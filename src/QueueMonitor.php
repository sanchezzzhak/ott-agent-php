<?php

namespace kak\OttPhpAgent;

use kak\OttPhpAgent\Transport\DiskTransport;

class QueueMonitor
{
    private string $queueDir;
    private int $maxAgeSeconds;
    private int $maxSizeBytes;

    public function __construct(string $queueDir, int $maxAgeSeconds = 86400, int $maxSizeBytes = 100 * 1024 * 1024)
    {
        $this->queueDir = $queueDir;
        $this->maxAgeSeconds = $maxAgeSeconds;
        $this->maxSizeBytes = $maxSizeBytes;
    }

    public function getStats(): array
    {
        if (!is_dir($this->queueDir)) {
            return [
                'exists' => false,
                'total_files' => 0,
                'total_size' => 0,
                'oldest' => null,
                'newest' => null,
                'size_mb' => 0,
                'is_full' => false,
                'errors' => ['Directory does not exist']
            ];
        }

        $files = glob($this->queueDir . '/event_*');
        if ($files === false) {
            return ['total_files' => 0, 'total_size' => 0, 'size_mb' => 0, 'is_full' => false];
        }

        $totalSize = 0;
        $now = time();
        $oldest = null;
        $newest = null;
        $errors = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = filemtime($file);
            $size = filesize($file);

            $totalSize += $size;

            if ($oldest === null || $mtime < $oldest) {
                $oldest = $mtime;
            }
            if ($newest === null || $mtime > $newest) {
                $newest = $mtime;
            }

            if ($now - $mtime > $this->maxAgeSeconds) {
                $errors[] = basename($file) . " is too old";
            }
        }

        $isFull = $totalSize >= $this->maxSizeBytes;

        return [
            'exists' => true,
            'total_files' => count($files),
            'total_size' => $totalSize,
            'size_mb' => round($totalSize / 1024 / 1024, 2),
            'oldest' => $oldest ? date('Y-m-d H:i:s', $oldest) : null,
            'newest' => $newest ? date('Y-m-d H:i:s', $newest) : null,
            'is_full' => $isFull,
            'max_size_mb' => round($this->maxSizeBytes / 1024 / 1024, 2),
            'errors' => $errors,
        ];
    }

    public function getRecentEvents(int $limit = 10): array
    {
        $files = glob($this->queueDir . '/event_*');
        if (empty($files)) return [];

        // Сортируем по времени модификации (свежие сначала)
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $events = [];
        $count = 0;
        foreach ($files as $file) {
            if ($count >= $limit) break;

            $content = file_get_contents($file);
            if ($content === false) continue;

            $json = str_ends_with($file, '.gz') ? gzdecode($content) : $content;
            $event = json_decode($json, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $events[] = [
                    'file' => basename($file),
                    'size' => filesize($file),
                    'modified' => date('Y-m-d H:i:s', filemtime($file)),
                    'event' => $event,
                ];
                $count++;
            }
        }

        return $events;
    }

    public function flush(): bool
    {
        $transport = new DiskTransport(
            Agent::instance(),
            $this->queueDir
        );
        try {
            $transport->flush();
            return true;
        } catch (\Throwable $e) {
            error_log('Queue flush failed: ' . $e->getMessage());
            return false;
        }
    }
}