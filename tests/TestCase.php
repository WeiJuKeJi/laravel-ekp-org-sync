<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kalnoy\Nestedset\NestedSetServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Base;
use Spatie\Permission\PermissionServiceProvider;
use WeiJuKeJi\LaravelEkpOrgSync\EkpOrgSyncServiceProvider;
use WeiJuKeJi\LaravelIam\IamServiceProvider;
use WeiJuKeJi\LaravelIam\Models\Role;
use WeiJuKeJi\LaravelIam\Models\User;

abstract class TestCase extends Base
{
    private string $isolatedDatabase;

    protected function getPackageProviders($app): array
    {
        return [IamServiceProvider::class, EkpOrgSyncServiceProvider::class, SanctumServiceProvider::class, PermissionServiceProvider::class, NestedSetServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->isolatedDatabase = tempnam(sys_get_temp_dir(), 'ekp-package-test-');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => $this->isolatedDatabase, 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('iam.directory.enabled', true);
        $app['config']->set('iam.route_prefix', 'api/v1/iam');
        $app['config']->set('iam.enforcement.mode', 'enforce');
        $app['config']->set('ekp-org-sync.enabled', true);
        $app['config']->set('ekp-org-sync.route_prefix', 'api/v1/ekp-org-sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.guards.sanctum', ['driver' => 'sanctum', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => User::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== $this->isolatedDatabase) {
            throw new \RuntimeException('Package test isolation mismatch');
        }
        fwrite(STDERR, "\nEKP test target: sqlite, host=null, port=null, database=".$this->isolatedDatabase."\n");
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('iam:sync-permissions');
        Role::create(['name' => 'directory-reader', 'guard_name' => 'sanctum']);
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        parent::tearDown();
        if (isset($this->isolatedDatabase) && is_file($this->isolatedDatabase)) {
            unlink($this->isolatedDatabase);
        }
    }
}
