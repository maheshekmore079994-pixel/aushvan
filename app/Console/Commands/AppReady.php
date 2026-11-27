<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a freshly uploaded or updated installation ready without a terminal: the application key when there is
 * none, the pending migrations, and a fresh configuration cache. Meant to run every minute from the host's cron
 * (before `schedule:run`): it is silent and does nothing when nothing is needed, so a shared-hosting account
 * without SSH is set up and updated by uploading files alone (D-064).
 */
class AppReady extends Command
{
    protected $signature = 'app:ready {--no-cache : leave the configuration cache alone}';

    protected $description = 'Create the key, run pending migrations and refresh the config cache when needed';

    public function handle(): int
    {
        $envFile = $this->laravel->environmentFilePath();
        if (! File::exists($envFile)) {
            $this->error('.env is missing: copy .env.hosting to .env and fill in the database and domain lines.');

            return self::FAILURE;
        }

        $did = [];
        if (! preg_match('/^APP_KEY=base64:/m', File::get($envFile))) {
            if (Artisan::call('key:generate', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                $this->error(trim(Artisan::output()));

                return self::FAILURE;
            }
            // A cached configuration would keep the empty key.
            Artisan::call('config:clear');
            $did[] = 'application key created';
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Database connection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $pending = $this->pendingMigrations();
        if ($pending > 0) {
            if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                $this->error(trim(Artisan::output()));

                return self::FAILURE;
            }
            $did[] = "{$pending} migration(s) run";
        }

        if (! $this->option('no-cache') && ($did !== [] || ! File::exists($this->laravel->getCachedConfigPath()))) {
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');
            $did[] = 'caches refreshed';
        }

        if ($did !== []) {
            $this->info(now()->toDateTimeString().' app:ready — '.implode(', ', $did).'.');
        }

        return self::SUCCESS;
    }

    private function pendingMigrations(): int
    {
        $files = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getFilenameWithoutExtension());
        if (! Schema::hasTable('migrations')) {
            return $files->count();
        }
        $ran = DB::table('migrations')->pluck('migration');

        return $files->diff($ran)->count();
    }
}
