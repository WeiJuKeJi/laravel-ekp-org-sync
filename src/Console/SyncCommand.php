<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Console;

use Illuminate\Console\Command;
use WeiJuKeJi\LaravelEkpOrgSync\Exceptions\SyncFailure;
use WeiJuKeJi\LaravelEkpOrgSync\Services\SyncService;

class SyncCommand extends Command
{
    protected $signature = 'ekp-org:sync {source?} {--full} {--dry-run} {--due}';

    protected $description = 'Synchronize EKP organization directory; default uses an overlapping incremental cursor.';

    public function handle(SyncService $service): int
    {
        try {
            if ($this->option('due')) {
                $results = $service->due();
                $this->line(json_encode($results, JSON_UNESCAPED_UNICODE));

                return collect($results)->contains('status', 'failed') ? self::FAILURE : self::SUCCESS;
            }
            if (! ctype_digit((string) $this->argument('source'))) {
                $this->error('A numeric source ID or --due is required.');

                return self::FAILURE;
            }
            $run = $service->sync((int) $this->argument('source'), (bool) $this->option('full'), (bool) $this->option('dry-run'));
            $this->line(json_encode($run->toArray(), JSON_UNESCAPED_UNICODE));

            return in_array($run->status, ['succeeded', 'previewed'], true) ? self::SUCCESS : self::FAILURE;
        } catch (SyncFailure $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
