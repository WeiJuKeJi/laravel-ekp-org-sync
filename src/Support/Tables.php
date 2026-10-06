<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Support;

class Tables
{
    public static function name(string $name): string
    {
        return config('ekp-org-sync.table_prefix', 'ekp_org_').$name;
    }
}
