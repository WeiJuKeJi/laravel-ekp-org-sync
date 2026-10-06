<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use WeiJuKeJi\LaravelEkpOrgSync\Exceptions\SyncFailure;
use WeiJuKeJi\LaravelEkpOrgSync\Models\Source;
use WeiJuKeJi\LaravelEkpOrgSync\Models\SyncRun;
use WeiJuKeJi\LaravelEkpOrgSync\Services\SyncService;
use WeiJuKeJi\LaravelEkpOrgSync\Tests\TestCase;
use WeiJuKeJi\LaravelIam\Models\DirectoryEntry;
use WeiJuKeJi\LaravelIam\Models\DirectorySource;
use WeiJuKeJi\LaravelIam\Models\ExternalIdentity;
use WeiJuKeJi\LaravelIam\Models\Role;
use WeiJuKeJi\LaravelIam\Models\User;
use WeiJuKeJi\LaravelIam\Services\Directory\IdentityLinker;

class DirectorySyncTest extends TestCase
{
    private Source $source;

    private array $remote;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $directory = DirectorySource::create(['instance_id' => (string) Str::uuid(), 'provider' => 'ekp', 'name' => 'Synthetic EKP']);
        $this->source = Source::create(['directory_source_id' => $directory->id, 'url' => 'https://ekp.example.test', 'username' => 'synthetic', 'password' => 'SYNTHETIC-SECRET', 'interval_minutes' => 5, 'full_interval_hours' => 24]);
        $this->remote = [
            $this->record('company', 'org'),
            $this->record('department', 'dept', 'company'),
            $this->record('person', 'person', 'department'),
            $this->record('position', 'post', 'department'),
            $this->record('group', 'group'),
        ];
        $this->fakeRemote();
    }

    private function record(string $id, string $type, ?string $parent = null): array
    {
        return ['id' => $id, 'type' => $type, 'name' => 'Synthetic '.$id, 'parent' => $parent, 'isAvailable' => true, 'alterTime' => '2026-10-01 12:00:00.000', 'loginName' => $type === 'person' ? 'synthetic-person' : null, 'password' => 'DO-NOT-PERSIST', 'customProps' => ['private' => 'DO-NOT-PERSIST-CUSTOM']];
    }

    private function fakeRemote(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), 'getElementsBaseInfo')) {
                $records = array_map(fn ($r) => ['id' => $r['id'], 'type' => $r['type'], 'name' => $r['name']], $this->remote);

                return Http::response(['returnState' => 2, 'count' => count($records), 'message' => json_encode($records)]);
            }
            $begin = $request['beginTimeStamp'];
            $eligible = array_values(array_filter($this->remote, fn ($row) => $row['alterTime'] > $begin));
            usort($eligible, fn ($a, $b) => $a['alterTime'] <=> $b['alterTime']);
            if (count($eligible) > $request['count']) {
                $end = $eligible[$request['count'] - 1]['alterTime'];
                $eligible = array_values(array_filter($eligible, fn ($row) => $row['alterTime'] <= $end));
            }
            $timestamp = $eligible ? end($eligible)['alterTime'] : '2099-01-01 00:00:00.000';

            return Http::response(['returnState' => 2, 'count' => count($eligible), 'message' => json_encode($eligible), 'timeStamp' => $timestamp]);
        });
    }

    private function sync(bool $full = false, bool $dryRun = false): SyncRun
    {
        return app(SyncService::class)->sync($this->source->id, $full, $dryRun);
    }

    public function test_all_types_are_imported_without_creating_accounts_or_storing_sensitive_fields(): void
    {
        $run = $this->sync(true);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(5, DirectoryEntry::count());
        $this->assertSame(1, ExternalIdentity::count());
        $this->assertSame(0, User::count());
        $this->assertSame(5, $run->summary['applied']['created']);
        $department = DirectoryEntry::where('external_id', 'department')->first();
        $this->assertSame('company', $department->parent->external_id);
        $stored = json_encode(DirectoryEntry::all()->toArray());
        $this->assertStringNotContainsString('DO-NOT-PERSIST', $stored);
        $this->assertStringNotContainsString('SYNTHETIC-SECRET', json_encode($this->source->toArray()));
        $this->assertNotSame('SYNTHETIC-SECRET', DB::table('ekp_org_sources')->value('password'));
    }

    public function test_opt_in_projects_users_and_login_eligibility_without_granting_access(): void
    {
        config(['iam.directory.accounts.enabled' => true, 'iam.directory.accounts.source_ids' => [$this->source->directory_source_id]]);
        $this->remote[2]['canLogin'] = false;
        $run = $this->sync(true);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(1, $run->summary['applied']['accounts']['created']);
        $user = User::where('username', 'synthetic-person')->firstOrFail();
        $this->assertSame('inactive', $user->status);
        $this->assertSame(0, $user->roles()->count());
        $this->assertTrue(ExternalIdentity::first()->provisioned);
        $this->assertFalse(DirectoryEntry::where('external_id', 'person')->first()->links['can_login']);
        $this->sync(true);
        $this->assertSame(1, User::count());
        $this->assertSame($user->id, ExternalIdentity::first()->user_id);
    }

    public function test_opt_in_directory_dry_run_never_provisions_accounts(): void
    {
        config(['iam.directory.accounts.enabled' => true, 'iam.directory.accounts.source_ids' => [$this->source->directory_source_id]]);
        $run = $this->sync(true, true);
        $this->assertSame('previewed', $run->status);
        $this->assertSame(0, User::count());
        $this->assertSame(0, ExternalIdentity::count());
    }

    public function test_repeated_full_sync_is_idempotent_and_keeps_local_ids(): void
    {
        $this->sync(true);
        $before = DirectoryEntry::pluck('id', 'external_id')->all();
        $run = $this->sync(true);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame($before, DirectoryEntry::pluck('id', 'external_id')->all());
        $this->assertSame(5, $run->summary['applied']['unchanged']);
        $this->assertSame(1, ExternalIdentity::count());
    }

    public function test_incremental_rename_move_and_omitted_contact_clear_preserve_identity(): void
    {
        $this->remote[2]['email'] = 'synthetic@example.test';
        $this->remote[2]['mobileNo'] = '123';
        $this->sync(true);
        $personId = DirectoryEntry::where('external_id', 'person')->value('id');
        $this->remote[2]['name'] = 'Renamed person';
        $this->remote[2]['parent'] = 'company';
        $this->remote[2]['alterTime'] = '2026-10-02 12:00:00.000';
        unset($this->remote[2]['email'], $this->remote[2]['mobileNo']);
        $run = $this->sync();
        $person = DirectoryEntry::find($personId);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('Renamed person', $person->name);
        $this->assertSame('company', $person->parent->external_id);
        $this->assertNull($person->contact_email);
        $this->assertNull($person->phone);
        $this->assertSame('2026-10-02 12:00:00.000', $this->source->refresh()->cursor);
    }

    public function test_all_same_millisecond_ties_are_kept_and_empty_page_does_not_advance_cursor(): void
    {
        config(['ekp-org-sync.page_size' => 2, 'ekp-org-sync.overlap_minutes' => 0]);
        $run = $this->sync(true);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(5, $run->summary['received']);
        $this->assertSame(2, $run->summary['pages']);
        $this->sync();
        $this->assertSame('2026-10-01 12:00:00.000', $this->source->refresh()->cursor);
    }

    public function test_dry_run_has_no_directory_or_cursor_writes(): void
    {
        $run = $this->sync(true, true);
        $this->assertSame('previewed', $run->status);
        $this->assertSame(0, DirectoryEntry::count());
        $this->assertNull($this->source->refresh()->cursor);
        $this->assertNull($this->source->last_full_at);
    }

    public function test_business_failure_keeps_cursor_and_directory_and_redacts_remote_error(): void
    {
        $this->sync(true);
        $before = DirectoryEntry::get()->toJson();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['returnState' => 1, 'message' => 'SYNTHETIC-SECRET'])]);
        $run = $this->sync();
        $this->assertSame('failed', $run->status);
        $this->assertSame('business_failure', $run->failure);
        $this->assertSame($before, DirectoryEntry::get()->toJson());
        $this->assertSame('2026-10-01 12:00:00.000', $this->source->refresh()->cursor);
        $this->assertNull($this->source->lease_owner);
    }

    public function test_incomplete_manifest_prevents_full_application(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $records = str_ends_with($request->url(), 'getElementsBaseInfo') ? $this->remote : array_slice($this->remote, 0, 4);

            return Http::response(['returnState' => 2, 'count' => count($records), 'message' => json_encode($records), 'timeStamp' => '2026-10-01 12:00:00.000']);
        });
        $run = $this->sync(true);
        $this->assertSame('full_manifest_mismatch', $run->failure);
        $this->assertSame(0, DirectoryEntry::count());
    }

    public function test_orphan_or_cycle_rolls_back_the_entire_application(): void
    {
        $this->remote[1]['parent'] = 'person';
        $this->remote[2]['parent'] = 'department';
        $run = $this->sync(true);
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, DirectoryEntry::count());
        $this->assertNull($this->source->refresh()->cursor);
    }

    public function test_large_absence_is_blocked_and_approved_small_absence_is_soft_only(): void
    {
        $this->sync(true);
        $this->remote = array_values(array_filter($this->remote, fn ($row) => $row['id'] !== 'person'));
        $run = $this->sync(true);
        $this->assertSame('failed', $run->status);
        $this->assertTrue(DirectoryEntry::where('external_id', 'person')->first()->is_present);
        config(['ekp-org-sync.max_missing_ratio' => 0.3]);
        $run = $this->sync(true);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(5, DirectoryEntry::count());
        $this->assertFalse(DirectoryEntry::where('external_id', 'person')->first()->is_present);
    }

    public function test_inactive_person_can_have_no_parent_and_can_login_flag_does_not_enable_it(): void
    {
        $this->remote[2]['isAvailable'] = false;
        $this->remote[2]['parent'] = null;
        $this->remote[2]['canLogin'] = true;
        $this->assertSame('succeeded', $this->sync(true)->status);
        $this->assertFalse(DirectoryEntry::where('external_id', 'person')->first()->is_available);
        $this->assertSame(0, User::count());
    }

    public function test_lease_prevents_parallel_sync_and_expired_run_is_recoverable(): void
    {
        $this->source->update(['lease_owner' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute()]);
        try {
            $this->sync();
            $this->fail('Concurrent run must be rejected');
        } catch (SyncFailure $error) {
            $this->assertSame('source_busy_or_disabled', $error->getMessage());
        }
        $this->source->update(['lease_expires_at' => now()->subMinute()]);
        $old = SyncRun::create(['id' => (string) Str::uuid(), 'source_id' => $this->source->id, 'mode' => 'full', 'status' => 'running', 'started_at' => now()->subMinutes(20)]);
        $this->assertSame('succeeded', $this->sync()->status);
        $this->assertSame('abandoned', $old->refresh()->status);
    }

    public function test_managed_identity_deactivation_revokes_tokens_but_preserves_password_and_roles(): void
    {
        $this->sync(true);
        $user = User::create(['name' => 'Local', 'username' => 'local', 'email' => 'local@example.test', 'password' => 'LocalPassword123', 'status' => 'active']);
        $user->assignRole('directory-reader');
        $password = $user->password;
        $user->createToken('synthetic');
        ExternalIdentity::where('directory_entry_id', DirectoryEntry::where('external_id', 'person')->value('id'))->update(['user_id' => $user->id, 'manages_access' => true]);
        $this->remote[2]['isAvailable'] = false;
        $this->remote[2]['alterTime'] = '2026-10-02 12:00:00.000';
        $this->assertSame('succeeded', $this->sync()->status);
        $this->assertSame('inactive', $user->refresh()->status);
        $this->assertSame($password, $user->password);
        $this->assertTrue($user->hasRole('directory-reader'));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_scheduler_runs_due_sources_and_selects_periodic_full_reconciliation(): void
    {
        $results = app(SyncService::class)->due();
        $this->assertSame('succeeded', $results[0]['status']);
        $this->assertSame([], app(SyncService::class)->due());
        $this->source->refresh()->update(['next_sync_at' => now()->subMinute(), 'last_full_at' => now()->subHours(25)]);
        $results = app(SyncService::class)->due();
        $this->assertSame('full', SyncRun::find($results[0]['run_id'])->mode);
    }

    public function test_preview_checks_tree_and_reports_planned_changes_without_writes(): void
    {
        $run = $this->sync(true, true);
        $this->assertSame(5, $run->summary['planned']['created']);
        $this->remote[1]['parent'] = 'person';
        $run = $this->sync(true, true);
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, DirectoryEntry::count());
        $this->assertNull($this->source->refresh()->cursor);
    }

    public function test_absent_managed_person_revokes_access_and_does_not_auto_reactivate(): void
    {
        $this->sync(true);
        $person = DirectoryEntry::where('external_id', 'person')->first();
        $user = User::create(['name' => 'Local', 'username' => 'local', 'email' => 'local@example.test', 'password' => 'Password123', 'status' => 'active']);
        app(IdentityLinker::class)->bind($person->id, $user->id, true);
        $user->createToken('synthetic');
        $oldRemote = $this->remote;
        $this->remote = array_values(array_filter($this->remote, fn ($row) => $row['id'] !== 'person'));
        config(['ekp-org-sync.max_missing_ratio' => 0.3]);
        $this->assertSame('succeeded', $this->sync(true)->status);
        $this->assertSame('inactive', $user->refresh()->status);
        $this->assertSame(0, $user->tokens()->count());
        $this->remote = $oldRemote;
        $this->assertSame('succeeded', $this->sync(true)->status);
        $this->assertTrue($person->refresh()->is_present);
        $this->assertSame('inactive', $user->refresh()->status);
    }

    public function test_linker_rejects_rebinding_duplicate_access_owners_and_protected_accounts(): void
    {
        $this->remote[] = $this->record('person2', 'person', 'department');
        $this->sync(true);
        $person = DirectoryEntry::where('external_id', 'person')->first();
        $person2 = DirectoryEntry::where('external_id', 'person2')->first();
        $user = User::create(['name' => 'Local', 'username' => 'local', 'email' => 'local@example.test', 'password' => 'Password123']);
        $other = User::create(['name' => 'Other', 'username' => 'other', 'email' => 'other@example.test', 'password' => 'Password123']);
        $linker = app(IdentityLinker::class);
        $linker->bind($person->id, $user->id, true);
        foreach ([[$person->id, $other->id], [$person2->id, $user->id]] as [$entryId, $userId]) {
            try {
                $linker->bind($entryId, $userId, true);
                $this->fail('Invalid identity binding must fail');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
        Role::create(['name' => 'super-admin', 'guard_name' => 'sanctum']);
        $other->assignRole('super-admin');
        try {
            $linker->bind($person2->id, $other->id, true);
            $this->fail('Protected identity binding must fail');
        } catch (\RuntimeException) {
            $this->assertNull($person2->identity->user_id);
        }
        // Protection still applies if a managed local user receives a protected role later.
        $user->assignRole('super-admin');
        $this->remote[2]['isAvailable'] = false;
        $run = $this->sync(true);
        $this->assertSame('failed', $run->status);
        $this->assertTrue($person->refresh()->is_available);
        $this->assertSame('active', $user->refresh()->status);
    }

    public function test_page_request_requires_manage_permission_and_is_consumed_as_full(): void
    {
        $this->sync(true);
        $user = User::create(['name' => 'Operator', 'username' => 'operator', 'email' => 'operator@example.test', 'password' => 'Password123']);
        $url = '/api/v1/ekp-org-sync/sources/'.$this->source->id.'/sync';
        $this->actingAs($user, 'sanctum')->postJson($url, ['mode' => 'full'])->assertForbidden();
        $user->givePermissionTo('ekp-org-sync.sources.manage');
        $this->postJson($url, ['mode' => 'full'])->assertStatus(202);
        $this->assertSame('full', $this->source->refresh()->requested_mode);
        $results = app(SyncService::class)->due();
        $this->assertSame('full', SyncRun::find($results[0]['run_id'])->mode);
        $this->assertNull($this->source->refresh()->requested_mode);
        $this->source->update(['lease_owner' => 'other-worker', 'lease_expires_at' => now()->addMinute()]);
        $this->postJson($url, ['mode' => 'full'])->assertStatus(409);
    }

    public function test_lost_lease_never_applies_or_clears_new_owner(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function () {
            $this->source->update(['lease_owner' => 'new-owner', 'lease_expires_at' => now()->addMinutes(15)]);

            return Http::response(['returnState' => 2, 'count' => 0, 'message' => '[]']);
        });
        $run = $this->sync(true);
        $this->assertSame('lease_lost', $run->failure);
        $this->assertSame(0, DirectoryEntry::count());
        $this->assertSame('new-owner', $this->source->refresh()->lease_owner);
        $this->assertNull($this->source->cursor);
    }

    public function test_invalid_cursor_initialization_releases_lease(): void
    {
        $this->source->update(['last_full_at' => now(), 'cursor' => 'invalid']);
        try {
            $this->sync();
            $this->fail('Invalid cursor must fail');
        } catch (SyncFailure $error) {
            $this->assertSame('initialization_failure', $error->getMessage());
        }
        $this->assertNull($this->source->refresh()->lease_owner);
    }

    public function test_redirect_and_invalid_json_do_not_expose_response_or_write_directory(): void
    {
        foreach ([Http::response('SYNTHETIC-SECRET', 302), Http::response(['returnState' => 2, 'count' => 1, 'message' => '{SYNTHETIC-SECRET'])] as $response) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => $response]);
            $run = $this->sync(true);
            $this->assertSame('failed', $run->status);
            $this->assertStringNotContainsString('SYNTHETIC-SECRET', $run->toJson());
            $this->assertSame(0, DirectoryEntry::count());
            $this->assertNull($this->source->refresh()->cursor);
        }
    }

    public function test_directory_filters_include_descendants_and_missing_people_and_literal_search(): void
    {
        $this->remote[2]['name'] = 'Synthetic%_! person';
        $this->sync(true);
        $user = User::create(['name' => 'Reader', 'username' => 'reader', 'email' => 'reader@example.test', 'password' => 'Password123']);
        $user->givePermissionTo('iam.directory.view');
        $root = DirectoryEntry::where('external_id', 'company')->value('id');
        $url = '/api/v1/iam/directory/entries';
        $this->actingAs($user, 'sanctum')->getJson($url.'?source_id='.$this->source->directory_source_id.'&organization_id='.$root.'&type=person&active=1')
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($url.'?search='.urlencode('%_!'))->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($url.'?search=Nonexistent')->assertOk()->assertJsonPath('data.total', 0);
        $this->remote = array_values(array_filter($this->remote, fn ($row) => $row['id'] !== 'person'));
        config(['ekp-org-sync.max_missing_ratio' => 0.3]);
        $this->sync(true);
        $this->getJson($url.'?type=person&active=0')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($url.'?type=person&active=1')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_directory_api_requires_permission_and_does_not_expose_credentials(): void
    {
        $this->sync(true);
        $user = User::create(['name' => 'Reader', 'username' => 'reader', 'email' => 'reader@example.test', 'password' => 'Password123']);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/iam/directory/entries')->assertForbidden();
        $user->givePermissionTo(['iam.directory.view', 'ekp-org-sync.sources.view']);
        $this->getJson('/api/v1/iam/directory/entries?type=person')->assertOk()->assertJsonPath('data.total', 1);
        $response = $this->getJson('/api/v1/ekp-org-sync/sources')->assertOk();
        $this->assertStringNotContainsString('SYNTHETIC-SECRET', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
    }
}
