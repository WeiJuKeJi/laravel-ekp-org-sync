<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use WeiJuKeJi\LaravelEkpOrgSync\Models\Source;
use WeiJuKeJi\LaravelEkpOrgSync\Services\EkpClient;
use WeiJuKeJi\LaravelIam\Models\DirectorySource;

class SourceCommand extends Command
{
    protected $signature = 'ekp-org:source {name} {--url=} {--username=} {--password-stdin}';

    protected $description = 'Configure a new encrypted EKP source (credentials are never printed).';

    public function handle(EkpClient $client): int
    {
        if (! config('ekp-org-sync.enabled') || ! config('iam.directory.enabled')) {
            $this->error('Enable EKP sync and IAM directory first.');

            return self::FAILURE;
        }
        $url = (string) $this->option('url');
        $username = (string) $this->option('username');
        try {
            $client->validateUrl($url);
        } catch (\Throwable) {
            $this->error('Invalid HTTPS origin.');

            return self::FAILURE;
        }
        $password = $this->option('password-stdin') ? rtrim((string) fgets(STDIN), "\r\n") : $this->secret('EKP password (encrypted at rest)');
        if ($username === '' || ! is_string($password) || $password === '') {
            $this->error('Credentials are required.');

            return self::FAILURE;
        }
        $connection = DB::connection();
        $this->line(json_encode(['driver' => $connection->getDriverName(), 'host' => $connection->getConfig('host'), 'port' => $connection->getConfig('port'), 'database' => $connection->getDatabaseName()], JSON_UNESCAPED_UNICODE));
        $source = DB::transaction(function () use ($url, $username, $password): Source {
            if (DirectorySource::query()->where('provider', 'ekp')->where('name', $this->argument('name'))->exists()) {
                throw new \RuntimeException('A source with this name already exists; duplicate setup refused.');
            }
            $directory = DirectorySource::query()->create(['instance_id' => (string) Str::uuid(), 'provider' => 'ekp', 'name' => $this->argument('name')]);

            return Source::query()->create(['directory_source_id' => $directory->id, 'url' => rtrim($url, '/'), 'username' => $username, 'password' => $password, 'interval_minutes' => config('ekp-org-sync.interval_minutes'), 'full_interval_hours' => config('ekp-org-sync.full_interval_hours')]);
        });
        $this->info('Source configured: '.$source->id);

        return self::SUCCESS;
    }
}
