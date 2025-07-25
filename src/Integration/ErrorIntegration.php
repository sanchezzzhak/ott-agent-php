<?php

namespace kak\OttPhpAgent\Integration;

namespace kak\OttPhpAgent\Integration;

use kak\OttPhpAgent\Agent;

class ErrorIntegration implements IntegrationInterface
{
    private Agent $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    public function setup(): void
    {
        if ($this->agent->getOption('capture_errors')) {
            set_error_handler([$this, 'handle']);
        }
    }

    public function handle($errno, $errstr, $errfile, $errline): bool
    {
        if (error_reporting() === 0) {
            return false;
        }

        $levels = [
            E_ERROR => 'fatal',
            E_WARNING => 'warning',
            E_PARSE => 'error',
            E_NOTICE => 'info',
            E_CORE_ERROR => 'fatal',
            E_CORE_WARNING => 'warning',
            E_COMPILE_ERROR => 'fatal',
            E_COMPILE_WARNING => 'warning',
            E_USER_ERROR => 'error',
            E_USER_WARNING => 'warning',
            E_USER_NOTICE => 'info',
            E_STRICT => 'info',
            E_RECOVERABLE_ERROR => 'error',
            E_DEPRECATED => 'info',
            E_USER_DEPRECATED => 'info',
        ];

        $level = $levels[$errno] ?? 'error';

        $this->agent->captureEvent([
            'level' => $level,
            'message' => $errstr,
            'extra' => [
                'error_code' => $errno,
                'file' => $errfile,
                'line' => $errline,
            ]
        ]);

        return false;
    }
}