<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use WeiJuKeJi\LaravelEkpOrgSync\Support\Tables;
use WeiJuKeJi\LaravelIam\Support\ConfigHelper;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('sources'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('directory_source_id')->unique()->constrained(ConfigHelper::table('directory_sources'));
            $table->string('url', 500);
            $table->string('username', 200);
            $table->text('password');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('interval_minutes')->default(5);
            $table->unsignedInteger('full_interval_hours')->default(24);
            $table->string('cursor', 23)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_full_at')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->uuid('lease_owner')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create(Tables::name('runs'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('source_id')->constrained(Tables::name('sources'));
            $table->string('mode', 20);
            $table->string('status', 20);
            $table->string('begin_cursor', 23)->nullable();
            $table->string('end_cursor', 23)->nullable();
            $table->json('summary')->nullable();
            $table->string('failure', 100)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['source_id', 'started_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Sync rollback is non-destructive; restore an approved backup instead.');
    }
};
