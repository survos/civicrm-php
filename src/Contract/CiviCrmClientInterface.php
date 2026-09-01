<?php

declare(strict_types=1);

namespace Survos\Civicrm\Contract;

/**
 * CiviCRM APIv4, over HTTP.
 *
 * Deliberately the API and not the database. CiviCRM's schema is wide, versioned, and full of
 * things that look like plain columns and are not -- custom fields live in generated tables,
 * option values resolve through option groups, and a contact's "name" is assembled by rules a
 * SELECT cannot see. Every one of those is a place where reading the tables directly gets an
 * answer that is subtly wrong and stays wrong until somebody notices.
 *
 * The API also enforces the things a village depends on: ACLs, dedupe rules, the hooks that
 * extensions register, and the logging that says who changed what. A direct write skips all of
 * it silently.
 */
interface CiviCrmClientInterface
{
    /**
     * Call any APIv4 entity/action.
     *
     * @param array<string,mixed> $params
     *
     * @return array<int,array<string,mixed>> the `values` array from the response envelope
     */
    public function call(string $entity, string $action, array $params = []): array;

    /**
     * The first result, or null.
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>|null
     */
    public function first(string $entity, string $action, array $params = []): ?array;

    /**
     * How many records match, without fetching them.
     *
     * @param array<string,mixed> $params
     */
    public function count(string $entity, array $params = []): int;

    /**
     * Read every matching record, a page at a time.
     *
     * Returns a generator rather than an array because "every contact" is 700 rows for one
     * village and six figures for a federation, and the caller should not have to know which
     * it is before asking.
     *
     * @param array<string,mixed> $params
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function all(string $entity, array $params = [], int $pageSize = 500): \Generator;
}
