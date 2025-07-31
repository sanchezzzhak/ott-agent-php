<?php

include_once "vendor/autoload.php";

use kak\OttPhpAgent\Agent;
$agent = Agent::instance([
    'api_key' => 'your-secret-api-key',
    'server_url' => 'http://localhost:8081',
    'environment' => 'production',
    'release' => '1.0.0',
    'slow_request' => 20,
    'sample_rate' => 1, // 50%, 1 = 100%
    'max_request_body_size' => 'small',
    'profile_sample_rate' => 0.0001,
    'profile_max_duration' => 30,
    'compressed' => 6,
    'profile' => true,
    'transport' => 'disk',
    'debug' => true,
    'disk_queue_dir' => __DIR__ . '/q'
]);
$agent->ready();