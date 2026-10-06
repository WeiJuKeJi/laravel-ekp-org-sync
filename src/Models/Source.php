<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Models;

use Illuminate\Database\Eloquent\Model;
use WeiJuKeJi\LaravelEkpOrgSync\Support\Tables;
use WeiJuKeJi\LaravelIam\Models\DirectorySource;

class Source extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['password', 'username', 'lease_owner'];

    protected $casts = ['password' => 'encrypted', 'enabled' => 'boolean', 'last_synced_at' => 'datetime', 'last_full_at' => 'datetime', 'next_sync_at' => 'datetime', 'lease_expires_at' => 'datetime'];

    public function getTable(): string
    {
        return Tables::name('sources');
    }

    public function directory()
    {
        return $this->belongsTo(DirectorySource::class, 'directory_source_id');
    }
}
