<?php

include_once "vendor/autoload.php";

use kak\OttPhpAgent\Agent;
$agent = Agent::instance([
    'api_key' => 'your-secret-api-key',
    'server_url' => 'http://localhost:8081',
    'environment' => 'production',
    'release' => '1.0.0',
    'slow_request' => 20,
    'sample_rate' => 1, // 50%, 1 = 100%
    'max_request_body_size' => 'small',
    'profile_sample_rate' => 0.0001,
    'profile_max_duration' => 30,
    'compressed' => 6,
    'profile' => true,
    'transport' => 'disk',
    'disk_queue_dir' => __DIR__ . '/q'
]);
$agent->ready();


// Простой генератор паролей

function generatePassword($length = 12, $includeSymbols = true) {
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numbers   = '0123456789';
    $symbols   = '!@#$%^&*()_+-=[]{}|;:,.<>?';

    $chars = $lowercase . $uppercase . $numbers;
    if ($includeSymbols) {
        $chars .= $symbols;
    }

    $password = '';
    $charLength = strlen($chars);
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $charLength - 1)];
    }

    return $password;
}

// Простая проверка сложности пароля
function checkPasswordStrength($password) {
    $strength = 0;
    if (strlen($password) >= 8) $strength++;
    if (preg_match('/[a-z]/', $password)) $strength++;
    if (preg_match('/[A-Z]/', $password)) $strength++;
    if (preg_match('/[0-9]/', $password)) $strength++;
    if (preg_match('/[^a-zA-Z0-9]/', $password)) $strength++;

    $levels = ['Очень слабый', 'Слабый', 'Средний', 'Хороший', 'Сильный'];
    return $levels[$strength] ?? 'Неизвестно';
}


// Perform some CPU-intensive operations
function runCode1() {
    for ($i = 0; $i < 1000; $i++) {
        $array = range(0, 100);
        array_map('sqrt', $array);
    }

// Perform some memory-intensive operations
    $data = [];
    for ($i = 0; $i < 1000; $i++) {
        $data[] = str_repeat('x', 100);
    }
    // Perform some I/O operations
    $tempFile = '/tmp/test.txt';
    file_put_contents($tempFile, str_repeat('x', 10000));
    $content = file_get_contents($tempFile);
}

function runCode2() {
    // Пример использования
    echo "Сгенерированный пароль: " . generatePassword(16, true) . "\n";
    $testPass = "MyP@ssw0rd!";
    echo "Уровень сложности пароля '$testPass': " . checkPasswordStrength($testPass) . "\n";
}
runCode2();
runCode1();
