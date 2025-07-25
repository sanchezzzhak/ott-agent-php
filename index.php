<?php

include_once "vendor/autoload.php";

use kak\OttPhpAgent\{
    Agent,
    Integration\ProfilingIntegration
};


$agent = Agent::instance([
    'api_key' => 'your-secret-api-key',
    'server_url' => 'http://localhost:8081',
    'environment' => 'production',
    'release' => '1.0.0',
    'sample_rate' => 0.5, // 50%, 1 = 100%
    'max_request_body_size' => 'small',
]);
$agent->getManager()->add(new ProfilingIntegration($agent));
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

// Пример использования
echo "Сгенерированный пароль: " . generatePassword(16, true) . "\n";

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

$testPass = "MyP@ssw0rd!";
echo "Уровень сложности пароля '$testPass': " . checkPasswordStrength($testPass) . "\n";

// Perform some CPU-intensive operations
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
$tempFile = $this->tempDir . '/test.txt';
file_put_contents($tempFile, str_repeat('x', 10000));
$content = file_get_contents($tempFile);
unlink($tempFile);