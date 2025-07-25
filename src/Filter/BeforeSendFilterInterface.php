<?php

namespace kak\OttPhpAgent\Filter;

use kak\OttPhpAgent\Agent;

interface BeforeSendFilterInterface
{
    /**
     * Обрабатывает событие перед отправкой
     *
     * @param array $event
     * @param Agent $agent
     * @return array|null Возвращает модифицированное событие или null, чтобы отменить отправку
     */
    public function apply(array $event, Agent $agent): ?array;
}