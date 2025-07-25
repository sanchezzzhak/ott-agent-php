<?php

namespace kak\OttPhpAgent;

class Event
{
    public string $eventId;
    public string $timestamp;
    public array $payload = [];

    public static function generateUUID(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function __construct(array $payload = [])
    {
        $this->eventId = self::generateUUID();
        $this->timestamp = gmdate('c');
        $this->payload = $payload;
    }
}