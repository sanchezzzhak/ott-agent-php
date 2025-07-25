<?php

namespace kak\OttPhpAgent\Integration;

class IntegrationManager
{
    private array $integrations = [];

    public function add(IntegrationInterface $integration): void
    {
        $this->integrations[] = $integration;
    }

    public function setupAll(): void
    {
        foreach ($this->integrations as $integration) {
            $integration->setup();
        }
    }
}