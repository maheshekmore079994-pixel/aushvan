<?php

namespace App\Console\Commands;

use App\Actions\MakeBackup;
use App\Domain\Backup\BackupFolder;
use App\Models\Store;
use Illuminate\Console\Command;

/** Takes a backup from the command line or the scheduler (hosted installs: a daily cron). */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--if-due : Only when no verified backup was made in the last auto_after_hours}';

    protected $description = 'Make and verify a backup of the shop database and uploads';

    public function handle(MakeBackup $make, BackupFolder $folder): int
    {
        $store = Store::first();
        if ($store === null) {
            $this->warn('No shop is set up yet; nothing to back up.');

            return self::SUCCESS;
        }
        if ($this->option('if-due')) {
            $status = $folder->status($store);
            $recent = $status['last_verified_at'] !== null
                && now()->parse($status['last_verified_at'])->gt(now()->subHours((int) config('shop.backup.auto_after_hours')));
            if ($recent) {
                $this->info('A verified backup was made recently; nothing to do.');

                return self::SUCCESS;
            }
        }

        $backup = $make->handle($store, null, MakeBackup::SCHEDULED);
        if (! $backup->isVerified()) {
            $this->error("Backup failed: {$backup->error}");

            return self::FAILURE;
        }
        $this->info("Verified backup {$backup->file_name} (".number_format($backup->size_bytes / 1024).' KB)');
        if ($backup->folder_path !== null) {
            $this->info("Copied to {$backup->folder_path}");
        } elseif ($backup->warning !== null) {
            $this->warn("Not copied to the backup folder: {$backup->warning}");
        }

        return self::SUCCESS;
    }
}
