<?php

namespace kak\OttPhpAgent\Integration;

use kak\OttPhpAgent\Agent;

class ExceptionIntegration implements IntegrationInterface
{
    private Agent $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    public function setup(): void
    {
        if ($this->agent->getOption('capture_exceptions')) {
            set_exception_handler([$this, 'handle']);
        }
    }

    public function handle($exception): void
    {
        $this->agent->captureException($exception);
        $this->restore();
        throw $exception;
    }

    private function restore(): void
    {
        restore_error_handler();
        restore_exception_handler();
    }
}