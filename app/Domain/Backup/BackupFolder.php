<?php

namespace App\Domain\Backup;

use App\Models\Backup;
use App\Models\Store;

/**
 * The shop's backup folder (a USB stick or a second disk) and what the owner should be told about backups:
 * whether one is due, whether one should be taken automatically, which drives are plugged in.
 */
final class BackupFolder
{
    public const SETTING = 'backup_folder';

    /** An automatic backup that did not reach the folder is not retried for this long. */
    public const AUTO_RETRY_MINUTES = 60;

    public function path(Store $store): ?string
    {
        $path = $store->setting(self::SETTING);

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** The folder exists and a file can be written into it. */
    public function usable(?string $path): bool
    {
        return $path !== null && is_dir($path) && is_writable($path);
    }

    /** A typed path made canonical: a bare drive letter becomes its root, other trailing separators go. */
    public function normalise(string $path): string
    {
        $path = trim($path);
        if (preg_match('/^[A-Za-z]:[\\\\\/]?$/', $path) === 1) {
            return strtoupper($path[0]).':\\';
        }

        return rtrim($path, '/\\') ?: $path;
    }

    /**
     * Validation code for a folder path, or null when it can be used (it is created when its parent exists).
     * On success the folder's real path is written back to $path, so what is saved is what the disk resolves.
     */
    public function problem(string &$path): ?string
    {
        $isAbsolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\');
        if (! $isAbsolute) {
            return 'backup_folder_not_absolute';
        }
        // Decided on real paths, so "..", junctions, symlinks and 8.3 short names cannot point back into the
        // application — and decided before anything is created, so a refused path leaves no folder behind.
        $existing = is_dir($path) ? $path : dirname($path);
        $realExisting = is_dir($existing) ? realpath($existing) : false;
        if ($realExisting === false) {
            return 'backup_folder_unreachable';
        }
        foreach ($this->forbiddenRoots() as $inside) {
            if ($this->isWithin($realExisting, $inside)) {
                return 'backup_folder_inside_app';
            }
        }
        if (! is_dir($path) && ! @mkdir($path, 0775, true)) {
            return 'backup_folder_unreachable';
        }
        $real = realpath($path);
        if ($real === false || ! is_dir($real) || ! is_writable($real)) {
            return 'backup_folder_unreachable';
        }
        $path = $real;

        return null;
    }

    /** @return list<string> real paths a backup folder may never be inside: the program, its storage, the web root */
    private function forbiddenRoots(): array
    {
        $roots = [base_path(), storage_path(), public_path()];
        if (! empty($_SERVER['DOCUMENT_ROOT'])) {
            $roots[] = (string) $_SERVER['DOCUMENT_ROOT'];
        }
        $real = [];
        foreach ($roots as $root) {
            $resolved = realpath($root);
            if ($resolved !== false) {
                $real[] = $resolved;
            }
        }

        return $real;
    }

    private function isWithin(string $path, string $root): bool
    {
        $norm = fn (string $p) => rtrim(str_replace('\\', '/', $p), '/').'/';
        $a = $norm($path);
        $b = $norm($root);
        if (PHP_OS_FAMILY === 'Windows') {
            $a = strtolower($a);
            $b = strtolower($b);
        }

        return str_starts_with($a, $b);
    }

    /**
     * Drives a backup can go to (Windows only; elsewhere the list is empty and the owner types a path).
     *
     * @return list<array{path: string, free_bytes: int, total_bytes: int, system: bool}>
     */
    public function drives(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }
        $system = strtoupper(substr((string) getenv('SystemDrive'), 0, 1)) ?: 'C';
        $drives = [];
        foreach (range('C', 'Z') as $letter) {
            $root = $letter.':\\';
            if (! @is_dir($root)) {
                continue;
            }
            $free = @disk_free_space($root);
            $total = @disk_total_space($root);
            if ($free === false || $total === false) {
                continue;
            }
            $drives[] = ['path' => $root, 'free_bytes' => (int) $free, 'total_bytes' => (int) $total, 'system' => $letter === $system];
        }

        return $drives;
    }

    /**
     * What the dashboard and the backup screen show about the state of backups.
     *
     * @return array{last_verified_at: ?string, last_in_folder_at: ?string, due: bool, auto_wanted: bool, folder: ?string, folder_ok: bool}
     */
    public function status(Store $store): array
    {
        $last = Backup::where('store_id', $store->id)->where('status', Backup::STATUS_VERIFIED)->latest('id')->first();
        $lastInFolder = Backup::where('store_id', $store->id)->where('status', Backup::STATUS_VERIFIED)
            ->whereNotNull('folder_path')->latest('id')->first();
        $lastAuto = Backup::where('store_id', $store->id)->where('kind', 'auto')->latest('id')->first();
        $folder = $this->path($store);
        $folderOk = $this->usable($folder);
        $dueHours = (int) config('shop.backup.due_after_hours');
        $autoHours = (int) config('shop.backup.auto_after_hours');

        $folderCopyOld = $lastInFolder === null || $lastInFolder->created_at->lt(now()->subHours($autoHours));
        // A failed or incomplete automatic attempt is not repeated on every visit to the home screen.
        $autoRecentlyTried = $lastAuto !== null && $lastAuto->created_at->gt(now()->subMinutes(self::AUTO_RETRY_MINUTES));

        return [
            'last_verified_at' => $last?->created_at->toIso8601String(),
            'last_in_folder_at' => $lastInFolder?->created_at->toIso8601String(),
            'due' => $last === null || $last->created_at->lt(now()->subHours($dueHours)),
            'auto_wanted' => $folderOk && $folderCopyOld && ! $autoRecentlyTried,
            'folder' => $folder,
            'folder_ok' => $folderOk,
        ];
    }
}
