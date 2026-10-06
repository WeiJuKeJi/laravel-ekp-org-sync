<?php

use Illuminate\Support\Facades\Route;
use WeiJuKeJi\LaravelEkpOrgSync\Http\SyncController;

Route::middleware(['api', 'auth:sanctum', 'permission:ekp-org-sync.sources.view'])
    ->prefix(config('ekp-org-sync.route_prefix'))->name('ekp-org-sync.sources.')
    ->group(function (): void {
        Route::get('sources', [SyncController::class, 'index'])->name('index');
        Route::get('runs', [SyncController::class, 'runs'])->name('show');
    });

Route::post(config('ekp-org-sync.route_prefix').'/sources/{source}/sync', [SyncController::class, 'store'])
    ->whereNumber('source')->middleware(['api', 'auth:sanctum', 'permission:ekp-org-sync.sources.manage'])->name('ekp-org-sync.sources.store');
