<?php

namespace kak\OttPhpAgent;


use InvalidArgumentException;
use kak\OttPhpAgent\{Filter\BeforeSendFilterInterface,
    Filter\MemoryUsageFilter,
    Filter\RateLimitFilter,
    Filter\SensitiveDataFilter,
    Filter\SlowRequestOnlyFilter,
    Integration\ErrorIntegration,
    Integration\IntegrationManager,
    Integration\ExceptionIntegration,
    Integration\ProfilingIntegration,
    Integration\ShutdownIntegration};

class Agent
{
    public const FILTERED = '[filtered]';
    public const UNKNOWN = '<unknown>';
    public const VERSION = '0.1.0';

    private static ?self $instance = null;
    private array $options;

    private int $memoryUsageStart;
    private int $memoryAllocatedStart;

    private Integration\IntegrationManager $manager;
    private EventBuilder $eventBuilder;
    /** @var BeforeSendFilterInterface[] */
    private array $beforeSendFilters = [];

    private function __construct(array $options)
    {
        $this->options = array_merge([
            'api_key' => '',                     // ключ для отправки данных
            'server_url' => '',                  // куда отправлять данные
            'environment' => 'production',       // это производство или разработка
            'release' => '',                     // информация о релизе проекта
            'sample_rate' => 0.5,                // отправлять 50% запросов
            'slow_request' => 2000.0,            // отправлять если код работал более 2з секунд
            'capture_errors' => true,            // отправлять ошибки
            'capture_exceptions' => true,        // отправлять исключения
            'max_request_body_size' => 'medium', // ограничить содержимое post данных
            'profile' => false,                  // включить или отключить профайл
            'profile_mode' => 'wall',            // режим профайлера
            'profile_speedscope' => false,       // отправлять speedscore
            'profile_max_stack_depth' => 128,    // максимальная глубина вложенности для кода
            'profile_sample_rate' => 0.01,       // от какого тайминга начинать собирать метрики 1ms
            'profile_max_duration' => 20,        // отправлять если время обработки скриита больше 20 sec
            'timeout' => 5,                      // таймаут отправки данных
            'high_memory_detected' => 128        // 128mb добавляет тег если использование памяти превысило значение
        ], $options);

        if (empty($this->options['api_key']) || empty($this->options['server_url'])) {
            throw new InvalidArgumentException('api_key and server_url are required');
        }

        $this->eventBuilder = new EventBuilder($this);
        $this->manager = new IntegrationManager();

        $this->memoryUsageStart = memory_get_usage(false);
        $this->memoryAllocatedStart = memory_get_usage(true);

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
        if ($this->getOption('profile')) {
            $this->manager->add(new ProfilingIntegration($this));
        }
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
        $this->addBeforeSend(
            new MemoryUsageFilter()
        );
        // Активируем все интеграции
        $this->manager->setupAll();
    }

    /**
     * Получить настройку
     * @param string $key
     * @return mixed
     */
    public function getOption(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Получить менеджер интеграции
     * @return IntegrationManager
     */
    public function getManager(): IntegrationManager
    {
        return $this->manager;
    }

    /**
     * Получить сборщик событий
     * @return EventBuilder
     */
    public function getEventBuilder(): EventBuilder
    {
        return $this->eventBuilder;
    }

    /**
     * Отправить исключение в OTT
     * @param \Exception $exception
     * @return void
     */
    public function captureException(\Exception $exception): void
    {
        $formatStackTrace = function(array $trace): array {
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
        };

        $this->captureEvent([
            'level' => 'error',
            'exception' => [
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'stacktrace' => $formatStackTrace($exception->getTrace()),
            ],
            'extra' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]
        ]);
    }

    /**
     * Отправить сообщение в OTT
     * @param string $message
     * @param string $level
     * @return void
     */
    public function captureMessage(string $message, string $level = 'info'): void
    {
        $this->captureEvent([
            'level' => $level,
            'message' => $message,
        ]);
    }

    /**
     * Отправить событие в OTT
     * @param array $payload
     * @return void
     */
    public function captureEvent(array $payload): void
    {
        $payload['contexts']['memory']['diff_usage_kb'] =
            Util::formatKb(memory_get_usage(false) - $this->memoryUsageStart);
        $payload['contexts']['memory']['diff_allocated_kb'] =
            Util::formatKb(memory_get_usage(true) - $this->memoryAllocatedStart);

        $event = $this->eventBuilder->build($payload);
        $this->send($event->payload);
    }

    /**
     * Добавить хук
     * @param BeforeSendFilterInterface $filter
     * @return void
     */
    public function addBeforeSend(BeforeSendFilterInterface $filter): void
    {
        $this->beforeSendFilters[] = $filter;
    }

    /**
     * Применить хук перед отправкой
     * @param array $event
     * @return array|null
     */
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
            CURLOPT_TIMEOUT => $this->getOption('timeout'),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        // Логирование ошибок добавить позже
    }
}