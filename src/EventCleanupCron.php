<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat;

use CronTask;
use Glpi\DBAL\QueryFunction;

final class EventCleanupCron
{
    private const RETENTION_DAYS = 30;

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'CleanEvents' => ['description' => __('Delete GLPI Chat events older than 30 days.')],
            default => [],
        };
    }

    public static function cronCleanEvents(CronTask $task): int
    {
        $deleted = self::deleteExpiredEvents();
        $task->addVolume($deleted);
        $task->log(sprintf('Deleted %d GLPI Chat event records older than %d days.', $deleted, self::RETENTION_DAYS));

        return $deleted > 0 ? 1 : 0;
    }

    public static function deleteExpiredEvents(): int
    {
        global $DB;

        $DB->delete('glpi_plugin_glpichat_events', [
            'date_creation' => ['<', QueryFunction::dateSub(QueryFunction::now(), self::RETENTION_DAYS, 'DAY')],
        ]);

        return (int) $DB->affectedRows();
    }
}
