<?php

return [
    // Root-scoped: servers belong to the organisation (root team), not to a sub-team.
    'scope_type' => 'parent',

    'routing' => [
        'mode' => env('INFRA_MODE', 'path'),
        'prefix' => 'infra',
    ],

    'guard' => 'web',

    'navigation' => [
        'route' => 'infra.dashboard',
        'icon'  => 'heroicon-o-server-stack',
        'order' => 95,
    ],

    'sidebar' => [
        [
            'group' => 'Allgemein',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'route' => 'infra.dashboard',
                    'icon'  => 'heroicon-o-home',
                ],
            ],
        ],
    ],

    // A server counts as reachable when its last report is younger than this.
    'stale_after_minutes' => (int) env('INFRA_STALE_AFTER_MINUTES', 30),

    // Retention of raw data (findings are kept; they carry their own lifecycle).
    'retention' => [
        'logs_days' => (int) env('INFRA_LOG_RETENTION_DAYS', 14),
        'metrics_days' => (int) env('INFRA_METRIC_RETENTION_DAYS', 30),
    ],

    // Upper bound for log lines accepted per report (protects the DB from runaway reporters).
    'max_logs_per_report' => 500,
];
