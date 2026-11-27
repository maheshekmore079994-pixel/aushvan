<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** `app:ready` is what sets a shared-hosting install up from cron when there is no terminal (D-064). */
class AppReadyTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/app-ready-'.uniqid());
        File::makeDirectory($this->dir, 0755, true);
        $this->app->useEnvironmentPath($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_refuses_without_an_env_file(): void
    {
        $this->artisan('app:ready --no-cache')
            ->expectsOutputToContain('.env is missing')
            ->assertFailed();
    }

    public function test_is_silent_when_nothing_is_needed(): void
    {
        File::put($this->dir.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");

        $this->artisan('app:ready --no-cache')
            ->doesntExpectOutputToContain('app:ready')
            ->assertSuccessful();
    }

    public function test_creates_the_application_key_when_the_env_has_none(): void
    {
        File::put($this->dir.'/.env', "APP_NAME=AUSHVAN\nAPP_KEY=\nDB_CONNECTION=sqlite\n");
        config(['app.key' => '']);

        $this->artisan('app:ready --no-cache')
            ->expectsOutputToContain('application key created')
            ->assertSuccessful();

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m', File::get($this->dir.'/.env'));
        $this->assertStringContainsString("APP_NAME=AUSHVAN\n", File::get($this->dir.'/.env'), 'the other lines are kept');
    }

    public function test_runs_only_the_pending_migrations(): void
    {
        File::put($this->dir.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
        // Undo one small migration by hand (its column and its row), as an update that ships a new migration looks.
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('preferences'));
        DB::table('migrations')->where('migration', '2026_11_27_130000_add_preferences_to_users_table')->delete();
        $this->assertFalse(Schema::hasColumn('users', 'preferences'));

        $this->artisan('app:ready --no-cache')
            ->expectsOutputToContain('1 migration(s) run')
            ->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('users', 'preferences'));
    }
}
