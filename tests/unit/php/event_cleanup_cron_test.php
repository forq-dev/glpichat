<?php

declare(strict_types=1);

// Stub glpichat_config_int before loading EventCleanupCron.
// EventCleanupCron calls glpichat_config_int (from functions.php) at runtime;
// the stub returns the provided default to keep the test self-contained.
if (!function_exists('glpichat_config_int')) {
    function glpichat_config_int(string $key, int $default): int
    {
        return $default;
    }
}

require dirname(__DIR__, 2) . '/bootstrap/unit.php';

// ── Mock CronTask ────────────────────────────────────────────────────────────
// Define before any autoload of the real CronTask to keep tests self-contained.

if (!class_exists('CronTask', false)) {
    final class CronTask
    {
        /** @var list<int|float> */
        public array $added_volumes = [];

        /** @var list<string> */
        public array $logged_messages = [];

        public function addVolume(int|float $volume): void
        {
            $this->added_volumes[] = $volume;
        }

        public function log(string $content): void
        {
            $this->logged_messages[] = $content;
        }
    }
}

// ── Mock DBmysqlIterator stand-in ─────────────────────────────────────────────
// Used to provide ->current() on request() results.

final class CleanupCronMockResult implements IteratorAggregate
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    /**
     * @return Traversable<int, array<string, mixed>>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    /**
     * @return array<string, mixed>|false
     */
    public function current(): array|false
    {
        return $this->rows[0] ?? false;
    }
}

// ── Mock DB ───────────────────────────────────────────────────────────────────

final class CleanupCronMockDB
{
    /** @var list<array<string, array<int, array<string, mixed>>>> */
    private array $request_queue = [];

    private int $affected_rows = 0;

    /** @var list<array<string, mixed>> */
    public array $deleted_calls = [];

    /** @var list<array<string, mixed>> */
    public array $inserted_calls = [];

    /**
     * Static method called by QueryFunction::getExpression() to quote column names.
     * Returns a minimal backtick-quoted form to keep QueryExpression generation working.
     */
    public static function quoteName(string $name): string
    {
        return '`' . $name . '`';
    }

    /**
     * @param list<array<int, array<string, mixed>>> $request_results
     *   FIFO queue: each element is the rows array to return for one request() call.
     */
    public function __construct(array $request_results = [], int $affected_rows = 0)
    {
        $this->request_queue = $request_results;
        $this->affected_rows = $affected_rows;
    }

    public function request(array $query): CleanupCronMockResult
    {
        $rows = array_shift($this->request_queue) ?? [];
        return new CleanupCronMockResult($rows);
    }

    public function delete(string $table, array $where): void
    {
        $this->deleted_calls[] = ['table' => $table, 'where' => $where];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): void
    {
        $this->inserted_calls[] = ['table' => $table, 'data' => $data];
    }

    public function affectedRows(): int
    {
        return $this->affected_rows;
    }
}

// The bootstrap already loaded the Composer autoloader. Register the plugin
// PSR-4 prefix so that GlpiPlugin\Glpichat\EventCleanupCron can be resolved
// without booting the full GLPI kernel.
/** @var \Composer\Autoload\ClassLoader $composer_loader */
$composer_loader = require dirname(__DIR__, 5) . '/vendor/autoload.php';
$composer_loader->addPsr4('GlpiPlugin\\Glpichat\\', dirname(__DIR__, 3) . '/src/');

use GlpiPlugin\Glpichat\EventCleanupCron;

// ── deleteExpiredMessages — no old messages ───────────────────────────────────
// Scenario 1: SELECT returns no affected rooms → 0 count, no event inserted.

$db_no_old = new CleanupCronMockDB(
    // First request(): SELECT of affected rooms → empty
    [[]],
    // affectedRows() after DELETE → 0
    0
);
$GLOBALS['DB'] = $db_no_old;

$deleted_count = EventCleanupCron::deleteExpiredMessages();

glpichat_unit_assert_same(0, $deleted_count, 'deleteExpiredMessages returns 0 when no old messages exist');
glpichat_unit_pass('deleteExpiredMessages returns 0 when no old messages exist');

glpichat_unit_assert_same(0, count($db_no_old->inserted_calls), 'deleteExpiredMessages inserts no messages_purged event when no old messages exist');
glpichat_unit_pass('deleteExpiredMessages inserts no messages_purged event when no old messages exist');

glpichat_unit_assert_same(1, count($db_no_old->deleted_calls), 'deleteExpiredMessages still executes the DELETE even with no affected rooms');
glpichat_unit_pass('deleteExpiredMessages still executes the DELETE even with no affected rooms');

glpichat_unit_assert_same('glpi_plugin_glpichat_messages', (string) $db_no_old->deleted_calls[0]['table'], 'deleteExpiredMessages targets the messages table');
glpichat_unit_pass('deleteExpiredMessages targets the messages table');

// ── deleteExpiredMessages — old messages in 1 room ────────────────────────────
// Scenario 2: SELECT returns 1 room → 1 event inserted with purged_before in Y-m-d, returns correct count.

$oldest_date = '2024-05-01 08:30:00';
$expected_purged_before = date('Y-m-d', strtotime($oldest_date)); // '2024-05-01'

$db_one_room = new CleanupCronMockDB(
    [
        // First request() — affected_rooms query: 1 row
        [
            [
                'rooms_id' => 5,
                'oldest'   => $oldest_date,
            ],
        ],
        // Second request() — room lookup to get tickets_id
        [
            [
                'tickets_id' => 42,
            ],
        ],
    ],
    // affectedRows() after DELETE
    7
);
$GLOBALS['DB'] = $db_one_room;

$deleted_count_room = EventCleanupCron::deleteExpiredMessages();

glpichat_unit_assert_same(7, $deleted_count_room, 'deleteExpiredMessages returns the affectedRows count after DELETE');
glpichat_unit_pass('deleteExpiredMessages returns the affectedRows count after DELETE');

glpichat_unit_assert_same(1, count($db_one_room->inserted_calls), 'deleteExpiredMessages inserts exactly 1 messages_purged event for 1 affected room');
glpichat_unit_pass('deleteExpiredMessages inserts exactly 1 messages_purged event for 1 affected room');

$insert_data = $db_one_room->inserted_calls[0];
glpichat_unit_assert_same('glpi_plugin_glpichat_events', (string) $insert_data['table'], 'deleteExpiredMessages inserts event into the events table');
glpichat_unit_pass('deleteExpiredMessages inserts event into the events table');

glpichat_unit_assert_same('messages_purged', (string) $insert_data['data']['event_type'], 'deleteExpiredMessages event has event_type messages_purged');
glpichat_unit_pass('deleteExpiredMessages event has event_type messages_purged');

glpichat_unit_assert_same(5, (int) $insert_data['data']['rooms_id'], 'deleteExpiredMessages event carries the correct rooms_id');
glpichat_unit_pass('deleteExpiredMessages event carries the correct rooms_id');

glpichat_unit_assert_same(42, (int) $insert_data['data']['tickets_id'], 'deleteExpiredMessages event carries the tickets_id from the room row');
glpichat_unit_pass('deleteExpiredMessages event carries the tickets_id from the room row');

glpichat_unit_assert_same('system', (string) $insert_data['data']['actor_type'], 'deleteExpiredMessages event has actor_type system');
glpichat_unit_pass('deleteExpiredMessages event has actor_type system');

$payload = json_decode((string) $insert_data['data']['payload_json'], true, 512, JSON_THROW_ON_ERROR);
glpichat_unit_assert_true(is_array($payload), 'deleteExpiredMessages event payload is valid JSON');
glpichat_unit_pass('deleteExpiredMessages event payload is valid JSON');

glpichat_unit_assert_same($expected_purged_before, (string) ($payload['purged_before'] ?? ''), 'deleteExpiredMessages event payload purged_before is in Y-m-d format');
glpichat_unit_pass('deleteExpiredMessages event payload purged_before is in Y-m-d format');

// ── deleteExpiredMessages — room lookup returns no row ────────────────────────
// Scenario: affected room has no matching room record → event skipped, DELETE still runs.

$db_missing_room = new CleanupCronMockDB(
    [
        // affected_rooms query: 1 row with a rooms_id
        [
            [
                'rooms_id' => 99,
                'oldest'   => '2023-01-01 00:00:00',
            ],
        ],
        // room lookup: empty result (room deleted or missing)
        [],
    ],
    3
);
$GLOBALS['DB'] = $db_missing_room;

$deleted_count_missing = EventCleanupCron::deleteExpiredMessages();

glpichat_unit_assert_same(3, $deleted_count_missing, 'deleteExpiredMessages returns affectedRows even when room lookup misses');
glpichat_unit_pass('deleteExpiredMessages returns affectedRows even when room lookup misses');

glpichat_unit_assert_same(0, count($db_missing_room->inserted_calls), 'deleteExpiredMessages skips event insert when room row is not found');
glpichat_unit_pass('deleteExpiredMessages skips event insert when room row is not found');

// ── deleteExpiredAuditlogs — no old logs ──────────────────────────────────────
// Scenario: affectedRows = 0, no event insertion expected.

$db_no_audit = new CleanupCronMockDB([], 0);
$GLOBALS['DB'] = $db_no_audit;

$audit_deleted_zero = EventCleanupCron::deleteExpiredAuditlogs();

glpichat_unit_assert_same(0, $audit_deleted_zero, 'deleteExpiredAuditlogs returns 0 when no old audit logs exist');
glpichat_unit_pass('deleteExpiredAuditlogs returns 0 when no old audit logs exist');

glpichat_unit_assert_same(1, count($db_no_audit->deleted_calls), 'deleteExpiredAuditlogs executes exactly one DELETE');
glpichat_unit_pass('deleteExpiredAuditlogs executes exactly one DELETE');

glpichat_unit_assert_same('glpi_plugin_glpichat_auditlogs', (string) $db_no_audit->deleted_calls[0]['table'], 'deleteExpiredAuditlogs targets the auditlogs table');
glpichat_unit_pass('deleteExpiredAuditlogs targets the auditlogs table');

glpichat_unit_assert_same(0, count($db_no_audit->inserted_calls), 'deleteExpiredAuditlogs inserts no event');
glpichat_unit_pass('deleteExpiredAuditlogs inserts no event');

// ── deleteExpiredAuditlogs — with old logs ────────────────────────────────────

$db_with_audit = new CleanupCronMockDB([], 12);
$GLOBALS['DB'] = $db_with_audit;

$audit_deleted_count = EventCleanupCron::deleteExpiredAuditlogs();

glpichat_unit_assert_same(12, $audit_deleted_count, 'deleteExpiredAuditlogs returns affectedRows when old logs exist');
glpichat_unit_pass('deleteExpiredAuditlogs returns affectedRows when old logs exist');

glpichat_unit_assert_same(0, count($db_with_audit->inserted_calls), 'deleteExpiredAuditlogs never inserts events regardless of count');
glpichat_unit_pass('deleteExpiredAuditlogs never inserts events regardless of count');

// ── cronCleanMessages — addVolume and log are called ─────────────────────────

$db_cron_msgs = new CleanupCronMockDB([[]], 4);
$GLOBALS['DB'] = $db_cron_msgs;

$task_msgs = new CronTask();
$cron_result_msgs = EventCleanupCron::cronCleanMessages($task_msgs);

glpichat_unit_assert_same(1, $cron_result_msgs, 'cronCleanMessages returns 1 when messages were deleted');
glpichat_unit_pass('cronCleanMessages returns 1 when messages were deleted');

glpichat_unit_assert_same(1, count($task_msgs->added_volumes), 'cronCleanMessages calls addVolume exactly once');
glpichat_unit_pass('cronCleanMessages calls addVolume exactly once');

glpichat_unit_assert_same(4, (int) $task_msgs->added_volumes[0], 'cronCleanMessages passes the deleted count to addVolume');
glpichat_unit_pass('cronCleanMessages passes the deleted count to addVolume');

glpichat_unit_assert_same(1, count($task_msgs->logged_messages), 'cronCleanMessages calls log exactly once');
glpichat_unit_pass('cronCleanMessages calls log exactly once');

glpichat_unit_assert_true(str_contains($task_msgs->logged_messages[0], '4'), 'cronCleanMessages log message mentions the deleted count');
glpichat_unit_pass('cronCleanMessages log message mentions the deleted count');

// cronCleanMessages returns 0 when nothing was deleted

$db_cron_msgs_zero = new CleanupCronMockDB([[]], 0);
$GLOBALS['DB'] = $db_cron_msgs_zero;

$task_msgs_zero = new CronTask();
$cron_result_msgs_zero = EventCleanupCron::cronCleanMessages($task_msgs_zero);

glpichat_unit_assert_same(0, $cron_result_msgs_zero, 'cronCleanMessages returns 0 when no messages were deleted');
glpichat_unit_pass('cronCleanMessages returns 0 when no messages were deleted');

// ── cronCleanAuditlogs — addVolume and log are called ─────────────────────────

$db_cron_audit = new CleanupCronMockDB([], 9);
$GLOBALS['DB'] = $db_cron_audit;

$task_audit = new CronTask();
$cron_result_audit = EventCleanupCron::cronCleanAuditlogs($task_audit);

glpichat_unit_assert_same(1, $cron_result_audit, 'cronCleanAuditlogs returns 1 when audit logs were deleted');
glpichat_unit_pass('cronCleanAuditlogs returns 1 when audit logs were deleted');

glpichat_unit_assert_same(1, count($task_audit->added_volumes), 'cronCleanAuditlogs calls addVolume exactly once');
glpichat_unit_pass('cronCleanAuditlogs calls addVolume exactly once');

glpichat_unit_assert_same(9, (int) $task_audit->added_volumes[0], 'cronCleanAuditlogs passes the deleted count to addVolume');
glpichat_unit_pass('cronCleanAuditlogs passes the deleted count to addVolume');

glpichat_unit_assert_same(1, count($task_audit->logged_messages), 'cronCleanAuditlogs calls log exactly once');
glpichat_unit_pass('cronCleanAuditlogs calls log exactly once');

glpichat_unit_assert_true(str_contains($task_audit->logged_messages[0], '9'), 'cronCleanAuditlogs log message mentions the deleted count');
glpichat_unit_pass('cronCleanAuditlogs log message mentions the deleted count');

// cronCleanAuditlogs returns 0 when nothing was deleted

$db_cron_audit_zero = new CleanupCronMockDB([], 0);
$GLOBALS['DB'] = $db_cron_audit_zero;

$task_audit_zero = new CronTask();
$cron_result_audit_zero = EventCleanupCron::cronCleanAuditlogs($task_audit_zero);

glpichat_unit_assert_same(0, $cron_result_audit_zero, 'cronCleanAuditlogs returns 0 when no audit logs were deleted');
glpichat_unit_pass('cronCleanAuditlogs returns 0 when no audit logs were deleted');

fwrite(STDOUT, "[PASS] EventCleanupCron unit tests completed\n");
