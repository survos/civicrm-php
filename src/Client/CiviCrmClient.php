<?php

declare(strict_types=1);

namespace Survos\Civicrm\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\Civicrm\Contract\CiviCrmClientInterface;
use Survos\Civicrm\Exception\CiviCrmApiException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * APIv4 over Symfony HttpClient.
 *
 * The wire format is odd enough to be worth stating: CiviCRM takes its parameters as a single
 * JSON string in a `params` field, not as ordinary form fields or a JSON body. Getting that
 * wrong produces an empty result rather than an error, which is the least helpful failure mode
 * available, so it is encoded in exactly one place -- here.
 *
 * Authentication is `X-Civi-Auth: Bearer <key>` rather than the query-string form. A key in a
 * URL ends up in access logs, proxy logs and browser history; a key in a header does not.
 */
final class CiviCrmClient implements CiviCrmClientInterface
{
    /**
     * Actions that may be retried.
     *
     * Retry lives here rather than in a RetryableHttpClient decorator because only this layer
     * knows whether a retry is safe. APIv4 is POST-only, so at the HTTP level every call looks
     * equally unsafe -- and a decorator that retried a timed-out `Contact.create` would quietly
     * create the contact twice. An allowlist, not a denylist: a new write action added upstream
     * should default to not retrying.
     */
    private const RETRYABLE_ACTIONS = ['get', 'getFields', 'getActions', 'getLinks', 'checkAccess'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 200,
        /** Injectable so tests do not actually sleep. */
        private readonly ?\Closure $sleeper = null,
    ) {
    }

    public function call(string $entity, string $action, array $params = []): array
    {
        $envelope = $this->request($entity, $action, $params);

        $values = $envelope['values'] ?? [];

        return \is_array($values) ? $values : [];
    }

    public function first(string $entity, string $action, array $params = []): ?array
    {
        $params['limit'] ??= 1;
        $values = $this->call($entity, $action, $params);

        $first = reset($values);

        return \is_array($first) ? $first : null;
    }

    public function count(string $entity, array $params = []): int
    {
        // select=row_count asks CiviCRM to count without materialising the rows, which matters
        // once an entity has six figures of them.
        $params['select'] = ['row_count'];
        unset($params['limit'], $params['offset']);

        $envelope = $this->request($entity, 'get', $params);

        return (int) ($envelope['count'] ?? 0);
    }

    public function all(string $entity, array $params = [], int $pageSize = 500): \Generator
    {
        $offset = 0;

        do {
            $page = $params;
            $page['limit'] = $pageSize;
            $page['offset'] = $offset;

            $values = $this->call($entity, 'get', $page);
            foreach ($values as $row) {
                yield $row;
            }

            $offset += $pageSize;
            // A short page means the end. Asking for the total first would be a second query
            // and would still race with anything being written meanwhile.
        } while (\count($values) === $pageSize);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private function request(string $entity, string $action, array $params): array
    {
        $attempts = \in_array($action, self::RETRYABLE_ACTIONS, true) ? max(1, $this->maxAttempts) : 1;

        for ($attempt = 1;; ++$attempt) {
            try {
                return $this->attempt($entity, $action, $params);
            } catch (CiviCrmApiException $e) {
                if ($attempt >= $attempts || !$this->isTransient($e)) {
                    throw $e;
                }

                // Full jitter: without it, every worker that hit the same 429 retries in lockstep
                // and reproduces the burst that caused it.
                $delayMs = random_int(0, min(10_000, $this->baseDelayMs * 2 ** ($attempt - 1)));

                $this->logger->warning('CiviCRM APIv4 call failed, retrying', [
                    'entity' => $entity,
                    'action' => $action,
                    'attempt' => $attempt,
                    'delay_ms' => $delayMs,
                    'error' => $e->getMessage(),
                ]);

                ($this->sleeper ?? static fn (int $ms) => usleep($ms * 1000))($delayMs);
            }
        }
    }

    /**
     * Worth retrying, or not?
     *
     * Rate limiting and server-side faults are worth another go; "no such entity" and a rejected
     * API key will fail exactly the same way three times and only delay the error.
     */
    private function isTransient(CiviCrmApiException $e): bool
    {
        $status = $e->getCode();

        return CiviCrmApiException::TRANSPORT_FAILURE === $status
            || 429 === $status
            || ($status >= 500 && $status < 600);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private function attempt(string $entity, string $action, array $params): array
    {
        $url = sprintf(
            '%s/civicrm/ajax/api4/%s/%s',
            rtrim($this->baseUrl, '/'),
            rawurlencode($entity),
            rawurlencode($action),
        );

        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'X-Civi-Auth' => 'Bearer '.$this->apiKey,
                    // CiviCRM treats this as the marker of a legitimate API call and will
                    // refuse some routes without it.
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Accept' => 'application/json',
                ],
                'body' => ['params' => json_encode($params, \JSON_THROW_ON_ERROR)],
            ]);

            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new CiviCrmApiException(sprintf('%s.%s: transport failure: %s', $entity, $action, $e->getMessage()), CiviCrmApiException::TRANSPORT_FAILURE, $e);
        } catch (\JsonException $e) {
            throw new CiviCrmApiException(sprintf('%s.%s: parameters are not encodable', $entity, $action), CiviCrmApiException::NOT_ENCODABLE, $e);
        }

        /*
         * CiviCRM reports API failures inside a 200 as often as with a status code, so the body
         * is authoritative and is checked first. Trusting the status alone turns "Api FakeEntity
         * does not exist" into an empty result set.
         */
        if (isset($payload['error_message'])) {
            throw new CiviCrmApiException(sprintf('%s.%s: %s', $entity, $action, (string) $payload['error_message']), (int) ($payload['error_code'] ?? null ?: $status));
        }

        if ($status >= 400) {
            throw new CiviCrmApiException(sprintf('%s.%s: HTTP %d', $entity, $action, $status), $status);
        }

        $this->logger->debug('CiviCRM APIv4 call', [
            'entity' => $entity,
            'action' => $action,
            'count' => $payload['count'] ?? null,
        ]);

        return $payload;
    }
}
