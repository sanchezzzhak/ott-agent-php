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
# web/bootstrap.php

require_once __DIR__ . '/../vendor/autoload.php';
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

### Call capture methods:
```php

$agent = Agent::instance();
$agent->captureMessage("User logged in");
$agent->captureException(new \Exception("Test"));
```

### Add monolog integration

```php
// use new logger or current logger
$logger = new Logger('app');
// add handle 
$logger->pushHandler(new OttAgentHandler(Agent::instance(), Logger::DEBUG));
// test log
$logger->error('Something went wrong', ['user_id' => 123]);
$logger->warning('Slow response', ['duration' => 2.5]);
```

Install php-excimer for performance analyze
#### Ubuntu/Debian:
```bash
sudo apt-get install php8.3-excimer
```

### Cron flush-queue.php

```crontab
*/3 * * * * /usr/bin/php /path/to/flush-queue.php > /dev/null 2>&1
```

```php
#!/usr/bin/env php
<?php
// flush-queue.php

require_once __DIR__ . '/vendor/autoload.php';

use kak\OttPhpAgent\Agent;
use kak\OttPhpAgent\Transport\DiskTransport;

$agent = Agent::instance([
    'api_key' => 'your-api-key',
    'server_url' => 'https://your-monitoring-server.com',
    'disk_queue_dir' => '/var/www/ott-agent-queue',
]);

$transport = new DiskTransport($agent);
$transport->flush();

echo "Queue flushed.\n";
```