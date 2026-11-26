<?php

namespace App\Actions;

use App\Domain\Backup\BackupArchive;
use App\Domain\Backup\BackupFolder;
use App\Domain\Backup\BackupVerifier;
use App\Domain\Shared\Audit;
use App\Exceptions\BusinessRuleException;
use App\Models\Backup;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Takes one backup: writes the archive on this PC/server, verifies it, copies it into the shop's backup
 * folder (USB / second disk) when one is set and verifies that copy too, records the result and removes
 * local copies beyond the retention count. Never throws for an unusable backup: the row says "failed" and why.
 * One backup at a time per shop; a second caller is told `backup_running`.
 */
final class MakeBackup
{
    public const MANUAL = 'manual';

    public const AUTO = 'auto';

    public const SCHEDULED = 'scheduled';

    public const BEFORE_RESTORE = 'before_restore';

    public function __construct(
        private BackupArchive $archive,
        private BackupVerifier $verifier,
        private BackupFolder $folder,
    ) {}

    /**
     * @param  list<string>  $keep  local files that retention must leave alone (the file a restore is reading)
     */
    public function handle(Store $store, ?User $by, string $kind = self::MANUAL, array $keep = []): Backup
    {
        $lock = Cache::lock('backup:'.$store->id, 600);
        if (! $lock->get()) {
            throw new BusinessRuleException('backup_running', 'A backup is already being taken. Wait a moment.');
        }
        try {
            return $this->run($store, $by, $kind, $keep);
        } finally {
            $lock->release();
        }
    }

    private function run(Store $store, ?User $by, string $kind, array $keep): Backup
    {
        $localDir = rtrim((string) config('shop.backup.local_dir'), '/\\');
        $workDir = null;
        $row = [
            'store_id' => $store->id,
            'kind' => $kind,
            'file_name' => 'AUSHVAN-backup.zip',
            'local_path' => null,
            'folder_path' => null,
            'size_bytes' => 0,
            'sha256' => str_repeat('0', 64),
            'status' => Backup::STATUS_FAILED,
            'error' => null,
            'warning' => null,
            'manifest' => null,
            'created_by' => $by?->id,
            'verified_at' => null,
        ];

        try {
            // Even an unusable local folder is a recorded failure, not an exception.
            if (! is_dir($localDir) && ! @mkdir($localDir, 0775, true)) {
                throw new \RuntimeException("The local backup folder {$localDir} cannot be used.");
            }
            $workDir = $this->verifier->tempDir();
            $fileName = $row['file_name'] = $this->fileName($store, $localDir);
            $localPath = $localDir.DIRECTORY_SEPARATOR.$fileName;
            $draft = $workDir.DIRECTORY_SEPARATOR.'archive.zip';

            // Built in the work dir and moved into place only once whole, so a half-written file never has a name.
            $manifest = $this->archive->make($store, $workDir, $draft);
            $row['manifest'] = $manifest;
            $row['size_bytes'] = (int) filesize($draft);
            $row['sha256'] = hash_file('sha256', $draft);

            $check = $this->verifier->verify($draft, $manifest);
            if (! $check['ok']) {
                $row['error'] = $check['error'];
            } elseif (! @rename($draft, $localPath)) {
                $row['error'] = 'backup_write_failed';
            } else {
                $row['local_path'] = $localPath;
                $row['status'] = Backup::STATUS_VERIFIED;
                $row['verified_at'] = now();
                $this->copyToFolder($store, $localPath, $fileName, $manifest, $row);
            }
        } catch (Throwable $e) {
            report($e);
            $row['error'] = 'backup_write_failed';
        } finally {
            if ($workDir !== null) {
                File::deleteDirectory($workDir);
            }
        }

        $backup = Backup::create($row)->refresh();
        $this->applyRetention($store, $keep);
        Audit::log(
            $backup->isVerified() ? 'backup.created' : 'backup.failed',
            $backup,
            new: ['file' => $backup->file_name, 'kind' => $kind, 'size_bytes' => $backup->size_bytes, 'folder' => $backup->folder_path, 'error' => $backup->error, 'warning' => $backup->warning],
            userId: $by?->id,
            storeId: $store->id,
        );

        return $backup;
    }

    /** @param  array<string, mixed>  $row */
    private function copyToFolder(Store $store, string $localPath, string $fileName, array $manifest, array &$row): void
    {
        $folder = $this->folder->path($store);
        if ($folder === null) {
            return;
        }
        if (! $this->folder->usable($folder)) {
            $row['warning'] = 'backup_folder_missing';

            return;
        }
        $free = @disk_free_space($folder);
        if ($free !== false && $free < $row['size_bytes'] + 1_000_000) {
            $row['warning'] = 'backup_folder_full';

            return;
        }
        $target = rtrim($folder, '/\\').DIRECTORY_SEPARATOR.$fileName;
        if (! @copy($localPath, $target)) {
            $row['warning'] = 'backup_folder_copy_failed';

            return;
        }
        $check = $this->verifier->verify($target, $manifest);
        if (! $check['ok']) {
            @unlink($target);
            $row['warning'] = 'backup_folder_copy_broken';

            return;
        }
        $row['folder_path'] = $target;
    }

    /**
     * Deletes local copies beyond the retention count (oldest first); rows and folder copies stay. Only verified
     * backups hold a local file, so failed attempts never push a good copy out.
     *
     * @param  list<string>  $keep
     */
    private function applyRetention(Store $store, array $keep): void
    {
        $max = max(1, (int) config('shop.backup.keep_local'));
        $protected = array_map(fn ($p) => realpath($p) ?: $p, $keep);
        $withFile = Backup::where('store_id', $store->id)->whereNotNull('local_path')->orderByDesc('id')->get();
        foreach ($withFile->slice($max) as $old) {
            if (in_array(realpath($old->local_path) ?: $old->local_path, $protected, true)) {
                continue;
            }
            if (is_file($old->local_path)) {
                @unlink($old->local_path);
            }
            $old->forceFill(['local_path' => null])->save();
        }
    }

    /** Shop name, date and time; a -2, -3… tail when a file of that second already exists (two backups never share a file). */
    private function fileName(Store $store, string $localDir): string
    {
        $slug = Str::of($store->name)->ascii()->slug('-')->value();
        if (strlen($slug) > 30) {
            $cut = strrpos(substr($slug, 0, 31), '-');
            $slug = substr($slug, 0, $cut !== false && $cut > 10 ? $cut : 30);
        }
        // The shop's own date and time, so the file name reads like the clock on the wall.
        $base = 'AUSHVAN-backup-'.($slug ?: 'shop').'-'.now()->setTimezone((string) config('shop.timezone'))->format('Ymd-His');
        $name = $base.'.zip';
        for ($n = 2; is_file($localDir.DIRECTORY_SEPARATOR.$name) || Backup::where('file_name', $name)->exists(); $n++) {
            $name = "{$base}-{$n}.zip";
        }

        return $name;
    }
}
