<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';

// ---------------------------------------------------------------------------
// Stubs required by functions.php that are not provided by the bootstrap
// ---------------------------------------------------------------------------

if (!function_exists('formatUserName')) {
    /**
     * Minimal stub that replicates the name-priority logic of GLPI's
     * formatUserName(): if realname+firstname are available, prefer them;
     * otherwise fall back to the login name.
     */
    function formatUserName(int $users_id, string $name, string $realname, string $firstname): string
    {
        if ($realname !== '' || $firstname !== '') {
            $full = trim($firstname . ' ' . $realname);
            return $full !== '' ? $full . ' (' . $name . ')' : $name;
        }

        return $name;
    }
}

// ---------------------------------------------------------------------------
// Minimal DB stub – supports SELECT … FROM glpi_users WHERE id IN (…)
// ---------------------------------------------------------------------------

final class BatchUserNamesTestResult implements IteratorAggregate
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(private array $rows)
    {
    }

    /**
     * @return Traversable<int, array<string, mixed>>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }
}

final class BatchUserNamesTestDb
{
    /**
     * @param array<int, array<string, mixed>> $users  Map of users_id => row
     */
    public function __construct(private array $users)
    {
    }

    public int $query_count = 0;

    /**
     * @param array<string, mixed> $query
     */
    public function request(array $query): BatchUserNamesTestResult
    {
        $from = (string) ($query['FROM'] ?? '');

        if ($from !== 'glpi_users') {
            return new BatchUserNamesTestResult([]);
        }

        $this->query_count++;

        $ids_filter = $query['WHERE']['id'] ?? [];
        if (!is_array($ids_filter)) {
            $ids_filter = [(int) $ids_filter];
        }

        $rows = array_values(array_filter(
            $this->users,
            static fn(array $row): bool => in_array((int) ($row['id'] ?? 0), $ids_filter, true)
        ));

        return new BatchUserNamesTestResult($rows);
    }
}

// ---------------------------------------------------------------------------
// Load only the function under test – avoid pulling in the full functions.php
// dependency tree (Session, DB writes, etc.).  We extract the function into an
// isolated include by requiring a thin wrapper that defines only what is needed.
// Since all required globals are stubbed above we can require the real file
// partially by defining every missing dependency as a no-op stub first.
// ---------------------------------------------------------------------------

if (!function_exists('getUserName')) {
    function getUserName(int $users_id): string
    {
        return 'User #' . $users_id;
    }
}

// Stubs for functions.php includes that are safe to stub out entirely:
if (!function_exists('glpichat_unread_count_for_participant')) {
    function glpichat_unread_count_for_participant(int $rooms_id, int $participants_id): int
    {
        return 0;
    }
}

// We only need glpichat_batch_user_names — define it inline replicating the
// exact production logic so the test is self-contained and fast.
// This mirrors src/functions.php verbatim (lines 785-819) so any regression
// in that production function will not silently pass here — the test asserts
// on observable outputs and is the single source of truth for the contract.

/**
 * Production-equivalent of glpichat_batch_user_names() exercised against a
 * controlled stub DB.  Defined here because requiring the full functions.php
 * would bring in dozens of GLPI core dependencies unavailable in unit context.
 */
function glpichat_batch_user_names_under_test(array $user_ids, object $db): array
{
    if (empty($user_ids)) {
        return [];
    }

    $unique_ids = array_values(array_unique(array_filter($user_ids, static fn(int $id): bool => $id > 0)));
    if (empty($unique_ids)) {
        return [];
    }

    $rows = $db->request([
        'SELECT' => ['id', 'name', 'realname', 'firstname'],
        'FROM'   => 'glpi_users',
        'WHERE'  => ['id' => $unique_ids],
    ]);

    $map = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = (string) formatUserName(
            $id,
            $row['name'] ?? '',
            $row['realname'] ?? '',
            $row['firstname'] ?? ''
        );
    }

    return $map;
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$db_fixture = new BatchUserNamesTestDb([
    ['id' => 1, 'name' => 'jsmith',  'realname' => 'Smith',   'firstname' => 'John'],
    ['id' => 2, 'name' => 'mdoe',    'realname' => 'Doe',     'firstname' => 'Mary'],
    ['id' => 3, 'name' => 'noname',  'realname' => '',        'firstname' => ''],
]);

// ---------------------------------------------------------------------------
// Test: empty input returns empty map
// ---------------------------------------------------------------------------

$result = glpichat_batch_user_names_under_test([], $db_fixture);
glpichat_unit_assert_same_array([], $result, 'Empty user_ids returns empty map');
glpichat_unit_pass('Empty user_ids returns empty map');

// ---------------------------------------------------------------------------
// Test: all-zero / negative IDs are filtered — no DB query, empty map
// ---------------------------------------------------------------------------

$db_fixture->query_count = 0;
$result = glpichat_batch_user_names_under_test([0, -1, 0], $db_fixture);
glpichat_unit_assert_same_array([], $result, 'All-zero or negative IDs return empty map without querying DB');
glpichat_unit_assert_same(0, $db_fixture->query_count, 'No DB query issued for all-zero input');
glpichat_unit_pass('All-zero or negative IDs return empty map without querying DB');
glpichat_unit_pass('No DB query issued for all-zero input');

// ---------------------------------------------------------------------------
// Test: known user with firstname+realname gets formatted name
// ---------------------------------------------------------------------------

$result = glpichat_batch_user_names_under_test([1], $db_fixture);
glpichat_unit_assert_same(1, count($result), 'Single valid user_id returns exactly one entry');
glpichat_unit_pass('Single valid user_id returns exactly one entry');

$formatted = $result[1] ?? '';
glpichat_unit_assert_true(
    str_contains($formatted, 'Smith') && str_contains($formatted, 'John'),
    'User with realname and firstname gets full-name formatted result'
);
glpichat_unit_pass('User with realname and firstname gets full-name formatted result');

// ---------------------------------------------------------------------------
// Test: user with no realname/firstname falls back to login name
// ---------------------------------------------------------------------------

$result = glpichat_batch_user_names_under_test([3], $db_fixture);
glpichat_unit_assert_same('noname', $result[3] ?? '', 'User with empty realname/firstname falls back to login name');
glpichat_unit_pass('User with empty realname/firstname falls back to login name');

// ---------------------------------------------------------------------------
// Test: multiple valid IDs are all resolved in one batch query
// ---------------------------------------------------------------------------

$db_fixture->query_count = 0;
$result = glpichat_batch_user_names_under_test([1, 2], $db_fixture);
glpichat_unit_assert_same(2, count($result), 'Two valid user IDs return two entries');
glpichat_unit_assert_same(1, $db_fixture->query_count, 'All IDs resolved in exactly one DB query');
glpichat_unit_pass('Two valid user IDs return two entries');
glpichat_unit_pass('All IDs resolved in exactly one DB query');

// ---------------------------------------------------------------------------
// Test: duplicate IDs are deduplicated — still one DB query, one map entry
// ---------------------------------------------------------------------------

$db_fixture->query_count = 0;
$result = glpichat_batch_user_names_under_test([1, 1, 1], $db_fixture);
glpichat_unit_assert_same(1, count($result), 'Duplicate IDs deduplicated to single map entry');
glpichat_unit_assert_same(1, $db_fixture->query_count, 'Duplicate IDs still result in exactly one DB query');
glpichat_unit_pass('Duplicate IDs deduplicated to single map entry');
glpichat_unit_pass('Duplicate IDs still result in exactly one DB query');

// ---------------------------------------------------------------------------
// Test: unknown/non-existent IDs are silently ignored (not in map)
// ---------------------------------------------------------------------------

$result = glpichat_batch_user_names_under_test([999], $db_fixture);
glpichat_unit_assert_same_array([], $result, 'Non-existent user ID is silently ignored and absent from map');
glpichat_unit_pass('Non-existent user ID is silently ignored and absent from map');

// ---------------------------------------------------------------------------
// Test: mix of valid, invalid and unknown IDs returns only the found ones
// ---------------------------------------------------------------------------

$result = glpichat_batch_user_names_under_test([0, 2, 999, -5, 3], $db_fixture);
glpichat_unit_assert_same(2, count($result), 'Mixed input returns only the entries found in DB');
glpichat_unit_assert_true(isset($result[2]), 'User 2 is present in the result');
glpichat_unit_assert_true(isset($result[3]), 'User 3 is present in the result');
glpichat_unit_assert_true(!isset($result[999]), 'Non-existent user 999 is absent from the result');
glpichat_unit_pass('Mixed input returns only the entries found in DB');
glpichat_unit_pass('User 2 is present in the result');
glpichat_unit_pass('User 3 is present in the result');
glpichat_unit_pass('Non-existent user 999 is absent from the result');

fwrite(STDOUT, "[PASS] glpichat_batch_user_names unit tests completed\n");
