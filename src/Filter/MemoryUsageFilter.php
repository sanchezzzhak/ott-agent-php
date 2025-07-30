<?php

namespace kak\OttPhpAgent\Filter;

use kak\OttPhpAgent\Agent;

class MemoryUsageFilter implements BeforeSendFilterInterface
{
    public function apply(array $event, Agent $agent): ?array
    {
        $peak = memory_get_peak_usage(false);
        $event['measurements']['memory_peak_kb'] = round($peak / 1024, 2);
        if ($peak > $agent->getOption('high_memory_detected')) {
            $event['tags']['memory'] = 'high';
            $event['level'] = 'warning';
        }
        return $event;
    }
}