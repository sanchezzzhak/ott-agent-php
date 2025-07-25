<?php

namespace kak\OttPhpAgent\Filter;

use \kak\OttPhpAgent\Agent;

class SensitiveDataFilter implements BeforeSendFilterInterface
{
    private array $sensitiveFields = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'auth',
        'credit_card',
        'ccv',
        'ssn',
    ];

    public function apply(array $event, Agent $agent): ?array
    {
        if (!isset($event['request']['data'])) {
            return $event;
        }

        $data = $event['request']['data'];
        if (!is_string($data)) {
            return $event;
        }

        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $event;
        }

        $this->redactArray($decoded);

        $event['request']['data'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        return $event;
    }

    private function redactArray(array &$data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->redactArray($value);
                $data[$key] = $value;
            } else {
                foreach ($this->sensitiveFields as $field) {
                    if (stripos($key, $field) !== false) {
                        $data[$key] = '[REDACTED]';
                        break;
                    }
                }
            }
        }
    }
}