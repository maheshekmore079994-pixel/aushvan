<?php

namespace App\Domain\Backup;

/**
 * Where local backups live. A relative BACKUP_LOCAL_DIR (as on shared hosting: "../aushvan-backups") is taken
 * from the application folder, never from the process's working directory, which differs between a web
 * request (public/) and the cron (the application folder) and would otherwise split the backups in two.
 */
final class LocalDir
{
    public static function resolve(?string $configured, string $basePath, string $default): string
    {
        $value = trim((string) $configured);
        if ($value === '') {
            return $default;
        }
        if (preg_match('~^(/|\\\\|[A-Za-z]:[\\\\/])~', $value) === 1) {
            return $value; // absolute on Linux or Windows
        }

        return rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.$value;
    }
}
