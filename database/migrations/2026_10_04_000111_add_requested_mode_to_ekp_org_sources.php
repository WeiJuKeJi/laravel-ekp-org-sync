<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use WeiJuKeJi\LaravelEkpOrgSync\Support\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('sources'), function (Blueprint $table): void {
            $table->string('requested_mode', 20)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Non-destructive rollback only.');
    }
};
