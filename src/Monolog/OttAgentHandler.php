<?php

namespace kak\OttPhpAgent\Monolog;

use Monolog\{
    Handler\AbstractProcessingHandler,
    Level,
    LogRecord,
    Logger
};

use kak\OttPhpAgent\Agent;

/**
$agent = Agent::instance([
    'api_key' => 'xxx',
    'server_url' => 'https://your-monitoring.com',
]);

$logger = new Logger('app');
$logger->pushHandler(new OttAgentHandler($agent, Logger::DEBUG));

$logger->error('Something went wrong', ['user_id' => 123]);
$logger->warning('Slow response', ['duration' => 2.5]);
*/
class OttAgentHandler extends AbstractProcessingHandler
{
    private Agent $agent;
    private array $levelMap = [
        Level::Debug => 'debug',
        Level::Info => 'info',
        Level::Notice => 'info',
        Level::Warning => 'warning',
        Level::Error => 'error',
        Level::Critical => 'critical',
        Level::Alert => 'critical',
        Level::Emergency => 'critical',
    ];

    public function __construct(Agent $agent, $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->agent = $agent;
    }

    protected function write(LogRecord $record): void
    {
        $level = $this->levelMap[$record->level] ?? 'info';

        $context = $record->context;
        $extra = array_diff_key($context, array_flip(['exception']));

        $payload = [
            'level' => $level,
            'message' => $record->message,
            'extra' => $extra,
        ];

        // Если есть исключение — отправляем как exception
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $this->agent->captureException($context['exception']);
            return;
        }

        $this->agent->captureEvent($payload);
    }
}