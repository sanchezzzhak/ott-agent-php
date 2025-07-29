<?php

namespace kak\OttPhpAgent\Integration;

use kak\OttPhpAgent\Agent;

class ProfilingIntegration implements IntegrationInterface
{
    private Agent $agent;
    private ?\ExcimerProfiler $profiler = null;
    private ?\ExcimerLog $excimerLog = null;

    /**
     * @var float The sample rate (10.01ms/101 Hz)
     */
    private const SAMPLE_RATE = 0.0001;

    /**
     * @var int The maximum stack depth
     */
    private const MAX_STACK_DEPTH = 128;

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

        $this->profiler = new \ExcimerProfiler();
//        $this->profiler->setStartTimeStamp(microtime(true));
        $this->profiler->setEventType(EXCIMER_REAL);   // EXCIMER_CPU, //EXCIMER_CPU
        $this->profiler->setPeriod(self::SAMPLE_RATE);
        $this->profiler->setMaxDepth(self::MAX_STACK_DEPTH);
        $this->profiler->start();

        register_shutdown_function([$this, 'shutdown']);
    }


    public function start(): void
    {
        $this->profiler?->start();
    }

    public function stop(): void
    {
        if (!$this->profiler) {
            return;
        }

        $this->profiler->stop();
        $this->excimerLog = $this->profiler->flush();
    }

    private function prepareStacks(): array
    {
        $stacks = [];

        foreach ($this->excimerLog as $stack) {
            if ($stack instanceof \ExcimerLogEntry) {
                $stacks[] = [
                    'trace' => $stack->getTrace(),
                    'timestamp' => $stack->getTimestamp(),
                ];
            } else {
                $stacks[] = $stack;
            }
        }
        return $stacks;
    }

    public function formatCollapsed(): array
    {
        return $this->excimerLog->formatCollapsed();
    }

    public function shutdown(): void
    {
        $this->stop();

        $report = sprintf( "%-79s %14s %14s\n", 'Function', 'Self', 'Inclusive' );
        foreach ( $this->excimerLog->aggregateByFunction() as $id => $info ) {
            $report .= sprintf( "%-79s %14d %14d\n", $id, $info['self'], $info['inclusive'] );
        }
        dump();
        dump($this->excimerLog->getSpeedscopeData());

//        $metadata = array_merge([
//            'timestamp' => time(),
//            'period' => $this->profiler,
//            'mode' => $this->mode,
//            'php_version' => PHP_VERSION,
//            'os' => PHP_OS,
//        ], []);

        $durationGlobal = $this->getRequestDuration();
        $loggedStacks = $this->prepareStacks();
        $frames = [];
        $frameHashMap = [];

        $duration = 0;
        $samples = [];
        $stacks = [];
        $stackHashMap = [];

        $registerStack = static function (array $stack) use (&$stacks, &$stackHashMap): int {
            $stackHash = md5(serialize($stack));

            if (false === \array_key_exists($stackHash, $stackHashMap)) {
                $stackHashMap[$stackHash] = \count($stacks);
                $stacks[] = $stack;
            }

            return $stackHashMap[$stackHash];
        };

        foreach ($loggedStacks as $stack) {
            $stackFrames = [];

            foreach ($stack['trace'] as $frame) {
                $absolutePath = $frame['file'];
                $lineno = $frame['line'];

                $frameKey = "{$absolutePath}:{$lineno}";

                $frameIndex = $frameHashMap[$frameKey] ?? null;

                if (null === $frameIndex) {
                    $file = $absolutePath;
                    $module = null;

                    if (isset($frame['class'], $frame['function'])) {
                        // Class::method
                        $function = $frame['class'] . '::' . $frame['function'];
                        $module = $frame['class'];
                    } elseif (isset($frame['function'])) {
                        // {closure}
                        $function = $frame['function'];
                    } else {
                        // /index.php
                        $function = $file;
                    }

                    $frameHashMap[$frameKey] = $frameIndex = \count($frames);
                    $frames[] = [
                        'filename' => $file,
                        'abs_path' => $absolutePath,
                        'module' => $module,
                        'function' => $function,
                        'lineno' => $lineno,
                    ];
                }

                $stackFrames[] = $frameIndex;
            }

            $stackId = $registerStack($stackFrames);

            $duration = $stack['timestamp'];

            $samples[] = [
                'stack_id' => $stackId,
                'thread_id' => 0,
                'elapsed_ns' => (int) round($duration * 1e+9),
            ];
        }

        $profile = [
            'frames' => $frames,
            'samples' => $samples,
            'stacks' => $stacks,
            'duration' => $durationGlobal
        ];

        dump($profile,     debug_backtrace());
        // Отправляем профиль, если запрос был долгим
//        if ($profile && $duration > $this->thresholdMs) {
//            $this->agent->captureEvent([
//                'type' => 'profile',
//                'profile' => $profile,
//                'duration_ms' => $duration,
//                'request' => $this->agent->getEventBuilder()->collectRequestData(),
//            ]);
//        }
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