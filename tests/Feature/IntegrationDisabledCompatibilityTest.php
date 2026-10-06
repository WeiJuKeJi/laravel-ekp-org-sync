<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use WeiJuKeJi\LaravelEkpOrgSync\Tests\TestCase;

class IntegrationDisabledCompatibilityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('iam.directory.enabled', false);
        $app['config']->set('ekp-org-sync.enabled', false);
    }

    public function test_disabled_modules_add_no_tables_routes_or_schedule(): void
    {
        $this->assertFalse((require __DIR__.'/../../config/ekp-org-sync.php')['enabled']);
        $this->assertFalse(Schema::hasTable('ekp_org_sources'));
        $this->assertFalse(Schema::hasTable('iam_directory_entries'));
        $this->getJson('/api/v1/ekp-org-sync/sources')->assertNotFound();
        $this->getJson('/api/v1/iam/directory/entries')->assertNotFound();
        $this->assertSame([], app(Schedule::class)->events());
    }
}
