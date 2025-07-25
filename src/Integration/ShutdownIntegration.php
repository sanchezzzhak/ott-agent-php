<?php

namespace kak\OttPhpAgent\Integration;

use kak\OttPhpAgent\Agent;

class ShutdownIntegration implements IntegrationInterface
{
    private Agent $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    public function setup(): void
    {
        register_shutdown_function([$this, 'handle']);
    }

    public function handle(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $this->agent->captureEvent([
                'level' => 'fatal',
                'message' => $error['message'],
                'extra' => [
                    'error_code' => $error['type'],
                    'file' => $error['file'],
                    'line' => $error['line'],
                ]
            ]);
        }
    }
}