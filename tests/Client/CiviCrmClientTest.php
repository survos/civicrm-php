<?php

declare(strict_types=1);

namespace Survos\Civicrm\Tests\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Survos\Civicrm\Client\CiviCrmClient;
use Survos\Civicrm\Exception\CiviCrmApiException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The wire format is the part that bites, so it is the part that is pinned down here.
 *
 * Two behaviours in particular fail silently rather than loudly, which is why they get tests
 * instead of comments: parameters must arrive as a JSON *string* in a `params` field, and an API
 * failure arrives inside an HTTP 200.
 */
#[CoversClass(CiviCrmClient::class)]
final class CiviCrmClientTest extends TestCase
{
    /** @var list<array{method:string, url:string, options:array<string,mixed>}> */
    private array $seen = [];

    /** @var list<int> milliseconds the client would have slept */
    private array $slept = [];

    public function testParamsAreSentAsAJsonStringInAParamsField(): void
    {
        $civi = $this->client([$this->ok([['id' => 1, 'display_name' => 'Ada']])]);

        $civi->call('Contact', 'get', ['where' => [['contact_type', '=', 'Individual']], 'limit' => 3]);

        self::assertCount(1, $this->seen);
        self::assertSame('POST', $this->seen[0]['method']);
        self::assertSame('https://example.org/civicrm/ajax/api4/Contact/get', $this->seen[0]['url']);

        parse_str($this->seen[0]['options']['body'], $body);
        self::assertArrayHasKey('params', $body, 'CiviCRM reads parameters from a `params` field.');
        self::assertIsString($body['params'], 'The params field holds JSON as a string, not a nested structure.');
        self::assertSame(
            ['where' => [['contact_type', '=', 'Individual']], 'limit' => 3],
            json_decode($body['params'], true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testTheApiKeyTravelsInAHeaderAndNeverInTheUrl(): void
    {
        $civi = $this->client([$this->ok([])]);

        $civi->call('Contact', 'get');

        self::assertContains('X-Civi-Auth: Bearer secret-key', $this->seen[0]['options']['headers']);
        self::assertStringNotContainsString('secret-key', $this->seen[0]['url']);
    }

    public function testAnErrorInsideAnHttpTwoHundredIsStillAnError(): void
    {
        // This is how CiviCRM actually reports a bad entity. Anything that checks only the
        // status code reads it as an empty result set and carries on.
        $civi = $this->client([
            new MockResponse(
                json_encode(['error_message' => 'Api FakeEntity fakeAction version 4 does not exist.']),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            ),
        ]);

        $this->expectException(CiviCrmApiException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $civi->call('FakeEntity', 'fakeAction');
    }

    public function testAllPagesUntilAShortPage(): void
    {
        $civi = $this->client([
            $this->ok(array_map(static fn (int $i): array => ['id' => $i], range(1, 3))),
            $this->ok(array_map(static fn (int $i): array => ['id' => $i], range(4, 5))),
        ]);

        $rows = iterator_to_array($civi->all('Contribution', [], pageSize: 3), false);

        self::assertCount(5, $rows);
        self::assertCount(2, $this->seen, 'A short page ends the run; no wasted third request.');

        parse_str($this->seen[1]['options']['body'], $second);
        $params = json_decode($second['params'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(3, $params['offset']);
        self::assertSame(3, $params['limit']);
    }

    public function testAllStopsCleanlyWhenTheFirstPageIsExactlyFullAndTheSecondIsEmpty(): void
    {
        $civi = $this->client([
            $this->ok([['id' => 1], ['id' => 2]]),
            $this->ok([]),
        ]);

        self::assertCount(2, iterator_to_array($civi->all('Contact', [], pageSize: 2), false));
    }

    public function testCountAsksForRowCountRatherThanRows(): void
    {
        $civi = $this->client([
            new MockResponse(
                json_encode(['count' => 742, 'values' => []]),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            ),
        ]);

        self::assertSame(742, $civi->count('Contact', ['limit' => 10, 'offset' => 40]));

        parse_str($this->seen[0]['options']['body'], $body);
        $params = json_decode($body['params'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['row_count'], $params['select']);
        self::assertArrayNotHasKey('limit', $params, 'A limit would cap the count at the limit.');
        self::assertArrayNotHasKey('offset', $params);
    }

    public function testFirstReturnsNullRatherThanAnEmptyArray(): void
    {
        $civi = $this->client([$this->ok([])]);

        self::assertNull($civi->first('Contact', 'get', ['where' => [['id', '=', 999999]]]));
    }

    public function testATrailingSlashOnTheBaseUrlDoesNotDoubleUp(): void
    {
        $civi = $this->client([$this->ok([])], 'https://example.org/');

        $civi->call('Contact', 'get');

        self::assertSame('https://example.org/civicrm/ajax/api4/Contact/get', $this->seen[0]['url']);
    }

    public function testATransportFailureBecomesACiviCrmException(): void
    {
        $civi = $this->client([new MockResponse('', ['http_code' => 502])]);

        $this->expectException(CiviCrmApiException::class);

        $civi->call('Contact', 'get');
    }

    public function testAReadIsRetriedAfterATransientFailure(): void
    {
        $civi = $this->client([
            new MockResponse('', ['http_code' => 503]),
            $this->ok([['id' => 1]]),
        ], maxAttempts: 3);

        self::assertCount(1, $civi->call('Contact', 'get'));
        self::assertCount(2, $this->seen);
        self::assertCount(1, $this->slept, 'One backoff, between the two attempts.');
    }

    public function testRetriesAreBoundedAndThenTheErrorSurfaces(): void
    {
        $civi = $this->client(array_fill(0, 5, new MockResponse('', ['http_code' => 429])), maxAttempts: 3);

        try {
            $civi->call('Contact', 'get');
            self::fail('Expected the error to surface once attempts run out.');
        } catch (CiviCrmApiException) {
            self::assertCount(3, $this->seen, 'Three attempts, not an unbounded loop.');
        }
    }

    public function testAWriteIsNeverRetried(): void
    {
        // The whole reason retry lives in the client rather than in an HTTP decorator: at the
        // HTTP layer every APIv4 call is a POST, and retrying this one creates two contacts.
        $civi = $this->client(array_fill(0, 5, new MockResponse('', ['http_code' => 503])), maxAttempts: 3);

        try {
            $civi->call('Contact', 'create', ['values' => ['first_name' => 'Ada']]);
            self::fail('Expected the error to surface.');
        } catch (CiviCrmApiException) {
            self::assertCount(1, $this->seen, 'A create must be attempted exactly once.');
            self::assertSame([], $this->slept);
        }
    }

    public function testAPermanentErrorIsNotRetried(): void
    {
        $civi = $this->client([
            new MockResponse(
                json_encode(['error_message' => 'Authentication failed']),
                ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']],
            ),
        ], maxAttempts: 3);

        try {
            $civi->call('Contact', 'get');
            self::fail('Expected the error to surface.');
        } catch (CiviCrmApiException) {
            // A bad API key fails identically three times; retrying only delays the message.
            self::assertCount(1, $this->seen);
        }
    }

    /** @param list<MockResponse> $responses */
    private function client(
        array $responses,
        string $baseUrl = 'https://example.org',
        int $maxAttempts = 1,
    ): CiviCrmClient {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $this->seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{"values":[]}', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        // maxAttempts defaults to 1 here so the wire-format tests measure exactly one request;
        // the retry tests opt in. The sleeper is recorded rather than performed -- a test suite
        // that actually backs off is a test suite nobody runs.
        return new CiviCrmClient(
            $http,
            $baseUrl,
            'secret-key',
            maxAttempts: $maxAttempts,
            sleeper: function (int $ms): void { $this->slept[] = $ms; },
        );
    }

    /** @param list<array<string,mixed>> $values */
    private function ok(array $values): MockResponse
    {
        return new MockResponse(
            json_encode(['count' => \count($values), 'values' => $values], \JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }
}
