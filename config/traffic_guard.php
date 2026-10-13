<?php

return [
    'enabled' => env('TRAFFIC_GUARD_ENABLED', true),
    'requests_per_minute' => 240,
    'auth_per_minute' => 20,
    'account_attempts' => 20,
    'account_window_seconds' => 600,
    'failure_threshold' => 6,
    'probe_threshold' => 5,
    'behavior_window_seconds' => 600,
    'cooldown_seconds' => 300,
];
