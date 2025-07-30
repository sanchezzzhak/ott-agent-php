SDK PHP Agent for OTT 
-

### Features:

* Monitoring php errors.
* Tracking custom events.
* Tracking performance.

### Install 

```bash
composer requare kak/ott-agent-php
```

### Uses (register agent)

add to bootstrap.php or top level code

```php


use kak\OttPhpAgent\Agent;

$agent = Agent::instance([
    'api_key' => 'your-secret-api-key',
    'server_url' => 'http://localhost:8081',
    'environment' => 'production',
    'release' => '1.0.0',
    'sample_rate' => 0.5, // 50%, 1 = 100%
    'max_request_body_size' => 'small',
]);
$agent->ready();
```

Install php-excimer for performance analyze
#### Ubuntu/Debian:
```bash
sudo apt-get install php8.3-excimer
```
