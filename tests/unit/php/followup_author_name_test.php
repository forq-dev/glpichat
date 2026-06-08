<?php

declare(strict_types=1);

namespace Glpi\Search {
    if (!class_exists(__NAMESPACE__ . '\\SearchOption', false)) {
        final class SearchOption
        {
            /**
             * @return array<int, array<string, string>>
             */
            public static function getOptionsForItemtype(string $itemtype): array
            {
                return [];
            }
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/bootstrap/unit.php';

    // -----------------------------------------------------------------------
    // Stubs for classes referenced by core_event_feed.php
    // -----------------------------------------------------------------------

    if (!class_exists('Ticket', false)) {
        final class Ticket
        {
            public static function getTable(): string
            {
                return 'glpi_tickets';
            }

            public static function getStatus(int $status): string
            {
                return 'Status ' . $status;
            }

            public static function getPriorityName(int $priority): string
            {
                return 'Priority ' . $priority;
            }

            public static function getUrgencyName(int $urgency): string
            {
                return 'Urgency ' . $urgency;
            }

            public static function getImpactName(int $impact): string
            {
                return 'Impact ' . $impact;
            }

            public static function getTicketTypeName(int $type): string
            {
                return 'Type ' . $type;
            }
        }
    }

    if (!class_exists('RequestType', false)) {
        final class RequestType
        {
            public static function getTable(): string
            {
                return 'glpi_requesttypes';
            }
        }
    }

    if (!class_exists('Dropdown', false)) {
        final class Dropdown
        {
            public static function getDropdownName(string $table, int $id): string
            {
                return '';
            }
        }
    }

    if (!function_exists('glpichat_is_ticket_terminal_status')) {
        function glpichat_is_ticket_terminal_status(int $status): bool
        {
            return $status >= 5;
        }
    }

    // Minimal no-op stub DB so core_event_feed.php does not crash on $DB usage.
    // Must implement Countable because GLPI's CommonDBTM::getFromDB() calls count()
    // on the result object returned by $DB->request().
    final class FollowupAuthorNameTestResult implements IteratorAggregate, Countable
    {
        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function __construct(private array $rows = [])
        {
        }

        /** @return Traversable<int, array<string, mixed>> */
        public function getIterator(): Traversable
        {
            return new ArrayIterator($this->rows);
        }

        public function current(): array|false
        {
            return $this->rows[0] ?? false;
        }

        public function count(): int
        {
            return count($this->rows);
        }
    }

    final class FollowupAuthorNameTestDb
    {
        /** @param array<string, mixed> $query */
        public function request(array $query): FollowupAuthorNameTestResult
        {
            return new FollowupAuthorNameTestResult();
        }
    }

    $DB = new FollowupAuthorNameTestDb();

    require_once dirname(__DIR__, 3) . '/src/core_event_feed.php';

    // -----------------------------------------------------------------------
    // Test: users_id <= 0 always returns 'Sistema' — no lookup needed
    // -----------------------------------------------------------------------

    $result_zero = glpichat_followup_author_name(0);
    glpichat_unit_assert_same('Sistema', $result_zero, 'users_id=0 returns Sistema');
    glpichat_unit_pass('users_id=0 returns Sistema');

    $result_neg = glpichat_followup_author_name(-1);
    glpichat_unit_assert_same('Sistema', $result_neg, 'users_id=-1 returns Sistema');
    glpichat_unit_pass('users_id=-1 returns Sistema');

    $result_neg2 = glpichat_followup_author_name(-99);
    glpichat_unit_assert_same('Sistema', $result_neg2, 'Any negative users_id returns Sistema');
    glpichat_unit_pass('Any negative users_id returns Sistema');

    // -----------------------------------------------------------------------
    // Test: valid users_id returns a non-empty string (name or fallback)
    //
    // The real getUserName() is available in the container and will return
    // either a name (if the user exists in the DB) or an empty string.
    // The function under test must never return an empty string for a
    // positive users_id — it must fall back to "Usuario #<id>".
    // -----------------------------------------------------------------------

    // Use a users_id that almost certainly does not exist in the test DB (very high number)
    $nonexistent_id = 999999999;
    $result_fallback = glpichat_followup_author_name($nonexistent_id);
    glpichat_unit_assert_true(
        $result_fallback !== '',
        'Positive users_id with no DB match never returns empty string'
    );
    glpichat_unit_pass('Positive users_id with no DB match never returns empty string');

    glpichat_unit_assert_same(
        'Usuario #' . $nonexistent_id,
        $result_fallback,
        'Non-existent users_id falls back to "Usuario #<id>"'
    );
    glpichat_unit_pass('Non-existent users_id falls back to "Usuario #<id>"');

    // -----------------------------------------------------------------------
    // Test: static cache — calling the function twice for the same ID returns
    // the identical string value (cache hit does not alter the name).
    // -----------------------------------------------------------------------

    $first  = glpichat_followup_author_name($nonexistent_id);
    $second = glpichat_followup_author_name($nonexistent_id);
    glpichat_unit_assert_same($first, $second, 'Second call for same users_id returns identical cached value');
    glpichat_unit_pass('Second call for same users_id returns identical cached value');

    // -----------------------------------------------------------------------
    // Test: return value is always a non-empty string for any positive id
    // -----------------------------------------------------------------------

    foreach ([1, 2, 42, 100, 999999999] as $uid) {
        $name = glpichat_followup_author_name($uid);
        glpichat_unit_assert_true(
            is_string($name) && $name !== '',
            "users_id={$uid} always produces a non-empty string"
        );
        glpichat_unit_pass("users_id={$uid} always produces a non-empty string");
    }

    fwrite(STDOUT, "[PASS] glpichat_followup_author_name unit tests completed\n");
}
