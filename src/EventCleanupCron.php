<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat;

use CronTask;
use Glpi\DBAL\QueryFunction;

use function glpichat_config_int;

final class EventCleanupCron
{
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'CleanEvents'    => ['description' => __('Delete GLPI Chat events older than configured retention days.')],
            'CleanMessages'  => ['description' => __('Delete GLPI Chat messages older than configured retention days.')],
            'CleanAuditlogs' => ['description' => __('Delete GLPI Chat audit logs older than configured retention days.')],
            default => [],
        };
    }

    public static function cronCleanEvents(CronTask $task): int
    {
        $days = glpichat_config_int('events_retention_days', 30);
        $deleted = self::deleteExpiredEvents($days);
        $task->addVolume($deleted);
        $task->log(sprintf('Deleted %d GLPI Chat event records older than %d days.', $deleted, $days));

        return $deleted > 0 ? 1 : 0;
    }

    public static function deleteExpiredEvents(?int $days = null): int
    {
        global $DB;

        $days = $days ?? glpichat_config_int('events_retention_days', 30);

        $DB->delete('glpi_plugin_glpichat_events', [
            'date_creation' => ['<', QueryFunction::dateSub(QueryFunction::now(), $days, 'DAY')],
        ]);

        return (int) $DB->affectedRows();
    }

    public static function cronCleanMessages(CronTask $task): int
    {
        $days = glpichat_config_int('messages_retention_days', 365);
        $deleted = self::deleteExpiredMessages($days);
        $task->addVolume($deleted);
        $task->log(sprintf('Deleted %d GLPI Chat message records older than %d days.', $deleted, $days));

        return $deleted > 0 ? 1 : 0;
    }

    public static function deleteExpiredMessages(?int $days = null): int
    {
        global $DB;

        $days = $days ?? glpichat_config_int('messages_retention_days', 365);
        $threshold = QueryFunction::dateSub(QueryFunction::now(), $days, 'DAY');

        $affected_rooms = $DB->request([
            'SELECT'  => [
                'rooms_id',
                QueryFunction::min('date_creation', 'oldest'),
            ],
            'FROM'    => 'glpi_plugin_glpichat_messages',
            'WHERE'   => [
                'date_creation' => ['<', $threshold],
            ],
            'GROUPBY' => 'rooms_id',
        ]);

        foreach ($affected_rooms as $room_row) {
            $rooms_id = (int) $room_row['rooms_id'];
            $oldest   = (string) $room_row['oldest'];

            $room = $DB->request([
                'SELECT' => ['tickets_id'],
                'FROM'   => 'glpi_plugin_glpichat_rooms',
                'WHERE'  => ['id' => $rooms_id],
                'LIMIT'  => 1,
            ])->current();

            if (!is_array($room)) {
                continue;
            }

            $tickets_id = (int) $room['tickets_id'];

            $DB->insert('glpi_plugin_glpichat_events', [
                'rooms_id'        => $rooms_id,
                'tickets_id'      => $tickets_id,
                'event_type'      => 'messages_purged',
                'actor_type'      => 'system',
                'users_id'        => 0,
                'participants_id' => 0,
                'payload_json'    => json_encode(['purged_before' => date('Y-m-d', strtotime($oldest))]),
                'date_creation'   => QueryFunction::now(),
            ]);
        }

        $DB->delete('glpi_plugin_glpichat_messages', [
            'date_creation' => ['<', $threshold],
        ]);

        return (int) $DB->affectedRows();
    }

    public static function cronCleanAuditlogs(CronTask $task): int
    {
        $days = glpichat_config_int('auditlogs_retention_days', 180);
        $deleted = self::deleteExpiredAuditlogs($days);
        $task->addVolume($deleted);
        $task->log(sprintf('Deleted %d GLPI Chat audit log records older than %d days.', $deleted, $days));

        return $deleted > 0 ? 1 : 0;
    }

    public static function deleteExpiredAuditlogs(?int $days = null): int
    {
        global $DB;

        $days = $days ?? glpichat_config_int('auditlogs_retention_days', 180);

        $DB->delete('glpi_plugin_glpichat_auditlogs', [
            'date_creation' => ['<', QueryFunction::dateSub(QueryFunction::now(), $days, 'DAY')],
        ]);

        return (int) $DB->affectedRows();
    }
}
