<?php

namespace Tests\Feature;

use App\Actions\MakeBackup;
use App\Domain\Backup\BackupVerifier;
use App\Models\Backup;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Throwable;
use ZipArchive;

/**
 * Tries to break the backup feature: hostile folder settings, downloads of files that are not this store's
 * backups, damaged archives, restores in awkward orders and a local folder that cannot be written.
 * DatabaseMigrations, not RefreshDatabase: SQLite refuses VACUUM INTO and ATTACH inside a transaction.
 */
class BackupRobustnessTest extends TestCase
{
    use DatabaseMigrations;

    private string $base;

    private string $localDir;

    private string $folder;

    private User $owner;

    /** @var list<string> directories created inside the application that must be removed afterwards */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aushvan-backup-robust-'.getmypid().'-'.bin2hex(random_bytes(3));
        $this->localDir = $this->base.DIRECTORY_SEPARATOR.'local';
        $this->folder = $this->base.DIRECTORY_SEPARATOR.'usb';
        File::ensureDirectoryExists($this->localDir);
        File::ensureDirectoryExists($this->folder);
        config(['shop.backup.local_dir' => $this->localDir, 'shop.backup.keep_local' => 5]);

        $this->seed(DemoDataSeeder::class);
        $this->owner = User::where('username', 'owner')->sole();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }
        if (is_file($this->localDir)) {
            @unlink($this->localDir);
        }
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function store(): Store
    {
        return $this->owner->store;
    }

    private function backup(): Backup
    {
        $backup = app(MakeBackup::class)->handle($this->store(), $this->owner);
        $this->assertTrue($backup->isVerified(), 'setup backup failed: '.$backup->error);

        return $backup;
    }

    /** A copy of a backup archive, rewritten by $change(ZipArchive, array &$manifest, string $workDir). */
    private function rewritten(Backup $backup, string $name, callable $change): string
    {
        $path = $this->base.DIRECTORY_SEPARATOR.$name;
        copy($backup->local_path, $path);
        $work = $this->base.DIRECTORY_SEPARATOR.'work-'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($work);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $change($zip, $manifest, $work);
        if ($manifest !== null) {
            $zip->addFromString('manifest.json', json_encode($manifest));
        }
        $zip->close();
        File::deleteDirectory($work);

        return $path;
    }

    /** These tests edit the SQLite copy inside the archive by name; on MySQL the archive holds a SQL dump instead. */
    private function sqliteOnly(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Edits the SQLite database copy inside the archive.');
        }
    }

    /** Changes the database copy inside an archive with $sql and records its new checksum in the manifest. */
    private function withDatabaseChanged(Backup $backup, string $name, string $sql): string
    {
        $path = $this->base.DIRECTORY_SEPARATOR.$name;
        copy($backup->local_path, $path);
        $work = $this->base.DIRECTORY_SEPARATOR.'work-'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($work);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertTrue($zip->extractTo($work, 'database.sqlite'));
        $db = $work.DIRECTORY_SEPARATOR.'database.sqlite';
        $pdo = new \PDO('sqlite:'.$db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($sql);
        $pdo = null;
        $manifest['database']['sha256'] = hash_file('sha256', $db);
        $zip->addFile($db, 'database.sqlite');
        $zip->addFromString('manifest.json', json_encode($manifest));
        $this->assertTrue($zip->close());
        File::deleteDirectory($work);

        return $path;
    }

    private function putFolder(?string $folder): TestResponse
    {
        return $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => $folder]);
    }

    // ---------------------------------------------------------------- folder setting

    public function test_folder_inside_the_application_is_refused_however_it_is_spelled(): void
    {
        $inside = storage_path('app');
        $variants = [
            str_replace('\\', '/', $inside),
            strtoupper($inside),
            strtolower(str_replace('\\', '/', $inside)).'///',
            $inside.DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR,
            base_path(),
            base_path('public'),
        ];
        foreach ($variants as $variant) {
            $this->putFolder($variant)->assertStatus(422)->assertJsonValidationErrors('folder');
        }
        $this->assertNull($this->store()->refresh()->setting('backup_folder'));
    }

    public function test_folder_inside_the_application_through_dot_dot_segments_is_refused(): void
    {
        // F:\Mahesh\zz-missing\..\RMGSMS\storage\app\robust-x resolves to a folder inside the application.
        $name = 'robust-'.bin2hex(random_bytes(3));
        $crafted = dirname(base_path()).DIRECTORY_SEPARATOR.'zz-not-here'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR
            .basename(base_path()).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.$name;
        $this->cleanup[] = storage_path('app'.DIRECTORY_SEPARATOR.$name);
        if (! is_dir(dirname($crafted))) {
            $this->markTestSkipped('This filesystem does not resolve .. through a missing folder.');
        }

        $this->putFolder($crafted)->assertStatus(422)->assertJsonValidationErrors('folder');
        $this->assertDirectoryDoesNotExist(storage_path('app'.DIRECTORY_SEPARATOR.$name), 'a folder was created inside the application');
    }

    public function test_bad_folder_values_are_refused_and_change_nothing(): void
    {
        $this->putFolder($this->folder)->assertOk();

        $file = $this->base.DIRECTORY_SEPARATOR.'a-file.txt';
        file_put_contents($file, 'x');
        $this->putFolder($file)->assertStatus(422)->assertJsonValidationErrors('folder');
        $this->putFolder($this->base.DIRECTORY_SEPARATOR.str_repeat('a', 300))->assertStatus(422)->assertJsonValidationErrors('folder');
        $this->putFolder('..\\..\\backups')->assertStatus(422)->assertJsonValidationErrors('folder');
        $this->putFolder('relative/path')->assertStatus(422)->assertJsonValidationErrors('folder');
        $this->actingAs($this->owner)->putJson('/api/v1/backups/folder', ['folder' => ['an', 'array']])->assertStatus(422);

        $this->assertSame($this->folder, $this->store()->refresh()->setting('backup_folder'));

        // An empty string clears the folder, as null does.
        $this->putFolder('   ')->assertOk()->assertJsonPath('status.folder', null);
    }

    public function test_a_drive_root_can_be_chosen_as_the_folder(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Drive letters exist only on Windows.');
        }
        $root = null;
        foreach ([sys_get_temp_dir(), base_path()] as $somewhere) {
            $candidate = strtoupper(substr($somewhere, 0, 1)).':\\';
            if (is_writable($candidate)) {
                $root = $candidate;
                break;
            }
        }
        if ($root === null) {
            $this->markTestSkipped('No writable drive root here.');
        }
        // The backup screen lists drives as "E:\"; trimming the trailing slash leaves the drive-relative "E:".
        $this->putFolder($root)->assertOk()->assertJsonPath('status.folder_ok', true);
    }

    public function test_a_unicode_folder_works_end_to_end(): void
    {
        $unicode = $this->folder.DIRECTORY_SEPARATOR.'बॅकअप दुकान';
        $this->putFolder($unicode)->assertOk()->assertJsonPath('status.folder_ok', true);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/backups')->assertCreated()->assertJsonPath('status', 'verified');
        $this->assertNotNull($response->json('folder_path'), 'warning: '.$response->json('warning'));
        $this->assertFileExists($response->json('folder_path'));
    }

    // ---------------------------------------------------------------- download

    public function test_download_never_serves_another_stores_or_a_missing_backup(): void
    {
        $other = (new Store)->forceFill(['name' => 'Other shop']);
        $other->save();
        $file = $this->localDir.DIRECTORY_SEPARATOR.'other.zip';
        file_put_contents($file, 'other shop data');
        $foreign = Backup::create([
            'store_id' => $other->id, 'kind' => 'manual', 'file_name' => 'other.zip', 'local_path' => $file,
            'size_bytes' => 15, 'sha256' => str_repeat('a', 64), 'status' => 'verified',
        ]);

        $this->actingAs($this->owner)->get("/api/v1/backups/{$foreign->id}/download")->assertNotFound();
        $this->actingAs($this->owner)->get('/api/v1/backups/999999/download')->assertNotFound();
        $this->actingAs($this->owner)->get('/api/v1/backups/abc/download')->assertNotFound();
    }

    public function test_download_refuses_a_row_pointing_outside_the_local_folder(): void
    {
        $outside = $this->folder.DIRECTORY_SEPARATOR.'secret.zip';
        file_put_contents($outside, 'not a backup');
        $row = Backup::create([
            'store_id' => $this->store()->id, 'kind' => 'manual', 'file_name' => 'secret.zip', 'local_path' => $outside,
            'size_bytes' => 12, 'sha256' => str_repeat('a', 64), 'status' => 'verified',
        ]);
        $this->actingAs($this->owner)->get("/api/v1/backups/{$row->id}/download")->assertStatus(409)->assertJsonPath('code', 'backup_file_gone');

        $dotted = $this->localDir.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'usb'.DIRECTORY_SEPARATOR.'secret.zip';
        $row->forceFill(['local_path' => $dotted])->save();
        $this->actingAs($this->owner)->get("/api/v1/backups/{$row->id}/download")->assertStatus(409);
    }

    public function test_download_refuses_a_sibling_folder_whose_name_starts_like_the_local_folder(): void
    {
        // local dir ".../local", file in ".../local-evil": a bare prefix test lets it through.
        $sibling = $this->localDir.'-evil';
        File::ensureDirectoryExists($sibling);
        $file = $sibling.DIRECTORY_SEPARATOR.'x.zip';
        file_put_contents($file, 'outside the backup folder');
        $row = Backup::create([
            'store_id' => $this->store()->id, 'kind' => 'manual', 'file_name' => 'x.zip', 'local_path' => $file,
            'size_bytes' => 25, 'sha256' => str_repeat('a', 64), 'status' => 'verified',
        ]);

        $this->actingAs($this->owner)->get("/api/v1/backups/{$row->id}/download")->assertStatus(409);
    }

    public function test_download_of_a_file_deleted_on_disk_says_gone(): void
    {
        $backup = $this->backup();
        unlink($backup->local_path);
        $this->actingAs($this->owner)->get("/api/v1/backups/{$backup->id}/download")->assertStatus(409)->assertJsonPath('code', 'backup_file_gone');
        $this->actingAs($this->owner)->getJson('/api/v1/backups')->assertJsonPath('backups.0.downloadable', false);
    }

    public function test_no_other_role_reaches_any_backup_route(): void
    {
        $backup = $this->backup();
        foreach (['cashier', 'pharmacist', 'manager'] as $username) {
            $user = User::where('username', $username)->sole();
            $this->actingAs($user)->get("/api/v1/backups/{$backup->id}/download")->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/backups')->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/backups')->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/backups/auto')->assertForbidden();
            $this->actingAs($user)->putJson('/api/v1/backups/folder', ['folder' => null])->assertForbidden();
        }
        $this->assertSame(1, Backup::count());
        $this->assertNull($this->store()->refresh()->setting('backup_folder'));
    }

    // ---------------------------------------------------------------- verification

    public function test_damaged_archives_are_named_for_what_is_wrong(): void
    {
        $this->sqliteOnly();
        $backup = $this->backup();
        $verifier = app(BackupVerifier::class);

        $noManifest = $this->rewritten($backup, 'no-manifest.zip', function (ZipArchive $zip, ?array &$manifest) {
            $zip->deleteName('manifest.json');
            $manifest = null;
        });
        $this->assertSame('backup_archive_broken', $verifier->verify($noManifest)['error']);

        $noDb = $this->rewritten($backup, 'no-db.zip', fn (ZipArchive $zip) => $zip->deleteName('database.sqlite'));
        $this->assertSame('backup_archive_broken', $verifier->verify($noDb)['error']);

        $wrongSha = $this->rewritten($backup, 'wrong-sha.zip', function (ZipArchive $zip, array &$manifest) {
            $manifest['database']['sha256'] = str_repeat('f', 64);
        });
        $this->assertSame('backup_database_broken', $verifier->verify($wrongSha)['error']);

        $text = $this->base.DIRECTORY_SEPARATOR.'text.zip';
        file_put_contents($text, "just some text\n");
        $this->assertSame('backup_archive_broken', $verifier->verify($text)['error']);

        $badJson = $this->rewritten($backup, 'bad-json.zip', function (ZipArchive $zip, ?array &$manifest) {
            $zip->addFromString('manifest.json', '{not json');
            $manifest = null;
        });
        $this->assertSame('backup_archive_broken', $verifier->verify($badJson)['error']);

        // The original is untouched by all of this.
        $this->assertTrue($verifier->verify($backup->local_path, $backup->manifest)['ok']);
        $this->assertSame([], File::directories($this->localDir.DIRECTORY_SEPARATOR.'tmp'), 'verifier left work folders behind');
    }

    public function test_a_database_copy_with_missing_rows_or_a_dropped_table_fails_even_with_a_matching_checksum(): void
    {
        $this->sqliteOnly();
        $backup = $this->backup();
        $verifier = app(BackupVerifier::class);
        $this->assertGreaterThan(0, $backup->manifest['counts']['products']);

        $fewer = $this->withDatabaseChanged($backup, 'fewer.zip', 'DELETE FROM products');
        $this->assertSame('backup_counts_differ', $verifier->verify($fewer)['error']);

        $dropped = $this->withDatabaseChanged($backup, 'dropped.zip', 'DROP TABLE payments');
        $this->assertContains($verifier->verify($dropped)['error'], ['backup_database_broken', 'backup_counts_differ']);

        $this->artisan('backup:restore', ['file' => $fewer, '--force' => true])->assertFailed();
        $this->assertSame($backup->manifest['counts']['products'], (int) DB::table('products')->count());
    }

    // ---------------------------------------------------------------- restore

    public function test_restores_go_back_and_forward_and_keep_the_list_of_backups(): void
    {
        $product = Product::where('store_id', $this->store()->id)->first();
        $oldName = $product->name;

        $old = $this->backup();
        $product->forceFill(['name' => 'Renamed between backups'])->save();
        $new = $this->backup();
        $rowsBefore = Backup::count();

        $this->artisan('backup:restore', ['file' => $old->local_path, '--force' => true])->assertSuccessful();
        $this->assertSame($oldName, $product->refresh()->name);
        // The backups table is not restored: the newer backup's row (made after $old) is still there.
        $this->assertNotNull(Backup::find($new->id));
        $this->assertSame($rowsBefore + 1, Backup::count());

        $this->artisan('backup:restore', ['file' => $new->local_path, '--force' => true])->assertSuccessful();
        $this->assertSame('Renamed between backups', $product->refresh()->name);
        $this->assertSame($rowsBefore + 2, Backup::count());
        $this->assertSame(2, Backup::where('kind', MakeBackup::BEFORE_RESTORE)->count());

        // The owner can still use the app after two restores (users and roles came back whole).
        $this->actingAs(User::where('username', 'owner')->sole())->getJson('/api/v1/backups')->assertOk();
        $this->assertSame([], File::directories($this->localDir.DIRECTORY_SEPARATOR.'tmp'));
    }

    public function test_restoring_the_oldest_local_backup_is_not_defeated_by_the_safety_backup(): void
    {
        // With keep_local = 2, the safety copy taken before a restore is the third local file: retention
        // must not delete the very file that is about to be restored.
        config(['shop.backup.keep_local' => 2]);
        $product = Product::where('store_id', $this->store()->id)->first();
        $oldName = $product->name;
        $oldest = $this->backup();
        $product->forceFill(['name' => 'Changed later'])->save();
        $this->backup();

        $this->artisan('backup:restore', ['file' => $oldest->local_path, '--force' => true])->assertSuccessful();
        $this->assertSame($oldName, $product->refresh()->name);
    }

    public function test_restore_refuses_a_backup_from_the_other_database_driver(): void
    {
        $backup = $this->backup();
        $path = $this->rewritten($backup, 'other-driver.zip', function (ZipArchive $zip, array &$manifest) {
            $manifest['database']['driver'] = DB::connection()->getDriverName() === 'sqlite' ? 'mysql' : 'sqlite';
        });
        $salesBefore = (int) DB::table('sales')->count();

        $this->artisan('backup:restore', ['file' => $path, '--force' => true])
            ->expectsOutputToContain('backup_driver_differs')
            ->assertFailed();
        $this->assertSame($salesBefore, (int) DB::table('sales')->count());
    }

    public function test_restore_without_force_does_nothing_when_the_owner_says_no(): void
    {
        $backup = $this->backup();
        $product = Product::where('store_id', $this->store()->id)->first();
        $product->forceFill(['name' => 'Kept because the owner said no'])->save();

        $this->artisan('backup:restore', ['file' => $backup->local_path])
            ->expectsConfirmation('This replaces ALL current shop data with the backup. Continue?', 'no')
            ->assertSuccessful();

        $this->assertSame('Kept because the owner said no', $product->refresh()->name);
        $this->assertSame(0, Backup::where('kind', MakeBackup::BEFORE_RESTORE)->count());
    }

    // ---------------------------------------------------------------- making backups

    public function test_an_unwritable_local_folder_gives_a_failed_row_not_an_exception(): void
    {
        File::deleteDirectory($this->localDir);
        file_put_contents($this->localDir, 'a file where the backup folder should be');

        try {
            $backup = app(MakeBackup::class)->handle($this->store(), $this->owner);
        } catch (Throwable $e) {
            $this->fail('MakeBackup threw instead of recording a failed backup: '.get_class($e).': '.$e->getMessage());
        }
        $this->assertSame(Backup::STATUS_FAILED, $backup->status);
        $this->assertSame('backup_write_failed', $backup->error);
        $this->assertNull($backup->local_path);
    }

    public function test_an_archive_that_cannot_be_written_leaves_no_bogus_local_path(): void
    {
        $this->travelTo(now()->startOfSecond());
        $first = $this->backup();
        // The next backup in this second is named "<first>-2.zip"; a folder squats on that name, so the zip
        // cannot be created there.
        $name = Str::replaceLast('.zip', '-2.zip', $first->file_name);
        File::ensureDirectoryExists($this->localDir.DIRECTORY_SEPARATOR.$name);

        try {
            $backup = app(MakeBackup::class)->handle($this->store(), $this->owner);
        } catch (Throwable $e) {
            $this->fail('MakeBackup threw: '.get_class($e).': '.$e->getMessage());
        }
        $this->assertSame($name, $backup->file_name, 'the file name did not collide with the squatting folder');
        $this->assertSame(Backup::STATUS_FAILED, $backup->status);
        $this->assertSame('backup_write_failed', $backup->error);
        $this->assertNull($backup->local_path, 'a failed backup records a local_path that is not a backup file');
        $this->assertFalse($backup->hasLocalFile());
        $this->assertSame([], File::directories($this->localDir.DIRECTORY_SEPARATOR.'tmp'), 'work folders left behind');
    }

    public function test_two_backups_in_the_same_second_get_their_own_files(): void
    {
        $this->travelTo(now()->startOfSecond());
        $first = $this->backup();
        $second = $this->backup();

        $this->assertNotSame($first->file_name, $second->file_name);
        $this->assertNotSame($first->local_path, $second->local_path);
        $this->assertFileExists($first->local_path);
        $this->assertFileExists($second->local_path);
        $this->assertTrue(app(BackupVerifier::class)->verify($first->local_path, $first->manifest)['ok']);
    }

    public function test_retention_removes_only_older_local_files_and_never_folder_copies(): void
    {
        config(['shop.backup.keep_local' => 1]);
        $this->putFolder($this->folder)->assertOk();
        $stranger = $this->localDir.DIRECTORY_SEPARATOR.'not-a-backup.txt';
        file_put_contents($stranger, 'keep me');

        $a = $this->backup();
        $this->travel(2)->seconds();
        $b = $this->backup();
        $this->travel(2)->seconds();
        $c = $this->backup();

        $this->assertNull($a->refresh()->local_path);
        $this->assertNull($b->refresh()->local_path);
        $this->assertFileExists($c->refresh()->local_path);
        foreach ([$a, $b, $c] as $backup) {
            $this->assertNotNull($backup->folder_path);
            $this->assertFileExists($backup->folder_path, 'retention removed a folder copy');
        }
        $this->assertFileExists($stranger, 'retention removed a file that is not one of its backups');
        $this->assertSame(1, Backup::whereNotNull('local_path')->count());
    }

    public function test_auto_backup_is_not_taken_twice_and_not_into_a_vanished_folder(): void
    {
        $this->putFolder($this->folder)->assertOk();
        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertCreated();
        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertNoContent();
        $this->travel(30)->hours();
        File::deleteDirectory($this->folder);
        $this->actingAs($this->owner)->postJson('/api/v1/backups/auto')->assertNoContent();
        $this->assertSame(1, Backup::count());
    }
}
