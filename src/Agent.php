<?php

namespace kak\OttPhpAgent;

use AllowDynamicProperties;
use InvalidArgumentException;

class Agent
{
    private const FILTERED = '[filtered]';
    private const VERSION = '0.0.1';


    private static ?self $instance = null;
    private array $options;


    private function __construct(array $options)
    {
        $this->options = array_merge([
            'api_key' => '',
            'server_url' => '',
            'environment' => 'production',
            'release' => '',
            'capture_errors' => true,
            'capture_exceptions' => true,
            'max_request_body_size' => 'medium', // 'none', 'small', 'medium', 'large'
        ], $options);

        if (empty($this->options['api_key']) || empty($this->options['server_url'])) {
            throw new InvalidArgumentException('api_key and server_url are required');
        }

        if ($this->options['capture_exceptions']) {
            set_exception_handler([$this, 'handleException']);
        }

        if ($this->options['capture_errors']) {
            set_error_handler([$this, 'handleError']);
        }

        register_shutdown_function([$this, 'handleShutdown']);
    }

    public static function init($options): ?self
    {
        if (self::$instance === null) {
            self::$instance = new self($options);
        }
        return self::$instance;
    }

    public function captureException(\Exception $exception): void
    {
        $data = $this->buildEvent([
            'level' => 'error',
            'exception' => [
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'stacktrace' => $this->formatStackTrace($exception->getTrace()),
            ],
            'extra' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]
        ]);

        $this->send($data);
    }

    public function captureMessage(string $message, string $level = 'info'): void
    {
        $data = $this->buildEvent([
            'level' => $level,
            'message' => $message,
        ]);

        $this->send($data);
    }

    public function handleError($errno, $errstr, $errfile, $errline)
    {
        // We do not process errors suppressed @
        if (error_reporting() === 0) {
            return false;
        }

        $levels = [
            E_ERROR => 'fatal',
            E_WARNING => 'warning',
            E_PARSE => 'error',
            E_NOTICE => 'info',
            E_CORE_ERROR => 'fatal',
            E_CORE_WARNING => 'warning',
            E_COMPILE_ERROR => 'fatal',
            E_COMPILE_WARNING => 'warning',
            E_USER_ERROR => 'error',
            E_USER_WARNING => 'warning',
            E_USER_NOTICE => 'info',
            E_STRICT => 'info',
            E_RECOVERABLE_ERROR => 'error',
            E_DEPRECATED => 'info',
            E_USER_DEPRECATED => 'info',
        ];

        $level = $levels[$errno] ?? 'error';

        $data = $this->buildEvent([
            'level' => $level,
            'message' => $errstr,
            'extra' => [
                'error_code' => $errno,
                'file' => $errfile,
                'line' => $errline,
            ]
        ]);

        $this->send($data);
        return false;
    }

    public function handleException($exception)
    {
        $this->captureException($exception);
        // После отправки можно вызвать default handler
        $this->restoreHandlers();
        throw $exception;
    }

    public function handleShutdown()
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $this->handleError($error['type'], $error['message'], $error['file'], $error['line']);
        }
    }

    private function buildEvent(array $payload): array
    {
        $event = [
            'event_id' => $this->generateUUID(),
            'timestamp' => gmdate('c'),
            'platform' => 'php',
            'sdk' => ['name' => 'ott-sdk-php', 'version' => self::VERSION],
            'environment' => $this->options['environment'],
            'release' => $this->options['release'],
            'server_name' => gethostname(),
            'contexts' => [
                'os' => [
                    'name' => php_uname('s'),
                    'version' => php_uname('r'),
                ],
                'runtime' => [
                    'name' => 'php',
                    'version' => PHP_VERSION,
                ],
            ],
            'request' => $this->collectRequestData(),
        ];

        return array_merge($event, $payload);
    }

    private function collectRequestData(): array
    {
        $body = null;
        $bodySize = $this->options['max_request_body_size'];

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
                $body = self::FILTERED;
            }
        }

        return [
            'url' => (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
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
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    private function formatStackTrace(array $trace): array
    {
        $frames = [];
        foreach ($trace as $frame) {
            $frames[] = [
                'filename' => $frame['file'] ?? '<unknown>',
                'lineno' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? '<unknown>',
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
            ];
        }
        return ['frames' => array_reverse($frames)];
    }

    private function generateUUID(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function send($data)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->options['server_url'], '/') . '/api/events',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->options['api_key'],
            ],
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    private function restoreHandlers(): void
    {
        restore_error_handler();
        restore_exception_handler();
    }
}