<?php

namespace kak\OttPhpAgent\Transport;

use kak\OttPhpAgent\Agent;
use RuntimeException;

class DiskTransport
{
    use SendEventTrait;

    private Agent $agent;
    private string $queueDir;
    private int $maxAgeSeconds; // Очистка файлов старше (по умолчанию 24 часа)

    public function __construct(Agent $agent, string $queueDir = null, int $maxAgeSeconds = 86400)
    {
        $this->agent = $agent;
        $this->queueDir = $queueDir ?? sys_get_temp_dir() . '/ott_agent_queue';
        $this->maxAgeSeconds = $maxAgeSeconds;

        $this->ensureQueueDir();
    }

    private function ensureQueueDir(): void
    {
        if (!is_dir($this->queueDir) && !mkdir($this->queueDir, 0755, true) && !is_dir($this->queueDir)) {
            throw new RuntimeException("Cannot create queue directory: {$this->queueDir}");
        }

        if (!is_writable($this->queueDir)) {
            throw new RuntimeException("Queue directory is not writable: {$this->queueDir}");
        }
    }

    public function enqueue(array $event): void
    {
        // Чистим старые
        $this->gc();

        // Проверяем общий размер
        $files = glob($this->queueDir . '/event_*');
        $totalSize = array_sum(array_map('filesize', array_filter($files, 'is_file')));

        $eventSize = strlen(json_encode($event));
        $compressedSize = $eventSize; // грубая оценка

        if ($totalSize + $compressedSize > $this->maxSizeBytes) {
            // Очередь переполнена — удаляем самые старые
            usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
            while (!empty($files) && $totalSize + $compressedSize > $this->maxSizeBytes) {
                $file = array_shift($files);
                $totalSize -= filesize($file);
                @unlink($file);
                @unlink($file . '.attempt');
            }
        }

        // Сохраняем новое событие
        $filename = $this->queueDir . '/event_' . gmdate('Ymd_His_') . random_int(1000, 9999) . '.json.gz';
        $compressed = gzencode(json_encode($event), $this->agent->getOption('compressed', 6));
        if ($compressed === false) {
            file_put_contents($filename . '.raw', json_encode($event));
        } else {
            file_put_contents($filename, $compressed);
        }
    }

    public function flush(): void
    {
        $files = glob($this->queueDir . '/event_*.json*');
        if (empty($files)) {
            return;
        }

        foreach ($files as $file) {
            $attempt = $this->getAttemptNumber($file);
            $delay = $this->calculateBackoffDelay($attempt);

            if ($delay > 0) {
                usleep($delay * 1000); // в миллисекундах
            }

            if ($this->sendFile($file)) {
                unlink($file);
            } else {
                $this->incrementAttempt($file);
            }
        }
    }

    private function sendFile(string $file): bool
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return false;
        }

        $isGzip = str_ends_with($file, '.gz');
        $data = $isGzip ? gzdecode($content) : $content;

        if (!$data) {
            unlink($file); // битый файл — удаляем
            return false;
        }

        $event = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            unlink($file);
            return false;
        }

        return $this->send($event);
    }



    public function gc(): void
    {
        $files = glob($this->queueDir . '/event_*');
        if (empty($files)) return;

        $cutoff = time() - $this->maxAgeSeconds;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    public function getQueueSize(): int
    {
        return count(glob($this->queueDir . '/event_*') ?: []);
    }

    private function getAttemptNumber(string $file): int
    {
        $attemptFile = $file . '.attempt';
        return is_file($attemptFile) ? (int)file_get_contents($attemptFile) : 0;
    }

    private function incrementAttempt(string $file): void
    {
        $attemptFile = $file . '.attempt';
        $attempts = $this->getAttemptNumber($file);
        file_put_contents($attemptFile, (string)($attempts + 1));
    }

    private function calculateBackoffDelay(int $attempt): int
    {
        // максимум 5 минут
        return min(1000 * (2 ** $attempt), 300000);
    }
}