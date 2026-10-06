<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Console;

use Illuminate\Console\Command;
use WeiJuKeJi\LaravelEkpOrgSync\Services\SyncService;

class WorkCommand extends Command
{
    protected $signature = 'ekp-org:work {--once} {--sleep=60}';

    protected $description = 'Run only EKP due synchronization locally; use the Laravel scheduler in managed deployments.';

    public function handle(SyncService $service): int
    {
        $interval = (int) $this->option('sleep');
        if ($interval < 1 || $interval > 300) {
            $this->error('Sleep must be between 1 and 300 seconds.');

            return self::FAILURE;
        }
        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function () use (&$running): void {
                $running = false;
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }
        $this->info('EKP directory worker started.');
        do {
            $results = $service->due();
            if ($results !== []) {
                $this->line(json_encode(['at' => now()->toIso8601String(), 'runs' => $results], JSON_UNESCAPED_UNICODE));
            }
            if ($this->option('once') || ! $running) {
                break;
            }
            sleep($interval);
        } while ($running);

        return self::SUCCESS;
    }
}
