<?php

return [
    'host' => env('ELASTIC_HOST', 'http://elasticsearch:9200'),
    'index' => env('ELASTIC_INDEX', 'laws'),
    'timeout' => (float) env('ELASTIC_TIMEOUT', 1.5),
    'connect_timeout' => (float) env('ELASTIC_CONNECT_TIMEOUT', 0.5),
    'retries' => (int) env('ELASTIC_RETRIES', 0),
    'failure_cooldown_seconds' => (int) env('ELASTIC_FAILURE_COOLDOWN_SECONDS', 15),
    'file_heavy_details' => (bool) env('LAW_SEARCH_FILE_HEAVY_DETAILS', false),
];
