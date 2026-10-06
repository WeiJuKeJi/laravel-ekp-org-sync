<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Models;

use Illuminate\Database\Eloquent\Model;
use WeiJuKeJi\LaravelEkpOrgSync\Support\Tables;

class SyncRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['summary' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function getTable(): string
    {
        return Tables::name('runs');
    }
}
