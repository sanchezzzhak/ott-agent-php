<?php

namespace kak\OttPhpAgent\Integration;

use kak\OttPhpAgent\Agent;

/**
 * @docs https://www.mediawiki.org/wiki/Excimer
 */
class ProfilingIntegration implements IntegrationInterface
{
    private Agent $agent;
    private ?\ExcimerProfiler $profiler = null;
    private ?\ExcimerLog $excimerLog = null;

    /**
     * @var float The sample rate (10.01ms/101 Hz)
     */
    private const SAMPLE_RATE = 0.0001;
    private const THRESHOLD_MS = 1000.0;
    private const MIN_SAMPLE_COUNT = 2;
    private const MAX_PROFILE_DURATION = 30;  // seconds

    /**
     * @var int The maximum stack depth
     */
    private const MAX_STACK_DEPTH = 128;

    private float $thresholdMs;
    private array $options = [];

    public function __construct(
        Agent $agent,
        array $options = []
    )
    {
        $this->agent = $agent;
        $this->options = $options;
    }

    private function getSampleRate(): float
    {
        return $this->options['sampleRate'] ?? self::SAMPLE_RATE;
    }

    private function getMaxStackDeep(): float
    {
        return $this->options['maxStackDepth'] ?? self::MAX_STACK_DEPTH;
    }

    public function setup(): void
    {
        if (!extension_loaded('excimer')) {
            return; // Пропускаем, если расширение не установлено
        }

        $this->initProfiler();
        register_shutdown_function([$this, 'shutdown']);
    }

    private function initProfiler(): void
    {
        $this->profiler = new \ExcimerProfiler();
        $this->profiler->setEventType(EXCIMER_REAL);   // EXCIMER_CPU,
        $this->profiler->setPeriod($this->getSampleRate());
        $this->profiler->setMaxDepth($this->getMaxStackDeep());
        $this->profiler->start();
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

    private function validateExcimerLog(): bool
    {
        if (\is_array($this->excimerLog)) {
            $sampleCount = \count($this->excimerLog);
        } else {
            $sampleCount = $this->excimerLog->count();
        }

        return self::MIN_SAMPLE_COUNT <= $sampleCount;
    }

    private function validateMaxDuration(float $duration): bool
    {
        if ($duration > self::MAX_PROFILE_DURATION) {
            return false;
        }

        return true;
    }


//    public function getFormatCollapsed(): array
//    {
//        return $this->excimerLog->formatCollapsed();
//    }

    public function shutdown(): void
    {
        $this->stop();

        $report = sprintf( "%-79s %14s %14s\n", 'Function', 'Self', 'Inclusive' );
        foreach ( $this->excimerLog->aggregateByFunction() as $id => $info ) {
            $report .= sprintf( "%-79s %14d %14d\n", $id, $info['self'], $info['inclusive'] );
        }
        dump($this->excimerLog->getSpeedscopeData());

        if (!$this->validateExcimerLog()) {
            return;
        }

        $requestDuration = $this->getRequestDuration();
        $loggedStacks = $this->prepareStacks();
        $frames = [];
        $frameHashMap = [];
        $samples = [];
        $stacks = [];
        $stackHashMap = [];
        $duration = 0;

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
            $timestamp = $stack['timestamp'] ?? 0;
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
                    } elseif (isset($frame['function'])) {  // closure_line
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
                'elapsed_ns' => (int) round($duration * 1e+9),
            ];
        }

        if (!$this->validateMaxDuration((float) $duration)) {
            return;
        }

        if ($requestDuration < self::THRESHOLD_MS) {
            return;
        }

        $this->agent->captureEvent([
            'type' => 'profile',
            'profile' => [
                'frames' => $frames,
                'samples' => $samples,
                'stacks' => $stacks,
            ],
            'duration_ms' => $requestDuration,
            'request' => $this->agent->getEventBuilder()->collectRequestData(),
        ]);

    }

    private function getRequestDuration(): float
    {
        return (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;
    }
}