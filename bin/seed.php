<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Db\Connection;

$envPath = __DIR__ . '/..';
if (is_file($envPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($envPath)->load();
}

$pdo = Connection::get();
$files = glob(__DIR__ . '/../database/seeders/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $sql = file_get_contents($file);
    $pdo->exec($sql);
    echo 'Seeded: ' . basename($file) . "\n";
}
