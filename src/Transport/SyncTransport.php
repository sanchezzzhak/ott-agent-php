<?php

namespace kak\OttPhpAgent\Transport;

use kak\OttPhpAgent\Agent;

class SyncTransport
{
    use SendEventTrait;

    private Agent $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    public function enqueue(array $event): void
    {
        $this->send($event);
    }
}