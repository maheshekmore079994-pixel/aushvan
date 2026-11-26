<?php

namespace App\Domain\Backup;

use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Writes a backup archive: the database (a consistent SQLite copy, or a SQL dump on MySQL), the private
 * uploads folder and a manifest with row counts and checksums that the verifier checks against.
 *
 * Archive layout:  manifest.json · database.sqlite | database.sql · uploads/…
 */
final class BackupArchive
{
    public const FORMAT = 2;

    public const DB_SQLITE = 'database.sqlite';

    public const DB_SQL = 'database.sql';

    /** Tables whose row counts the manifest records and the verifier recounts after unpacking. */
    public const COUNTED_TABLES = [
        'users', 'products', 'stock_batches', 'inventory_movements', 'customers', 'customer_ledger_entries',
        'sales', 'sale_items', 'payments', 'sale_returns', 'audit_logs',
    ];

    /**
     * Rows of these tables are not restored: the schema comes from the running application, and the list of
     * backups describes the files on this computer, which a restore does not change.
     */
    public const SCHEMA_TABLES = ['migrations', 'backups'];

    /**
     * Short-lived or machine-bound rows: never worth carrying to another installation, and reviving them on a
     * restore does harm (old sessions, stale locks, spent idempotency keys). Left out of the archive and of a restore.
     */
    public const VOLATILE_TABLES = [
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens', 'idempotency_keys',
    ];

    /**
     * Makes the archive at $zipPath, working inside $workDir (which must exist and be empty).
     *
     * @return array<string, mixed> the manifest written into the archive
     */
    public function make(Store $store, string $workDir, string $zipPath): array
    {
        if (DB::transactionLevel() > 0) {
            // A copy taken inside someone else's transaction would not be a consistent snapshot (and SQLite refuses it).
            throw new RuntimeException('A backup cannot be taken inside a database transaction.');
        }
        $driver = DB::connection()->getDriverName();
        $dbFile = $workDir.DIRECTORY_SEPARATOR.($driver === 'sqlite' ? self::DB_SQLITE : self::DB_SQL);

        $counts = $this->dumpDatabase($driver, $dbFile);

        $manifest = [
            'format' => self::FORMAT,
            'app' => 'AUSHVAN',
            'version' => (string) config('shop.version'),
            'created_at' => now()->toIso8601String(),
            'shop' => ['id' => $store->id, 'name' => $store->name],
            'database' => [
                'driver' => $driver,
                'file' => basename($dbFile),
                'sha256' => hash_file('sha256', $dbFile),
                'size_bytes' => filesize($dbFile),
            ],
            'schema' => $this->schemaVersion(),
            'counts' => $counts,
            'uploads' => 0,
            'files' => [],
        ];

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create the backup archive at {$zipPath}.");
        }
        $zip->addFile($dbFile, basename($dbFile));
        $manifest['files'] = $this->addUploads($zip);
        $manifest['uploads'] = count($manifest['files']);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (! $zip->close()) {
            throw new RuntimeException('Could not finish writing the backup archive.');
        }

        return $manifest;
    }

    /** The name of the last migration that has run: two databases with the same name have the same tables. */
    public function schemaVersion(): string
    {
        return (string) DB::table('migrations')->orderByDesc('id')->value('migration');
    }

    /** Row counts of the tables the verifier checks, from the live database. */
    public function liveCounts(): array
    {
        $counts = [];
        foreach (self::COUNTED_TABLES as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * Copies the database into $dbFile and returns the row counts taken from the very same snapshot.
     *
     * @return array<string, int>
     */
    private function dumpDatabase(string $driver, string $dbFile): array
    {
        if ($driver === 'sqlite') {
            // VACUUM INTO writes a consistent, compacted copy while the application keeps running.
            DB::statement("VACUUM INTO '".str_replace("'", "''", str_replace('\\', '/', $dbFile))."'");
            $pdo = new \PDO('sqlite:'.$dbFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $tables = array_map(fn ($row) => $row->name, $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_OBJ));
            foreach (array_intersect(self::VOLATILE_TABLES, $tables) as $table) {
                $pdo->exec('DELETE FROM "'.$table.'"'); // the copy carries no sessions, locks or spent keys
            }
            $counts = [];
            foreach (self::COUNTED_TABLES as $table) {
                $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
            }
            $pdo = null;

            return $counts;
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException("Backups are not implemented for the {$driver} database driver.");
        }

        return $this->dumpMysql($dbFile);
    }

    /**
     * A plain SQL dump written by PHP (shared hosting often has no mysqldump): one statement per line, so
     * the restorer can read it line by line. Taken inside one REPEATABLE READ transaction for consistency.
     *
     * @return array<string, int>
     */
    private function dumpMysql(string $dbFile): array
    {
        $handle = fopen($dbFile, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Could not write {$dbFile}.");
        }
        $pdo = DB::connection()->getPdo();
        $tables = array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'));
        $tables = array_values(array_diff($tables, self::VOLATILE_TABLES));
        $counts = [];

        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            fwrite($handle, '-- AUSHVAN backup '.now()->toIso8601String()." (MySQL)\n");
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($tables as $table) {
                $create = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
                $createSql = $create['Create Table'] ?? array_values($create)[1];
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, str_replace("\n", ' ', $createSql).";\n");

                $rows = 0;
                $columns = null;
                $query = DB::table($table);
                $stream = in_array('id', Schema::getColumnListing($table), true) ? $query->lazyById(500) : $query->cursor();
                foreach ($stream as $row) {
                    $row = (array) $row;
                    $columns ??= '`'.implode('`, `', array_keys($row)).'`';
                    $values = array_map(fn ($value) => $this->sqlValue($pdo, $value), array_values($row));
                    fwrite($handle, "INSERT INTO `{$table}` ({$columns}) VALUES (".implode(', ', $values).");\n");
                    $rows++;
                }
                if (in_array($table, self::COUNTED_TABLES, true)) {
                    $counts[$table] = $rows;
                }
            }
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            DB::rollBack();
            fclose($handle);
        }

        // Same key order as the SQLite copy and liveCounts(), whatever order SHOW TABLES used.
        $ordered = [];
        foreach (self::COUNTED_TABLES as $table) {
            $ordered[$table] = $counts[$table] ?? 0;
        }

        return $ordered;
    }

    /** One SQL literal on one line (PDO::quote escapes the line breaks, so a row never spans lines). */
    private function sqlValue(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return json_encode($value); // shortest round-trip text, no precision loss
        }

        return $pdo->quote((string) $value);
    }

    /**
     * Adds every file under the private uploads disk as uploads/<relative path>.
     *
     * @return array<string, string> relative path => sha256, recorded in the manifest and checked by the verifier
     */
    private function addUploads(ZipArchive $zip): array
    {
        $root = config('filesystems.disks.local.root');
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || $file->getFilename() === '.gitignore') {
                continue;
            }
            $relative = Str::of($file->getPathname())->after($root)->ltrim('/\\')->replace('\\', '/')->value();
            $zip->addFile($file->getPathname(), 'uploads/'.$relative);
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
