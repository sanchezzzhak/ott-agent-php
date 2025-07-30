<?php
// public/queue-dashboard.php
require_once __DIR__ . '/../vendor/autoload.php';

use kak\OttPhpAgent\QueueMonitor;

$queueDir = $_ENV['OTT_QUEUE_DIR'] ?? sys_get_temp_dir() . '/ott_agent_queue';
$monitor = new QueueMonitor($queueDir, 86400, 100 * 1024 * 1024);

$stats = $monitor->getStats();
$recent = $monitor->getRecentEvents(10);

// Обработка ручной отправки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['flush'])) {
    $success = $monitor->flush();
    $message = $success ? 'Очередь успешно отправлена!' : 'Ошибка при отправке.';
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>OttAgent Queue Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-6">
<div class="max-w-6xl mx-auto">

    <h1 class="text-3xl font-bold mb-6 text-gray-800">OttAgent Queue Dashboard</h1>

    <?php if (isset($message)): ?>
        <div class="p-4 mb-6 bg-<?= $success ? 'green' : 'red' ?>-100 border border-<?= $success ? 'green' : 'red' ?>-200 rounded">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Статистика -->
    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h2 class="text-xl font-semibold mb-4">Статистика очереди</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="p-4 bg-blue-50 rounded border">
                <div class="text-sm text-gray-500">Файлов в очереди</div>
                <div class="text-2xl font-bold"><?= $stats['total_files'] ?></div>
            </div>
            <div class="p-4 bg-green-50 rounded border">
                <div class="text-sm text-gray-500">Объём (MB)</div>
                <div class="text-2xl font-bold"><?= $stats['size_mb'] ?></div>
            </div>
            <div class="p-4 bg-purple-50 rounded border">
                <div class="text-sm text-gray-500">Свежее событие</div>
                <div class="text-sm"><?= $stats['newest'] ?? '—' ?></div>
            </div>
            <div class="p-4 bg-orange-50 rounded border">
                <div class="text-sm text-gray-500">Самое старое</div>
                <div class="text-sm"><?= $stats['oldest'] ?? '—' ?></div>
            </div>
        </div>

        <?php if ($stats['is_full']): ?>
            <div class="mt-4 p-3 bg-red-100 border border-red-200 rounded text-red-700">
                ⚠️ Очередь превысила максимальный размер (<?= $stats['max_size_mb'] ?> MB)
            </div>
        <?php endif; ?>

        <?php if (!empty($stats['errors'])): ?>
            <div class="mt-4 p-3 bg-yellow-100 border border-yellow-200 rounded">
                <strong>Предупреждения:</strong>
                <ul class="mt-1 text-sm">
                    <?php foreach ($stats['errors'] as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <!-- Управление -->
    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h2 class="text-xl font-semibold mb-4">Управление</h2>
        <form method="post">
            <button
                type="submit"
                name="flush"
                class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                Отправить очередь вручную
            </button>
        </form>
    </div>

    <!-- Последние события -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-semibold mb-4">Последние события (<?= count($recent) ?>)</h2>
        <div class="space-y-4">
            <?php foreach ($recent as $item): ?>
                <div class="border p-4 rounded bg-gray-50">
                    <div class="flex justify-between text-sm text-gray-600 mb-2">
                        <span><?= htmlspecialchars($item['file']) ?></span>
                        <span><?= $item['modified'] ?> (<?= $item['size'] ?> B)</span>
                    </div>
                    <pre class="text-xs bg-white p-2 rounded overflow-auto max-h-32">
<?= htmlspecialchars(json_encode($item['event'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?>
                    </pre>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>
</body>
</html>