<?php

namespace App\Domain\Backup;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Puts the data of a verified backup into the running database, table by table, in one transaction that is
 * rolled back unless the restored row counts match the manifest. The schema is never restored: the backup
 * must come from the same application version (same last migration), otherwise the restore is refused and
 * the owner updates or downgrades first. Uploaded files in the archive are put back; files that are not in
 * the archive are kept.
 */
final class BackupRestorer
{
    public function __construct(private BackupVerifier $verifier, private BackupArchive $archive) {}

    /**
     * @return array{tables: int, counts: array<string, int>, uploads: int}
     *
     * @throws BusinessRuleException when the archive is not sound or does not fit this application
     */
    public function restore(string $zipPath): array
    {
        $manifest = $this->check($zipPath);
        $driver = DB::connection()->getDriverName();

        $workDir = $this->verifier->tempDir();
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::RDONLY);
        try {
            if (! @$zip->extractTo($workDir)) {
                throw new BusinessRuleException('backup_archive_broken', 'The backup file cannot be unpacked.');
            }
            $dbFile = $workDir.DIRECTORY_SEPARATOR.$manifest['database']['file'];
            $expected = array_map('intval', $manifest['counts']);

            $tables = $driver === 'sqlite' ? $this->restoreSqlite($dbFile, $expected) : $this->restoreMysql($dbFile, $expected);
            $uploads = $this->restoreUploads($workDir.DIRECTORY_SEPARATOR.'uploads');
        } finally {
            $zip->close();
            File::deleteDirectory($workDir);
        }

        return ['tables' => $tables, 'counts' => $expected, 'uploads' => $uploads];
    }

    /**
     * Verifies the archive and that it fits this installation, without touching anything; returns its manifest.
     *
     * @return array<string, mixed>
     *
     * @throws BusinessRuleException
     */
    public function check(string $zipPath): array
    {
        $check = $this->verifier->verify($zipPath);
        if (! $check['ok']) {
            throw new BusinessRuleException($check['error'], 'The backup file cannot be used.');
        }
        $manifest = $check['manifest'];
        if (($manifest['schema'] ?? null) !== $this->archive->schemaVersion()) {
            throw new BusinessRuleException('backup_schema_differs', 'The backup was made with another version of the application.');
        }
        if (($manifest['database']['driver'] ?? null) !== DB::connection()->getDriverName()) {
            throw new BusinessRuleException('backup_driver_differs', 'The backup comes from another kind of database.');
        }

        return $manifest;
    }

    /** Tables whose rows a restore replaces. */
    private function restorableTables(array $all): array
    {
        return array_values(array_diff($all, BackupArchive::SCHEMA_TABLES, BackupArchive::VOLATILE_TABLES));
    }

    /** Throws (rolling the transaction back) unless the live counts now equal the manifest's. */
    private function assertCounts(array $expected): void
    {
        $live = $this->archive->liveCounts();
        ksort($live);
        ksort($expected);
        if ($live !== $expected) {
            throw new BusinessRuleException('backup_counts_differ', 'The restored data does not match the backup.');
        }
    }

    /** Attaches the backup database and copies every table across, by named columns, inside one transaction. */
    private function restoreSqlite(string $dbFile, array $expected): int
    {
        $tables = array_map(fn ($row) => $row->name, DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"));
        $tables = $this->restorableTables($tables);

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement("ATTACH DATABASE '".str_replace("'", "''", str_replace('\\', '/', $dbFile))."' AS backup_src");
        try {
            DB::transaction(function () use ($tables, $expected) {
                foreach ($tables as $table) {
                    $quoted = '"'.str_replace('"', '""', $table).'"';
                    $columns = implode(', ', array_map(fn ($c) => '"'.str_replace('"', '""', $c->name).'"', DB::select("PRAGMA main.table_info({$quoted})")));
                    DB::statement("DELETE FROM main.{$quoted}");
                    DB::statement("INSERT INTO main.{$quoted} ({$columns}) SELECT {$columns} FROM backup_src.{$quoted}");
                }
                $this->assertCounts($expected);
            });
        } finally {
            DB::statement('DETACH DATABASE backup_src');
            DB::statement('PRAGMA foreign_keys = ON');
        }

        return count($tables);
    }

    /** Empties every table and replays the INSERT lines of the dump inside one transaction. */
    private function restoreMysql(string $dbFile, array $expected): int
    {
        $tables = array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'));
        $tables = $this->restorableTables($tables);
        $allowed = array_fill_keys($tables, true);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($tables, $dbFile, $allowed, $expected) {
                foreach ($tables as $table) {
                    DB::statement("DELETE FROM `{$table}`"); // not TRUNCATE: that would commit the transaction
                }
                $handle = fopen($dbFile, 'rb');
                try {
                    while (($line = fgets($handle)) !== false) {
                        if (! Str::startsWith($line, 'INSERT INTO `')) {
                            continue;
                        }
                        preg_match('/^INSERT INTO `([^`]+)`/', $line, $m);
                        if (! isset($allowed[$m[1]])) {
                            continue;
                        }
                        DB::unprepared(rtrim($line));
                    }
                } finally {
                    fclose($handle);
                }
                $this->assertCounts($expected);
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return count($tables);
    }

    /** Puts the archive's uploads back (files not in the archive are kept); returns the number of files written. */
    private function restoreUploads(string $from): int
    {
        $root = config('filesystems.disks.local.root');
        if (! is_dir($from)) {
            return 0;
        }
        File::ensureDirectoryExists($root);
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($from)), '/\\');
            $target = $root.DIRECTORY_SEPARATOR.$relative;
            File::ensureDirectoryExists(dirname($target));
            File::copy($file->getPathname(), $target);
            $count++;
        }

        return $count;
    }
}
