<?php

namespace kak\OttPhpAgent;

class EventBuilder
{
    private Agent $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    public function build(array $payload): Event
    {
        $event = [
            'timestamp' => gmdate('c'),
            'platform' => 'php',
            'sdk' => ['name' => 'ott-sdk-php', 'version' => Agent::VERSION],
            'environment' => $this->agent->getOption('environment'),
            'release' => $this->agent->getOption('release'),
            'server_name' => gethostname(),
            'contexts' => [
                ...$this->getContexts(),
                ...$this->getMemoryContext()
            ],
            'request' => $this->collectRequestData(),
        ];

        return new Event(array_merge($event, $payload));
    }

    private function getContexts(): array
    {
        return [
            'os' => [
                'name' => php_uname('s'),
                'version' => php_uname('r'),
            ],
            'runtime' => [
                'name' => 'php',
                'version' => PHP_VERSION,
            ],
        ];
    }

    public function collectRequestData(): array
    {
        $body = null;
        $bodySize = $this->agent->getOption('max_request_body_size');

        if ($bodySize !== 'none' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $rawBody = file_get_contents('php://input');
            $size = strlen($rawBody);

            if ($bodySize === 'small' && $size <= 1024) {
                $body = $rawBody;
            } elseif ($bodySize === 'medium' && $size <= 10 * 1024) {
                $body = $rawBody;
            } elseif ($bodySize === 'large') {
                $body = $rawBody;
            } else {
                $body = Agent::FILTERED;
            }
        }

        $proto = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://');
        $host = ($_SERVER['HTTP_HOST'] ?? '');
        $path = ($_SERVER['REQUEST_URI'] ?? '');

        return [
            'url' => $proto. $host. $path,
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'headers' => $this->getHeaders(),
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'data' => $body,
            'env' => [
                'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
                'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ],
        ];
    }

    private function getHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace(
                    ' ',
                    '-',
                    ucwords(strtolower(str_replace('_', ' ', substr($key, 5))))
                );
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    private function getMemoryContext(): array
    {
        return [
            'memory' => [
                'initial_kb' => Util::formatKb(memory_get_usage(true)),
                'current_kb' => Util::formatKb(memory_get_usage()),
                'peak_kb' => Util::formatKb(memory_get_peak_usage()),
                'current_usage_kb' => Util::formatKb(memory_get_usage(false)),
                'current_allocated_kb' => Util::formatKb(memory_get_usage(true)),
                'peak_usage_kb' => Util::formatKb(memory_get_peak_usage(false)),
                'peak_allocated_kb' => Util::formatKb(memory_get_peak_usage(true)),
            ]
        ];
    }
}