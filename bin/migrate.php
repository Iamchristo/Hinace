<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Db\Connection;
use App\Core\Db\Migrator;

$envPath = __DIR__ . '/..';
if (is_file($envPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($envPath)->load();
}

$migrator = new Migrator(Connection::get(), __DIR__ . '/../database/migrations');
$ran = $migrator->run();

if ($ran === []) {
    echo "No pending migrations.\n";
} else {
    foreach ($ran as $name) {
        echo "Migrated: {$name}\n";
    }
}
