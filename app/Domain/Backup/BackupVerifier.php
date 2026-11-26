<?php

namespace App\Domain\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Decides whether a backup file can be trusted. A backup is "verified" only when the file is there and
 * whole, the archive opens, the manifest inside matches the one recorded when it was made, the database
 * copy has the recorded checksum, opens (SQLite: integrity_check) and holds the recorded row counts, and
 * every uploaded file inside has the checksum the manifest records.
 */
final class BackupVerifier
{
    /**
     * @param  array<string, mixed>|null  $expected  the manifest recorded at creation; null checks the archive against itself
     * @return array{ok: bool, error: ?string, manifest: ?array}
     */
    public function verify(string $zipPath, ?array $expected = null): array
    {
        if (! is_file($zipPath) || filesize($zipPath) === 0) {
            return $this->failed('backup_file_missing');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true || $zip->locateName('manifest.json') === false) {
            return $this->failed('backup_archive_broken');
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (! is_array($manifest) || ! isset($manifest['database']['file'], $manifest['database']['sha256'], $manifest['counts'])
            || ! is_array($manifest['counts'])
            || ! in_array($manifest['database']['file'], [BackupArchive::DB_SQLITE, BackupArchive::DB_SQL], true)) {
            $zip->close();

            return $this->failed('backup_archive_broken');
        }
        if ($expected !== null && (
            $expected['counts'] !== $manifest['counts']
            || $expected['database']['sha256'] !== $manifest['database']['sha256']
            || ($expected['files'] ?? []) !== ($manifest['files'] ?? [])
        )) {
            $zip->close();

            return $this->failed('backup_manifest_mismatch');
        }

        $dbName = $manifest['database']['file'];
        $workDir = $this->tempDir();
        try {
            try {
                $extracted = @$zip->extractTo($workDir, $dbName); // a damaged entry raises a CRC warning
            } catch (\Throwable) {
                $extracted = false;
            }
            if (! $extracted) {
                return $this->failed('backup_archive_broken');
            }
            $dbFile = $workDir.DIRECTORY_SEPARATOR.$dbName;
            if (! is_file($dbFile) || hash_file('sha256', $dbFile) !== $manifest['database']['sha256']) {
                return $this->failed('backup_database_broken');
            }

            $counts = $dbName === BackupArchive::DB_SQLITE
                ? $this->sqliteCounts($dbFile, array_keys($manifest['counts']))
                : $this->dumpCounts($dbFile, array_keys($manifest['counts']));
            if ($counts === null) {
                return $this->failed('backup_database_broken');
            }
            $recorded = array_map('intval', $manifest['counts']);
            ksort($counts);
            ksort($recorded);
            if ($counts !== $recorded) {
                return $this->failed('backup_counts_differ');
            }
            if (! $this->uploadsMatch($zip, is_array($manifest['files'] ?? null) ? $manifest['files'] : [])) {
                return $this->failed('backup_uploads_broken');
            }
        } finally {
            $zip->close();
            File::deleteDirectory($workDir);
        }

        return ['ok' => true, 'error' => null, 'manifest' => $manifest];
    }

    /** @return array<string, int>|null null when the file is not a sound SQLite database */
    private function sqliteCounts(string $dbFile, array $tables): ?array
    {
        try {
            $pdo = new \PDO('sqlite:'.$dbFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                return null;
            }
            $counts = [];
            foreach ($tables as $table) {
                $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM "'.str_replace('"', '""', (string) $table).'"')->fetchColumn();
            }

            return $counts;
        } catch (\PDOException) {
            return null;
        }
    }

    /** Counts the INSERT lines per table in a SQL dump (one row per line, as BackupArchive writes it). */
    private function dumpCounts(string $dbFile, array $tables): ?array
    {
        $handle = fopen($dbFile, 'rb');
        if ($handle === false) {
            return null;
        }
        $counts = array_fill_keys($tables, 0);
        $sawHeader = false;
        while (($line = fgets($handle)) !== false) {
            if (! $sawHeader) {
                $sawHeader = Str::startsWith($line, '-- AUSHVAN backup');
                if (! $sawHeader) {
                    fclose($handle);

                    return null;
                }
            }
            if (Str::startsWith($line, 'INSERT INTO `') && preg_match('/^INSERT INTO `([^`]+)`/', $line, $m) && array_key_exists($m[1], $counts)) {
                $counts[$m[1]]++;
            }
        }
        fclose($handle);

        return $counts;
    }

    /** Every upload in the manifest is in the archive with the recorded checksum, and nothing else is under uploads/. */
    private function uploadsMatch(ZipArchive $zip, array $files): bool
    {
        $inArchive = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && str_starts_with($name, 'uploads/') && ! str_ends_with($name, '/')) {
                $inArchive++;
            }
        }
        if ($inArchive !== count($files)) {
            return false;
        }
        foreach ($files as $relative => $sha) {
            $stream = @$zip->getStream('uploads/'.$relative);
            if ($stream === false) {
                return false;
            }
            $context = hash_init('sha256');
            while (! feof($stream)) {
                $chunk = fread($stream, 1 << 16);
                if ($chunk === false) {
                    fclose($stream);

                    return false;
                }
                hash_update($context, $chunk);
            }
            fclose($stream);
            if (hash_final($context) !== $sha) {
                return false;
            }
        }

        return true;
    }

    public function tempDir(): string
    {
        $dir = rtrim(config('shop.backup.local_dir'), '/\\').DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.Str::lower((string) Str::ulid());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /** @return array{ok: bool, error: ?string, manifest: ?array} */
    private function failed(string $code): array
    {
        return ['ok' => false, 'error' => $code, 'manifest' => null];
    }
}
