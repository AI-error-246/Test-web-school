<?php

return [
    'enabled' => (bool) env('ASSISTANT_ENABLED', true),
    'timeout_seconds' => (int) env('ASSISTANT_TIMEOUT_SECONDS', 20),
    'requests_per_minute' => (int) env('ASSISTANT_REQUESTS_PER_MINUTE', 6),
    'daily_request_limit' => (int) env('ASSISTANT_DAILY_REQUEST_LIMIT', 100),
    'max_concurrent_requests' => (int) env('ASSISTANT_MAX_CONCURRENT_REQUESTS', 3),
];
