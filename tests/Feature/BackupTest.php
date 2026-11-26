<?php

namespace Tests\Feature;

use App\Actions\MakeBackup;
use App\Domain\Backup\BackupFolder;
use App\Domain\Backup\BackupVerifier;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Uses DatabaseMigrations, not RefreshDatabase: SQLite refuses VACUUM INTO and ATTACH inside the transaction
 * RefreshDatabase wraps each test in, and a real request never runs inside one.
 */
class BackupTest extends TestCase
{
    use DatabaseMigrations;

    private string $localDir;

    private string $folder;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aushvan-backup-test-'.getmypid().'-'.bin2hex(random_bytes(3));
        $this->localDir = $base.DIRECTORY_SEPARATOR.'local';
        $this->folder = $base.DIRECTORY_SEPARATOR.'usb';
        File::ensureDirectoryExists($this->localDir);
        File::ensureDirectoryExists($this->folder);
        config(['shop.backup.local_dir' => $this->localDir, 'shop.backup.keep_local' => 2]);

        $this->seed(DemoDataSeeder::class);
        $this->owner = User::where('username', 'owner')->sole();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->localDir));
        parent::tearDown();
    }

    private function store(): Store
    {
        return $this->owner->store;
    }

    public function test_owner_makes_a_verified_backup_whose_manifest_matches_the_database(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/v1/backups')
            ->assertCreated()
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('kind', 'manual')
            ->assertJsonPath('downloadable', true)
            ->assertJsonPath('folder_path', null)
            ->assertJsonPath('warning', null);

        $backup = Backup::sole();
        $this->assertFileExists($backup->local_path);
        $this->assertSame(hash_file('sha256', $backup->local_path), $backup->sha256);
        $this->assertSame((int) DB::table('sales')->count(), $response->json('counts.sales'));
        $this->assertSame((int) DB::table('products')->count(), $backup->manifest['counts']['products']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($backup->local_path));
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $dbName = DB::connection()->getDriverName() === 'sqlite' ? 'database.sqlite' : 'database.sql';
        $this->assertNotFalse($zip->locateName($dbName));
        $zip->close();

        $this->assertSame(1, AuditLog::where('action', 'backup.created')->count());
        $this->actingAs($this->owner)->getJson('/api/v1/backups')
            ->assertOk()
            ->assertJsonPath('status.due', false)
            ->assertJsonPath('backups.0.id', $backup->id);
    }

    public function test_only_backup_manage_may_touch_backups(): void
    {
        foreach ([RoleName::Cashier, RoleName::Pharmacist, RoleName::StoreManager] as $role) {
            $user = User::where('username', match ($role) {
                RoleName::Cashier => 'cashier', RoleName::Pharmacist => 'pharmacist', default => 'manager',
            })->sole();
            $this->actingAs($user)->getJson('/api/v1/backups')->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/backups')->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/backups/auto')->assertForbidden();
            $this->actingAs($user)->putJson('/api/v1/backups/folder', ['folder' => $this->folder])->assertForbidden();
        }
        $this->assertSame(0, Backup::count());
    }

    public function test_backup_is_copied_to_the_folder_and_verified_there(): void
    {
        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $this->folder])
            ->assertOk()->assertJsonPath('status.folder', $this->folder)->assertJsonPath('status.folder_ok', true);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/backups')->assertCreated()->assertJsonPath('status', 'verified');
        $copy = $response->json('folder_path');
        $this->assertNotNull($copy);
        $this->assertFileExists($copy);
        $this->assertStringStartsWith($this->folder, $copy);
        $this->assertSame(hash_file('sha256', Backup::sole()->local_path), hash_file('sha256', $copy));
    }

    public function test_missing_folder_still_gives_a_local_backup_with_a_warning(): void
    {
        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $this->folder])->assertOk();
        File::deleteDirectory($this->folder); // the USB stick was pulled out

        $this->actingAs($this->owner)->postJson('/api/v1/backups')
            ->assertCreated()
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('folder_path', null)
            ->assertJsonPath('warning', 'backup_folder_missing');

        $this->actingAs($this->owner)->getJson('/api/v1/backups')->assertJsonPath('status.folder_ok', false)->assertJsonPath('status.auto_wanted', false);
    }

    public function test_folder_must_be_an_absolute_path_outside_the_application(): void
    {
        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => 'backups'])
            ->assertStatus(422)->assertJsonValidationErrors('folder');
        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => storage_path('app')])
            ->assertStatus(422)->assertJsonValidationErrors('folder');
        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $this->folder.DIRECTORY_SEPARATOR.'nothere'.DIRECTORY_SEPARATOR.'deeper'])
            ->assertStatus(422)->assertJsonValidationErrors('folder');

        // A new folder on an existing drive is created.
        $new = $this->folder.DIRECTORY_SEPARATOR.'AUSHVAN Backups';
        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $new])->assertOk()->assertJsonPath('status.folder_ok', true);
        $this->assertDirectoryExists($new);
        $this->assertSame(1, AuditLog::where('action', 'backup.folder_changed')->count());

        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => null])->assertOk()->assertJsonPath('status.folder', null);
    }

    public function test_tampered_archives_are_not_called_backups(): void
    {
        $backup = app(MakeBackup::class)->handle($this->store(), $this->owner);
        $verifier = app(BackupVerifier::class);
        $this->assertTrue($verifier->verify($backup->local_path, $backup->manifest)['ok']);

        // Bytes flipped in the middle of the file: the archive no longer opens or its database checksum fails.
        $bytes = file_get_contents($backup->local_path);
        $middle = intdiv(strlen($bytes), 2);
        $tampered = substr($bytes, 0, $middle).strrev(substr($bytes, $middle, 64)).substr($bytes, $middle + 64);
        file_put_contents($this->localDir.'/tampered.zip', $tampered);
        $result = $verifier->verify($this->localDir.'/tampered.zip', $backup->manifest);
        $this->assertFalse($result['ok']);
        $this->assertContains($result['error'], ['backup_archive_broken', 'backup_database_broken']);

        // Rows added after the backup must not pass as "the same" backup.
        $zip = new ZipArchive;
        $zip->open($backup->local_path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['counts']['sales'] += 1;
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        $this->assertSame('backup_manifest_mismatch', $verifier->verify($backup->local_path, $backup->manifest)['error']);
        $this->assertSame('backup_counts_differ', $verifier->verify($backup->local_path)['error']);

        $this->assertSame('backup_file_missing', $verifier->verify($this->localDir.'/nothing.zip')['error']);
        file_put_contents($this->localDir.'/empty.zip', '');
        $this->assertSame('backup_file_missing', $verifier->verify($this->localDir.'/empty.zip')['error']);
    }

    public function test_download_serves_the_file_and_retention_removes_old_local_copies(): void
    {
        $first = app(MakeBackup::class)->handle($this->store(), $this->owner);
        $this->actingAs($this->owner)->get("/api/v1/backups/{$first->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/zip')
            ->assertDownload($first->file_name);

        app(MakeBackup::class)->handle($this->store(), $this->owner);
        app(MakeBackup::class)->handle($this->store(), $this->owner); // keep_local is 2

        $first->refresh();
        $this->assertNull($first->local_path);
        $this->assertSame(2, Backup::whereNotNull('local_path')->count());
        $this->assertCount(2, File::files($this->localDir));
        $this->actingAs($this->owner)->get("/api/v1/backups/{$first->id}/download")->assertStatus(409)->assertJsonPath('code', 'backup_file_gone');
        $this->assertSame(1, AuditLog::where('action', 'backup.downloaded')->count());
    }

    public function test_restore_puts_the_data_back_exactly(): void
    {
        $product = Product::where('store_id', $this->store()->id)->first();
        $originalName = $product->name;
        $salesBefore = (int) DB::table('sales')->count();
        $ledgerBefore = DB::table('customer_ledger_entries')->sum('debit_paise');

        $backup = app(MakeBackup::class)->handle($this->store(), $this->owner);

        // Life goes on after the backup: a product is renamed and bills are deleted by hand.
        $product->forceFill(['name' => 'Renamed after backup'])->save();
        DB::table('sale_items')->delete();
        DB::table('payments')->delete();
        DB::table('sales')->delete();
        $this->assertSame(0, (int) DB::table('sales')->count());

        $this->artisan('backup:restore', ['file' => $backup->local_path, '--force' => true])
            ->expectsOutputToContain('Restored')
            ->assertSuccessful();

        $this->assertSame($originalName, $product->refresh()->name);
        $this->assertSame($salesBefore, (int) DB::table('sales')->count());
        $this->assertEquals($ledgerBefore, DB::table('customer_ledger_entries')->sum('debit_paise'));
        // A safety copy of the data as it was before the restore was taken first.
        $this->assertSame(1, Backup::where('kind', MakeBackup::BEFORE_RESTORE)->count());
        $this->assertSame(1, AuditLog::where('action', 'backup.restored')->count());
    }

    public function test_restore_refuses_a_backup_from_another_schema_or_a_broken_file(): void
    {
        $backup = app(MakeBackup::class)->handle($this->store(), $this->owner);
        $salesBefore = (int) DB::table('sales')->count();

        $zip = new ZipArchive;
        $zip->open($backup->local_path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['schema'] = '2099_01_01_000000_future_change';
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        $this->artisan('backup:restore', ['file' => $backup->local_path, '--force' => true])
            ->expectsOutputToContain('backup_schema_differs')
            ->assertFailed();
        $this->assertSame($salesBefore, (int) DB::table('sales')->count());

        file_put_contents($this->localDir.'/junk.zip', 'not a zip');
        $this->artisan('backup:restore', ['file' => $this->localDir.'/junk.zip', '--force' => true])
            ->expectsOutputToContain('backup_archive_broken')
            ->assertFailed();
        $this->artisan('backup:restore', ['file' => $this->localDir.'/absent.zip', '--force' => true])->assertFailed();
    }

    public function test_auto_backup_runs_once_when_a_folder_is_set(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertNoContent(); // no folder: nothing to do
        $this->actingAs($this->owner)->getJson('/api/v1/dashboard')
            ->assertJsonPath('backup.due', true)->assertJsonPath('backup.auto_wanted', false);

        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $this->folder])->assertOk();
        $this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertJsonPath('backup.auto_wanted', true);

        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertCreated()->assertJsonPath('kind', 'auto')->assertJsonPath('status', 'verified');
        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertNoContent();
        $this->assertSame(1, Backup::count());
        $this->actingAs($this->owner)->getJson('/api/v1/dashboard')
            ->assertJsonPath('backup.due', false)->assertJsonPath('backup.auto_wanted', false);

        // A cashier's home screen says nothing about backups.
        $this->actingAs(User::where('username', 'cashier')->sole())->getJson('/api/v1/dashboard')->assertJsonMissingPath('backup');
    }

    /** Review finding: a folder spelled with ".." or a short name could point back into the web root. */
    public function test_folder_inside_the_application_is_refused_however_it_is_spelled(): void
    {
        $public = public_path();
        $sneaky = dirname($public).DIRECTORY_SEPARATOR.'nothere'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.basename($public);
        foreach ([$sneaky, strtoupper($public), str_replace('\\', '/', $public).'/', base_path('storage/app')] as $path) {
            $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $path])
                ->assertStatus(422)->assertJsonPath('errors.folder.0', 'backup_folder_inside_app');
        }
        // A real folder is saved as the disk resolves it, and a bare drive root keeps its separator.
        $spelled = $this->folder.DIRECTORY_SEPARATOR.'.'.DIRECTORY_SEPARATOR;
        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $spelled])
            ->assertOk()->assertJsonPath('status.folder', realpath($this->folder));
        $this->assertSame('E:\\', app(BackupFolder::class)->normalise('e:'));
        $this->assertSame('E:\\', app(BackupFolder::class)->normalise('E:\\'));
    }

    /** Review finding: restoring the oldest local backup must not let the safety backup's retention delete it. */
    public function test_restoring_the_oldest_local_backup_survives_retention(): void
    {
        config(['shop.backup.keep_local' => 1]);
        $oldest = app(MakeBackup::class)->handle($this->store(), $this->owner);
        $marker = Product::where('store_id', $this->store()->id)->first();
        $marker->forceFill(['name' => 'Changed since the oldest backup'])->save();

        $this->artisan('backup:restore', ['file' => $oldest->local_path, '--force' => true])->assertSuccessful();
        $this->assertNotSame('Changed since the oldest backup', $marker->refresh()->name);
        $this->assertFileExists($oldest->local_path); // protected while it was being read
    }

    /** Review finding: a failed attempt must leave no file behind and must not use up a retention slot. */
    public function test_failed_attempt_keeps_no_file_and_no_retention_slot(): void
    {
        config(['shop.backup.keep_local' => 1]);
        $good = app(MakeBackup::class)->handle($this->store(), $this->owner);

        File::deleteDirectory($this->localDir);
        file_put_contents($this->localDir, 'a file where the folder should be'); // the local dir cannot be used
        $bad = app(MakeBackup::class)->handle($this->store(), $this->owner);
        $this->assertSame('failed', $bad->status);
        $this->assertSame('backup_write_failed', $bad->error);
        $this->assertNull($bad->local_path);
        $this->assertFalse($bad->refresh()->hasLocalFile());
        unlink($this->localDir);
        File::ensureDirectoryExists($this->localDir);

        $this->assertNotNull($good->refresh()->local_path, 'the failed attempt pushed the good copy out');
        $this->actingAs($this->owner)->getJson('/api/v1/backups')->assertJsonPath('backups.0.downloadable', false)->assertJsonPath('backups.1.downloadable', false);
    }

    /** Review finding: sessions, locks and spent keys are neither carried in the archive nor revived by a restore. */
    public function test_volatile_rows_are_left_out_of_the_archive_and_of_a_restore(): void
    {
        DB::table('cache_locks')->insert(['key' => 'old-lock', 'owner' => 'x', 'expiration' => now()->addDay()->timestamp]);
        $backup = app(MakeBackup::class)->handle($this->store(), $this->owner);
        DB::table('cache_locks')->where('key', 'old-lock')->delete();
        DB::table('cache_locks')->insert(['key' => 'live-lock', 'owner' => 'y', 'expiration' => now()->addDay()->timestamp]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            $zip = new ZipArchive;
            $zip->open($backup->local_path);
            $copy = $this->localDir.'/copy.sqlite';
            file_put_contents($copy, $zip->getFromName('database.sqlite'));
            $zip->close();
            $this->assertSame(0, (int) (new \PDO('sqlite:'.$copy))->query('SELECT COUNT(*) FROM cache_locks')->fetchColumn());
        }

        $this->artisan('backup:restore', ['file' => $backup->local_path, '--force' => true])->assertSuccessful();
        $this->assertNull(DB::table('cache_locks')->where('key', 'old-lock')->first(), 'a stale lock came back');
        $this->assertNotNull(DB::table('cache_locks')->where('key', 'live-lock')->first(), 'the live lock was removed');
        // The backup history is kept too: the restore's own safety backup is still listed.
        $this->assertSame(2, Backup::count());
    }

    /** Review finding: a copy that keeps failing to reach the folder must not run on every visit to the home screen. */
    public function test_automatic_backup_backs_off_after_a_failed_folder_copy(): void
    {
        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $this->folder])->assertOk();
        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertCreated()->assertJsonPath('kind', 'auto');
        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertNoContent();

        // The copy is old (or never reached the folder) but an automatic attempt ran half an hour ago: wait.
        Backup::query()->update(['folder_path' => null, 'created_at' => now()->subMinutes(30)]);
        $this->actingAs($this->owner)->getJson('/api/v1/backups')->assertJsonPath('status.auto_wanted', false);
        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertNoContent();

        // Once the retry pause is over it is tried again.
        Backup::query()->update(['created_at' => now()->subMinutes(BackupFolder::AUTO_RETRY_MINUTES + 1)]);
        $this->actingAs($this->owner)->getJson('/api/v1/backups')->assertJsonPath('status.auto_wanted', true);
        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertCreated();
        $this->assertSame(2, Backup::count());
    }

    /** Review finding: the uploaded files inside the archive are checked, not only the database. */
    public function test_damaged_upload_inside_the_archive_fails_verification(): void
    {
        $root = config('filesystems.disks.local.root');
        File::ensureDirectoryExists($root.'/rx');
        file_put_contents($root.'/rx/test-prescription.txt', 'a prescription photo stands in here');
        try {
            $backup = app(MakeBackup::class)->handle($this->store(), $this->owner);
            $this->assertSame('verified', $backup->status);
            $this->assertSame(1, $backup->manifest['uploads']);

            $zip = new ZipArchive;
            $zip->open($backup->local_path);
            $zip->addFromString('uploads/rx/test-prescription.txt', 'something else');
            $zip->close();
            $verifier = app(BackupVerifier::class);
            $this->assertSame('backup_uploads_broken', $verifier->verify($backup->local_path, $backup->manifest)['error']);
            $this->assertSame('backup_uploads_broken', $verifier->verify($backup->local_path)['error']);
        } finally {
            File::deleteDirectory($root.'/rx');
        }
    }

    /** Review finding: two backups at once would have written the same file. */
    public function test_second_backup_at_the_same_time_is_refused(): void
    {
        $lock = Cache::lock('backup:'.$this->store()->id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($this->owner)->postJson('/api/v1/backups')->assertStatus(409)->assertJsonPath('code', 'backup_running');
            $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $this->folder])->assertOk();
            $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertNoContent();
        } finally {
            $lock->release();
        }
        $this->assertSame(0, Backup::count());
        $this->actingAs($this->owner)->postJson('/api/v1/backups')->assertCreated();
    }

    public function test_scheduled_command_backs_up_only_when_due(): void
    {
        $this->artisan('backup:run', ['--if-due' => true])->assertSuccessful();
        $this->assertSame(1, Backup::where('kind', MakeBackup::SCHEDULED)->count());
        $this->artisan('backup:run', ['--if-due' => true])->expectsOutputToContain('recently')->assertSuccessful();
        $this->assertSame(1, Backup::count());
        $this->artisan('backup:run')->assertSuccessful();
        $this->assertSame(2, Backup::count());
    }
}
