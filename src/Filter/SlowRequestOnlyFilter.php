<?php

namespace kak\OttPhpAgent\Filter;

use kak\OttPhpAgent\Agent;

class SlowRequestOnlyFilter implements BeforeSendFilterInterface
{
    private float $thresholdMs;

    public function __construct(float $thresholdMs = 1000.0)
    {
        $this->thresholdMs = $thresholdMs;
    }

    public function apply(array $event, Agent $agent): ?array
    {
        // Предположим, что duration_ms добавлено где-то в событие (например, из транзакции)
        $duration = $event['duration_ms'] ?? $this->getRequestDuration();

        if ($duration < $this->thresholdMs) {
            return null;
        }

        return $event;
    }

    private function getRequestDuration(): float
    {
        return (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;
    }
}