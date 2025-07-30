<?php
namespace kak\OttPhpAgent\Transport;

use kak\OttPhpAgent\Agent;

class AsyncTransport
{
    use SendEventTrait;

    private Agent $agent;
    private array $queue = [];

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    public function enqueue(array $event): void
    {
        $this->queue[] = $event;

        // Авто-отправка при завершении запроса
        if (count($this->queue) === 1) {
            register_shutdown_function([$this, 'flush']);
        }
    }

    public function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }

        // Завершаем HTTP-ответ, если ещё не завершён
        if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
            if (!headers_sent()) {
                http_response_code(200);
                echo '';
            }
            fastcgi_finish_request();
        }

        foreach ($this->queue as $event) {
            $this->send($event);
        }

        $this->queue = [];
    }
}