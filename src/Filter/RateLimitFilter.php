<?php

namespace kak\OttPhpAgent\Filter;

use kak\OttPhpAgent\Agent;

class RateLimitFilter implements BeforeSendFilterInterface
{
    private float $sampleRate;

    /**
     * @param float $sampleRate От 0.0 (ничего) до 1.0 (всё). Пример: 0.1 = 10%
     */
    public function __construct(float $sampleRate = 0.1)
    {
        $this->sampleRate = max(0.0, min(1.0, $sampleRate));
    }

    public function apply(array $event, Agent $agent): ?array
    {
        if ($this->sampleRate >= 1.0) {
            return $event; // всё проходит
        }

        if ($this->sampleRate <= 0.0) {
            return null; // ничего не проходит
        }

        // Случайный выбор: пропускаем с вероятностью $sampleRate
        if (mt_rand() / mt_getrandmax() >= $this->sampleRate) {
            return null;
        }

        // Добавим информацию о сэмплировании
        $event['tags']['sample_rate'] = $this->sampleRate;
        return $event;
    }
}