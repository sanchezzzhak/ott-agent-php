<?php

namespace kak\OttPhpAgent\Integration;

use Excimetry\Profiler\ExcimerLog;
use Excimetry\Profiler\ExcimerProfiler;

use kak\OttPhpAgent\Agent;

class ProfilingIntegration implements IntegrationInterface
{
    private Agent $agent;
    private ?ExcimerProfiler $profiler = null;

    private float $thresholdMs;

    public function __construct(Agent $agent, float $thresholdMs = 1000.0)
    {
        $this->agent = $agent;
        $this->thresholdMs = $thresholdMs;
    }

    public function setup(): void
    {
        if (!extension_loaded('excimer')) {
            return; // Пропускаем, если расширение не установлено
        }

        $this->profiler = new ExcimerProfiler();
        $this->profiler->setPeriod(0.001);
        $this->profiler->start();

        register_shutdown_function([$this, 'shutdown']);
    }


    public function start(): void
    {
        $this->profiler?->start();
    }

    public function stop(): ?array
    {
        if (!$this->profiler) {
            return null;
        }

        $this->profiler->stop();
        $result = $this->profiler->getLog();
        dump($result->getParsedLog(), $result->getSpeedscopeData());



        return []; //$this->formatCallTree($log);
    }

    public function shutdown(): void
    {
        $profile = $this->stop();
        $duration = $this->getRequestDuration();

        // Отправляем профиль, если запрос был долгим
        if ($profile && $duration > $this->thresholdMs) {
            $this->agent->captureEvent([
                'type' => 'profile',
                'profile' => $profile,
                'duration_ms' => $duration,
                'request' => $this->agent->getEventBuilder()->collectRequestData(),
            ]);
        }
    }

    private function getRequestDuration(): float
    {
        return (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;
    }

    /**
     * @param ExcimerLog[] $frames
     * @return array
     */
    private function formatCallTree(array $frames): array
    {
        $tree = [];
        foreach ($frames as $frame) {
            $tree[] = [
//                'function' => $frame->getFunctionName(),
//                'file' => $frame->getFileName(),
//                'line' => $frame->getLineNumber(),
//                'weight' => $frame->getWeight(),
//                'weight_type' => $frame->getWeightType() === 0 ? 'wall_time' : 'cpu_time',
            ];
        }
        return $tree;
    }
}