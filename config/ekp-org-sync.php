<?php

return [
    'enabled' => false,
    'table_prefix' => 'ekp_org_',
    'route_prefix' => 'api/ekp-org-sync',
    'page_size' => 200,
    'max_records' => 100000,
    'max_pages' => 2000,
    'timeout' => 45,
    'overlap_minutes' => 60,
    'interval_minutes' => 5,
    'full_interval_hours' => 24,
    'lease_seconds' => 900,
    'max_missing_ratio' => 0.1,
    'timezone' => 'Asia/Shanghai',
    'schedule_enabled' => true,
];
