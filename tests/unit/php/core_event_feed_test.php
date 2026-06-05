<?php

declare(strict_types=1);

namespace Glpi\Search {
    final class SearchOption
    {
        /**
         * @return array<int, array<string, string>>
         */
        public static function getOptionsForItemtype(string $itemtype): array
        {
            return [
                501 => [
                    'table' => \Ticket::getTable(),
                    'field' => 'status',
                ],
            ];
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/bootstrap/unit.php';

    if (!class_exists('Ticket', false)) {
        final class Ticket
        {
            public static function getTable(): string
            {
                return 'glpi_tickets';
            }

            public static function getStatus(int $status): string
            {
                return match ($status) {
                    1 => 'Pendente',
                    2 => 'Em atendimento',
                    default => 'Status ' . $status,
                };
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
                return match ($table) {
                    'glpi_requesttypes' => 'Portal',
                    'glpi_itilcategories' => 'Categoria ' . $id,
                    default => '',
                };
            }
        }
    }

    if (!function_exists('getUserName')) {
        function getUserName(int $users_id): string
        {
            return 'Tecnico #' . $users_id;
        }
    }

    if (!function_exists('glpichat_is_ticket_terminal_status')) {
        function glpichat_is_ticket_terminal_status(int $status): bool
        {
            return $status >= 5;
        }
    }

    if (!function_exists('glpichat_room_by_id')) {
        /**
         * @return array<string, int|string>
         */
        function glpichat_room_by_id(int $rooms_id): array
        {
            return [
                'id' => $rooms_id,
                'tickets_id' => 99,
                'room_type' => GLPICHAT_ROOM_PUBLIC,
            ];
        }
    }

    final class CoreEventFeedTestResult implements IteratorAggregate
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

        /**
         * @return array<string, mixed>|false
         */
        public function current(): array|false
        {
            return $this->rows[0] ?? false;
        }
    }

    final class CoreEventFeedTestDb
    {
        /**
         * @param array<string, array<int, array<string, mixed>>> $fixtures
         */
        public function __construct(private array $fixtures)
        {
        }

        public function request(array $query): CoreEventFeedTestResult
        {
            $from = (string) ($query['FROM'] ?? '');
            $rows = $this->fixtures[$from] ?? [];
            $where = $query['WHERE'] ?? [];

            $filtered = array_values(array_filter(
                $rows,
                static function (array $row) use ($where, $from): bool {
                    foreach ($where as $key => $value) {
                        if (is_int($key)) {
                            $field = array_key_first($value);
                            if (!is_string($field)) {
                                continue;
                            }
                            $condition = $value[$field];
                            if (is_array($condition) && ($condition[0] ?? null) === '>=') {
                                if ((string) ($row[$field] ?? '') < (string) ($condition[1] ?? '')) {
                                    return false;
                                }
                            } elseif ($field === 'requesttypes_id' && is_array($condition) && ($condition[0] ?? null) === '<>') {
                                if ((int) ($row['requesttypes_id'] ?? 0) === (int) ($condition[1] ?? 0)) {
                                    return false;
                                }
                            }
                            continue;
                        }

                        if ($key === 'event_type' && is_array($value)) {
                            if (!in_array((string) ($row['event_type'] ?? ''), $value, true)) {
                                return false;
                            }
                            continue;
                        }

                        if ($key === 'id_search_option' && is_array($value)) {
                            if (!in_array((int) ($row['id_search_option'] ?? 0), $value, true)) {
                                return false;
                            }
                            continue;
                        }

                        if (($row[$key] ?? null) !== $value) {
                            return false;
                        }
                    }

                    return true;
                }
            ));

            $order = $query['ORDER'] ?? [];
            if ($from === 'glpi_itilfollowups') {
                usort($filtered, static fn(array $a, array $b): int => strcmp((string) $a['date_creation'], (string) $b['date_creation']) ?: ((int) $a['id'] <=> (int) $b['id']));
            } elseif ($from === 'glpi_logs') {
                usort($filtered, static fn(array $a, array $b): int => strcmp((string) $a['date_mod'], (string) $b['date_mod']) ?: ((int) $a['id'] <=> (int) $b['id']));
            } elseif ($from === 'glpi_plugin_glpichat_events') {
                usort($filtered, static fn(array $a, array $b): int => strcmp((string) $a['date_creation'], (string) $b['date_creation']) ?: ((int) $a['id'] <=> (int) $b['id']));
            }

            if (($order[0] ?? '') === 'date_creation DESC' || ($order[0] ?? '') === 'date_mod DESC') {
                $filtered = array_reverse($filtered);
            }

            $limit = (int) ($query['LIMIT'] ?? count($filtered));
            if ($limit > 0) {
                $filtered = array_slice($filtered, 0, $limit);
            }

            return new CoreEventFeedTestResult($filtered);
        }
    }

    require_once dirname(__DIR__, 3) . '/src/core_event_feed.php';

    $DB = new CoreEventFeedTestDb([
        'glpi_requesttypes' => [
            ['id' => 5, 'name' => 'GLPI Chat'],
        ],
        'glpi_itilfollowups' => [
            [
                'id' => 10,
                'itemtype' => Ticket::class,
                'items_id' => 99,
                'requesttypes_id' => 9,
                'users_id' => 0,
                'is_private' => 0,
                'date_creation' => '2026-06-04 10:00:00',
            ],
        ],
        'glpi_logs' => [
            [
                'id' => 30,
                'itemtype' => Ticket::class,
                'items_id' => 99,
                'id_search_option' => 501,
                'old_value' => 1,
                'new_value' => 2,
                'date_mod' => '2026-06-04 10:03:00',
            ],
        ],
        'glpi_plugin_glpichat_events' => [
            [
                'id' => 20,
                'rooms_id' => 7,
                'tickets_id' => 99,
                'event_type' => 'guest_joined',
                'actor_type' => GLPICHAT_ACTOR_GUEST,
                'users_id' => 0,
                'participants_id' => 8,
                'payload_json' => json_encode(['guest_name' => 'Alice'], JSON_THROW_ON_ERROR),
                'date_creation' => '2026-06-04 10:05:00',
            ],
            [
                'id' => 22,
                'rooms_id' => 7,
                'tickets_id' => 99,
                'event_type' => 'user_joined',
                'actor_type' => GLPICHAT_ACTOR_USER,
                'users_id' => 4,
                'participants_id' => 9,
                'payload_json' => json_encode(['user_name' => 'Tecnico #4'], JSON_THROW_ON_ERROR),
                'date_creation' => '2026-06-04 10:07:00',
            ],
            [
                'id' => 21,
                'rooms_id' => 7,
                'tickets_id' => 99,
                'event_type' => 'ticket_status_changed',
                'actor_type' => 'system',
                'users_id' => 0,
                'participants_id' => 0,
                'payload_json' => json_encode(['ignored' => true], JSON_THROW_ON_ERROR),
                'date_creation' => '2026-06-04 10:06:00',
            ],
        ],
    ]);

    $events = glpichat_events(7, '');
    glpichat_unit_assert_same(4, count($events), 'Merged event feed keeps only core facts plus guest and user lifecycle plugin events');
    glpichat_unit_pass('Merged event feed keeps only core facts plus guest and user lifecycle plugin events');

    glpichat_unit_assert_same_array(
        [
            '2026-06-04 10:00:00|F|000000000010',
            '2026-06-04 10:03:00|L|000000000030',
            '2026-06-04 10:05:00|E|000000000020',
            '2026-06-04 10:07:00|E|000000000022',
        ],
        array_map(static fn(array $event): string => (string) $event['id'], $events),
        'Merged event feed orders core and plugin events with a stable textual cursor'
    );
    glpichat_unit_pass('Merged event feed orders core and plugin events with a stable textual cursor');

    glpichat_unit_assert_same(
        'Alice',
        (string) ($events[2]['payload']['guest_name'] ?? ''),
        'Merged event feed preserves guest payload from plugin events table'
    );
    glpichat_unit_pass('Merged event feed preserves guest payload from plugin events table');

    $after_log_events = glpichat_events(7, '2026-06-04 10:03:00|L|000000000030');
    glpichat_unit_assert_same(2, count($after_log_events), 'Merged event feed respects after_cursor across core and plugin sources');
    glpichat_unit_pass('Merged event feed respects after_cursor across core and plugin sources');

    glpichat_unit_assert_same(
        'guest_joined',
        (string) ($after_log_events[0]['event_type'] ?? ''),
        'Merged event feed returns guest lifecycle event after the last core cursor'
    );
    glpichat_unit_pass('Merged event feed returns guest lifecycle event after the last core cursor');

    glpichat_unit_assert_same(
        'user_joined',
        (string) ($after_log_events[1]['event_type'] ?? ''),
        'Merged event feed also returns logged user lifecycle events after the last core cursor'
    );
    glpichat_unit_pass('Merged event feed also returns logged user lifecycle events after the last core cursor');

    glpichat_unit_assert_same(
        '2026-06-04 10:07:00|E|000000000022',
        glpichat_room_last_event_id(7),
        'Room last event id reflects the newest merged plugin lifecycle event instead of core-only events'
    );
    glpichat_unit_pass('Room last event id reflects the newest merged plugin lifecycle event instead of core-only events');
}
