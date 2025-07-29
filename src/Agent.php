<?php

namespace kak\OttPhpAgent;


use InvalidArgumentException;
use kak\OttPhpAgent\{Filter\BeforeSendFilterInterface,
    Filter\RateLimitFilter,
    Filter\SensitiveDataFilter,
    Filter\SlowRequestOnlyFilter,
    Integration\ErrorIntegration,
    Integration\IntegrationManager,
    Integration\ExceptionIntegration,
    Integration\ShutdownIntegration};

class Agent
{
    public const FILTERED = '[filtered]';
    public const UNKNOWN = '<unknown>';
    public const VERSION = '0.1.0';

    private static ?self $instance = null;
    private array $options;
    private Integration\IntegrationManager $manager;
    private EventBuilder $eventBuilder;
    /** @var BeforeSendFilterInterface[]  */
    private array $beforeSendFilters = [];

    private function __construct(array $options)
    {
        $this->options = array_merge([
            'api_key' => '',
            'server_url' => '',
            'environment' => 'production',
            'release' => '',
            'sample_rate' => 0.5,
            'slow_request' => 2000.0,
            'capture_errors' => true,
            'capture_exceptions' => true,
            'max_request_body_size' => 'medium',
            'integrations' => [],
        ], $options);

        if (empty($this->options['api_key']) || empty($this->options['server_url'])) {
            throw new InvalidArgumentException('api_key and server_url are required');
        }

        $this->eventBuilder = new EventBuilder($this);
        $this->manager = new IntegrationManager();
    }

    public static function instance(array $options = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($options);
        }
        return self::$instance;
    }

    public function ready(): void
    {
        // Автоматические интеграции
        $this->manager->add(new ExceptionIntegration($this));
        $this->manager->add(new ErrorIntegration($this));
        $this->manager->add(new ShutdownIntegration($this));
        // Добавляем фильтры
        $this->addBeforeSend(
            new SensitiveDataFilter()
        );
        $this->addBeforeSend(
            new SlowRequestOnlyFilter($this->getOption('slow_request'))
        );
        $this->addBeforeSend(
            new RateLimitFilter($this->getOption('sample_rate'))
        );

        $this->manager->setupAll();
    }

    public function getOption(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    public function getManager(): IntegrationManager
    {
        return $this->manager;
    }

    public function getEventBuilder(): EventBuilder
    {
        return $this->eventBuilder;
    }

    public function captureException(\Exception $exception): void
    {
        $this->captureEvent([
            'level' => 'error',
            'exception' => [
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'stacktrace' => $this->formatStackTrace($exception->getTrace()),
            ],
            'extra' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]
        ]);
    }

    public function captureMessage(string $message, string $level = 'info'): void
    {
        $this->captureEvent([
            'level' => $level,
            'message' => $message,
        ]);
    }

    public function captureEvent(array $payload): void
    {
        $event = $this->eventBuilder->build($payload);
        $this->send($event->payload);
    }

    private function formatStackTrace(array $trace): array
    {
        $frames = [];
        foreach ($trace as $frame) {
            $frames[] = [
                'filename' => $frame['file'] ?? self::UNKNOWN,
                'lineno' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? self::UNKNOWN,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
            ];
        }
        return ['frames' => array_reverse($frames)];
    }

    public function addBeforeSend(BeforeSendFilterInterface $filter): void
    {
        $this->beforeSendFilters[] = $filter;
    }

    private function applyBeforeSend(array $event): ?array
    {
        foreach ($this->beforeSendFilters as $filter) {
            $event = $filter->apply($event, $this);
            if ($event === null) {
                return null;
            }
        }
        return $event;
    }

    public function send(?array $data): void
    {
        // Применяем хуки
        $data = $this->applyBeforeSend($data);
        if ($data === null) {
            return;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->options['server_url'], '/') . '/api/events',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->getOption('api_key'),
            ],
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        // Логирование ошибок добавить позже
    }
}