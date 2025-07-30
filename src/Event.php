<?php

namespace kak\OttPhpAgent;

class Event
{
    public string $eventId;
    public string $timestamp;
    public array $payload = [];

    public function __construct(array $payload = [])
    {
        $this->eventId = Util::generateUUID();
        $this->timestamp = gmdate('c');
        $this->payload = $payload;
    }
}