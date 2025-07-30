<?php

namespace kak\OttPhpAgent\Transport;

use kak\OttPhpAgent\Agent;

trait SendEventTrait
{
    private function send(array $event): bool
    {
        try {

            $agent = $this->agent;

            $json = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (!$json) {
                $this->logError('Failed to encode event to JSON');
                return false;
            }

            $compressedLevel = $agent->getOption('compressed', 0);
            $isCompressed = $compressedLevel > 0;
            $compressed = $isCompressed ? gzencode($json, $compressedLevel) : $json;

            if ($isCompressed && $compressed === false) {
                $this->logError('Gzip compression failed');
                $compressed = $json; // fallback
                $encoding = 'identity';
            } else {
                $encoding = $isCompressed ? 'gzip' : 'identity';
            }

            $url = parse_url($agent->getOption('server_url'));
            if (!$url || !isset($url['host'])) {
                $this->logError('Invalid server URL: ' . $agent->getOption('server_url'));
                return $this->queueForRetry($event);
            }

            $scheme = $url['scheme'] ?? 'http';
            $host = $url['host'];
            $port = ($scheme === 'https' ? 443 : 80);
            $tls = $scheme === 'https';
            $path = rtrim($url['path'] ?? '', '/') . '/api/events';

            // Открываем соединение
            $connectionHost = $tls ? 'tls://' . $host : $host;
            $timeout = (float)($agent->getOption('timeout') ?? 5.0);

            $fp = @fsockopen($connectionHost, $port, $errno, $errstr, $timeout);
            if (!$fp) {
                $this->logError("fsockopen failed: {$errstr} (code: {$errno})");
                return $this->queueForRetry($event);
            }

            // Устанавливаем таймаут чтения
            stream_set_timeout($fp, $timeout);

            // Формируем запрос
            $request = "POST {$path} HTTP/1.1\r\n";
            $request .= "Host: {$host}\r\n";
            $request .= "Content-Type: application/json\r\n";
            $request .= "Content-Length: " . strlen($compressed) . "\r\n";
            $request .= "Content-Encoding: {$encoding}\r\n";
            $request .= "X-API-Key: " . $agent->getOption('api_key') . "\r\n";
            $request .= "Connection: close\r\n";
            $request .= "\r\n";
            $request .= $compressed;

            // Отправляем
            $written = fwrite($fp, $request);
            if ($written === false || $written < strlen($request)) {
                $this->logError("Failed to write full request (wrote {$written} of " . strlen($request) . ")");
                fclose($fp);
                return $this->queueForRetry($event);
            }

            // Попробуем прочитать HTTP-статус (необязательно, но полезно)
            $httpCode = 0;
            $response = '';
            $startLine = fgets($fp, 128);
            if ($startLine !== false && preg_match('{^HTTP/\d\.\d (\d{3})}i', $startLine, $matches)) {
                $httpCode = (int)$matches[1];
            }

            fclose($fp);

            // Проверяем статус
            if ($httpCode >= 400) {
                $this->logError("HTTP {$httpCode} received from server");
                if ($httpCode >= 500 || $httpCode === 429) {
                    // Ошибки сервера или рейт-лимит — можно повторить
                    return $this->queueForRetry($event);
                }
                // 4xx клиентские ошибки (кроме 429) — скорее всего, не исправятся
                return false;
            }
            return true;

        } catch (\JsonException $e) {
            $this->logError('JSON encode error: ' . $e->getMessage());
            return $this->queueForRetry($event);

        } catch (\Throwable $e) {
            $this->logError('Unexpected error in send(): ' . $e->getMessage());
            return $this->queueForRetry($event);
        }
    }

    private function logError(string $message): void
    {
        if ($this->agent->getOption('debug')) {
            error_log('[OttAgent] ' . $message);
        }
    }

    private function queueForRetry(array $event): bool
    {
        try {
            $transport = new DiskTransport(
                $this->agent,
                $this->agent->getOption('disk_queue_dir'),
                $this->agent->getOption('queue_max_age', 86400)
            );
            $transport->enqueue($event);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to save event to disk queue: ' . $e->getMessage());
            return false;
        }
    }


}