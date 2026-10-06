<?php

namespace WeiJuKeJi\LaravelEkpOrgSync;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use WeiJuKeJi\LaravelEkpOrgSync\Console\SourceCommand;
use WeiJuKeJi\LaravelEkpOrgSync\Console\SyncCommand;
use WeiJuKeJi\LaravelEkpOrgSync\Console\WorkCommand;

class EkpOrgSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ekp-org-sync.php', 'ekp-org-sync');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/ekp-org-sync.php' => config_path('ekp-org-sync.php')], 'ekp-org-sync-config');
        if (! config('ekp-org-sync.enabled', false)) {
            return;
        }
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        if ($this->app->runningInConsole()) {
            $this->commands([SourceCommand::class, SyncCommand::class, WorkCommand::class]);
        }
        if (config('ekp-org-sync.schedule_enabled', true)) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('ekp-org:sync --due')->everyMinute()->withoutOverlapping(30)->timezone(config('ekp-org-sync.timezone'));
            });
        }
    }
}
