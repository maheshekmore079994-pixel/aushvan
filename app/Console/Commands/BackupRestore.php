<?php

namespace App\Console\Commands;

use App\Actions\MakeBackup;
use App\Domain\Backup\BackupRestorer;
use App\Domain\Shared\Audit;
use App\Exceptions\BusinessRuleException;
use App\Models\Store;
use Illuminate\Console\Command;

/**
 * Puts a backup's data back into the database. Everything in the database is replaced, so a safety backup
 * of the current data is taken first. Run it while nobody is billing.
 */
class BackupRestore extends Command
{
    protected $signature = 'backup:restore {file : The backup zip} {--force : Do not ask for confirmation}';

    protected $description = 'Replace the shop data with the contents of a verified backup';

    public function handle(BackupRestorer $restorer, MakeBackup $make): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('This replaces ALL current shop data with the backup. Continue?')) {
            return self::SUCCESS;
        }

        // The file is checked before anything is touched, and the safety backup's retention must not remove it.
        try {
            $restorer->check($file);
            $store = Store::first();
            if ($store !== null) {
                $safety = $make->handle($store, null, MakeBackup::BEFORE_RESTORE, keep: [$file]);
                if (! $safety->isVerified()) {
                    $this->error("Could not take a safety backup of the current data ({$safety->error}); nothing was restored.");

                    return self::FAILURE;
                }
                $this->info("Current data saved first as {$safety->local_path}");
            }
            $result = $restorer->restore($file);
        } catch (BusinessRuleException $e) {
            $this->error("Restore refused: {$e->ruleCode} — {$e->getMessage()}");

            return self::FAILURE;
        }

        Audit::log('backup.restored', null, new: ['file' => basename($file), 'counts' => $result['counts']], storeId: Store::first()?->id);
        $this->info("Restored {$result['tables']} tables and {$result['uploads']} uploaded files from ".basename($file).'.');
        foreach ($result['counts'] as $table => $count) {
            $this->line(sprintf('  %-26s %d', $table, $count));
        }

        return self::SUCCESS;
    }
}
