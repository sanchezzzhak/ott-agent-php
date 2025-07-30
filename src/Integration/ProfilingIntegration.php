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
    private const MIN_SAMPLE_COUNT = 2;
    private const MAX_PROFILE_DURATION = 30;  // seconds

    /**
     * @var int The maximum stack depth
     */
    private const MAX_STACK_DEPTH = 128;
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
        return $this->agent->getOption('profile_sample_rate') ?? self::SAMPLE_RATE;
    }

    private function getMaxStackDeep(): float
    {
        return $this->agent->getOption('profile_max_stack_depth') ?? self::MAX_STACK_DEPTH;
    }

    private function getMaxDuration(): float|int
    {
        return $this->agent->getOption('profile_max_duration') ?? self::MAX_PROFILE_DURATION;
    }

    private function getMode(): mixed
    {
        $mode = $this->agent->getOption('profile_mode') ?? 'wall';
        return match ($mode) {
            'cpu' => EXCIMER_CPU,
            default => EXCIMER_REAL
        };
    }

    public function isSpeedScope(): bool
    {
        return (bool)($this->agent->getOption('profile_speedscope') ?? false);
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
        $this->profiler->setEventType($this->getMode());
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
        return $duration <= $this->getMaxDuration();
    }

    private function getProfileData(): array
    {
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
        return  [
            'frames' => $frames,
            'samples' => $samples,
            'stacks' => $stacks,
            'duration' => $duration
        ];
    }

//    public function getFormatCollapsed(): array
//    {
//        return $this->excimerLog->formatCollapsed();
//    }

    public function shutdown(): void
    {
        $this->stop();
        $duration = $this->getRequestDuration();

        if (!$this->validateExcimerLog()) {
            return;
        }

        $profile = $this->getProfileData();

        if (!$this->validateMaxDuration((float)$profile['duration'])) {
            return;
        }

        if ($this->isSpeedScope()) {
            $this->agent->captureEvent([
                'type' => 'speedscope',
                'mode' => $this->getMode(),
                'speedscope' => $this->excimerLog->getSpeedscopeData(),
                'duration_ms' => $duration,
                'request' => $this->agent->getEventBuilder()->collectRequestData(),
            ]);
        }

        $this->agent->captureEvent([
            'type' => 'profile',
            'mode' => $this->getMode(),
            'profile' => $profile,
            'duration_ms' => $duration,
            'request' => $this->agent->getEventBuilder()->collectRequestData(),
        ]);
    }

    private function getRequestDuration(): float
    {
        return (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;
    }
}