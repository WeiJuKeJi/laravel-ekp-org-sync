<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use WeiJuKeJi\LaravelEkpOrgSync\Exceptions\SyncFailure;
use WeiJuKeJi\LaravelEkpOrgSync\Models\Source;
use WeiJuKeJi\LaravelEkpOrgSync\Models\SyncRun;
use WeiJuKeJi\LaravelIam\Contracts\DirectoryWriter;

class SyncService
{
    public function __construct(private readonly EkpClient $client, private readonly Normalizer $normalizer, private readonly DirectoryWriter $writer) {}

    public function sync(int $sourceId, bool $full = false, bool $dryRun = false): SyncRun
    {
        if (! config('ekp-org-sync.enabled') || ! config('iam.directory.enabled')) {
            throw new SyncFailure('integration_disabled');
        }
        $owner = (string) Str::uuid();
        $acquired = Source::query()->whereKey($sourceId)->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->update(['lease_owner' => $owner, 'lease_expires_at' => now()->addSeconds(config('ekp-org-sync.lease_seconds'))]);
        if ($acquired !== 1) {
            throw new SyncFailure('source_busy_or_disabled');
        }
        $run = null;
        try {
            $source = Source::query()->findOrFail($sourceId);
            $full = $full || ! $source->last_full_at || $source->requested_mode === 'full';
            $begin = $full || ! $source->cursor ? null : CarbonImmutable::createFromFormat('Y-m-d H:i:s.v', $source->cursor, config('ekp-org-sync.timezone'))->subMinutes(config('ekp-org-sync.overlap_minutes'))->format('Y-m-d H:i:s.v');
            SyncRun::query()->where('source_id', $sourceId)->where('status', 'running')->update(['status' => 'abandoned', 'failure' => 'lease_expired', 'finished_at' => now()]);
            $run = SyncRun::query()->create(['id' => $owner, 'source_id' => $sourceId, 'mode' => $dryRun ? 'dry-run' : ($full ? 'full' : 'incremental'), 'status' => 'running', 'begin_cursor' => $begin, 'started_at' => now()]);
            $manifest = $full ? $this->manifest($source, $owner) : null;
            [$entries, $cursor, $pages] = $this->readUpdates($source, $owner, $begin);
            if ($full) {
                $after = $this->manifest($source, $owner);
                $actual = array_keys($entries);
                sort($actual);
                if ($manifest !== $after || array_keys($after) !== $actual) {
                    throw new SyncFailure('full_manifest_mismatch');
                }
            }
            $summary = ['received' => count($entries), 'pages' => $pages, 'coverage_verified' => $full, 'counts' => [], 'available_counts' => []];
            foreach ($entries as $entry) {
                $summary['counts'][$entry['type']] = ($summary['counts'][$entry['type']] ?? 0) + 1;
                if ($entry['is_available']) {
                    $summary['available_counts'][$entry['type']] = ($summary['available_counts'][$entry['type']] ?? 0) + 1;
                }
                if ($entry['type'] === 'person' && $entry['is_available'] && ! $entry['parent_external_id']) {
                    throw new SyncFailure('active_person_without_organization');
                }
            }
            $cursor = max($cursor ?? '', $source->cursor ?? '') ?: null;
            DB::transaction(function () use ($source, $owner, $run, $entries, $cursor, $full, $dryRun, $summary): void {
                $this->heartbeat($source, $owner);
                if ($dryRun) {
                    $summary['planned'] = $this->writer->preview($source->directory_source_id, array_values($entries), $full, config('ekp-org-sync.max_missing_ratio'));
                }
                if (! $dryRun) {
                    $summary['applied'] = $this->writer->apply($source->directory_source_id, array_values($entries), $full, $owner, config('ekp-org-sync.max_missing_ratio'));
                    $source->refresh()->update(['cursor' => $cursor, 'last_synced_at' => now(), 'last_full_at' => $full ? now() : $source->last_full_at, 'next_sync_at' => now()->addMinutes($source->interval_minutes), 'requested_mode' => null]);
                }
                $run->update(['status' => $dryRun ? 'previewed' : 'succeeded', 'end_cursor' => $cursor, 'summary' => $summary, 'finished_at' => now()]);
            }, 3);
        } catch (\Throwable $error) {
            if (! $run) {
                throw new SyncFailure('initialization_failure');
            }
            $code = $error instanceof SyncFailure ? $error->getMessage() : 'directory_apply_failure';
            $run->update(['status' => 'failed', 'failure' => $code, 'finished_at' => now()]);
            // A failed run never advances the cursor. The scheduler retries with bounded backoff.
            Source::query()->whereKey($sourceId)->where('lease_owner', $owner)->update(['next_sync_at' => now()->addMinutes($source->interval_minutes)]);
        } finally {
            Source::query()->whereKey($sourceId)->where('lease_owner', $owner)->update(['lease_owner' => null, 'lease_expires_at' => null]);
        }

        return $run->refresh();
    }

    private function heartbeat(Source $source, string $owner): void
    {
        $valid = Source::query()->whereKey($source->id)->where('lease_owner', $owner)->where('lease_expires_at', '>', now())
            ->update(['lease_expires_at' => now()->addSeconds(config('ekp-org-sync.lease_seconds'))]);
        if ($valid !== 1) {
            throw new SyncFailure('lease_lost');
        }
    }

    private function manifest(Source $source, string $owner): array
    {
        $this->heartbeat($source, $owner);
        $result = $this->client->call($source, 'getElementsBaseInfo', ['returnOrgType' => '', 'returnType' => '']);
        $manifest = [];
        foreach ($result['records'] as $row) {
            if (! isset($row['id'], $row['type'], $row['name']) || ! is_string($row['id']) || isset($manifest[$row['id']]) || ! isset(Normalizer::TYPES[$row['type']])) {
                throw new SyncFailure('invalid_manifest');
            }
            $manifest[$row['id']] = [$row['type'], $row['name']];
        }
        ksort($manifest);

        return $manifest;
    }

    private function readUpdates(Source $source, string $owner, ?string $begin): array
    {
        $entries = [];
        $cursor = $begin;
        $size = (int) config('ekp-org-sync.page_size', 200);
        if ($size < 1 || $size > 1000) {
            throw new SyncFailure('invalid_page_size');
        }
        for ($page = 1; $page <= config('ekp-org-sync.max_pages'); $page++) {
            $this->heartbeat($source, $owner);
            // Timestamp endpoint has no cached token snapshot; it returns all ties at the boundary.
            $result = $this->client->call($source, 'getUpdatedElements', ['returnOrgType' => '', 'count' => $size, 'beginTimeStamp' => $cursor ?? '']);
            if ($result['records'] === []) {
                return [$entries, $cursor, $page];
            }
            $next = $this->normalizer->timestamp($result['timestamp']);
            if ($cursor && $next <= $cursor) {
                throw new SyncFailure('non_advancing_cursor');
            }
            foreach ($result['records'] as $raw) {
                $entry = $this->normalizer->entry($raw);
                $modified = $entry['source_modified_at'];
                if (($cursor && $modified <= $cursor) || $modified > $next) {
                    throw new SyncFailure('inconsistent_timestamp_boundary');
                }
                $id = $entry['external_id'];
                if (isset($entries[$id]) && $entries[$id]['source_modified_at'] >= $modified) {
                    throw new SyncFailure('duplicate_or_out_of_order_element');
                }
                $entries[$id] = $entry;
                if (count($entries) > config('ekp-org-sync.max_records')) {
                    throw new SyncFailure('record_limit_exceeded');
                }
            }
            if (max(array_column($result['records'], 'alterTime')) !== $next) {
                throw new SyncFailure('unconfirmed_timestamp_boundary');
            }
            $cursor = $next;
            if (count($result['records']) < $size) {
                return [$entries, $cursor, $page];
            }
        }
        throw new SyncFailure('page_limit_exceeded');
    }

    public function due(): array
    {
        $results = [];
        foreach (Source::query()->where('enabled', true)->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))->get() as $source) {
            $full = ! $source->last_full_at || $source->last_full_at->lte(now()->subHours($source->full_interval_hours));
            try {
                $run = $this->sync($source->id, $full);
                $results[] = ['source_id' => $source->id, 'run_id' => $run->id, 'status' => $run->status];
            } catch (SyncFailure $error) {
                $results[] = ['source_id' => $source->id, 'status' => 'skipped', 'failure' => $error->getMessage()];
            }
        }

        return $results;
    }
}
