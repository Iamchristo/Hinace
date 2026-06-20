<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Db\Connection;
use App\Core\Db\Migrator;

$envPath = __DIR__ . '/..';
if (is_file($envPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($envPath)->load();
}

// Tests run against a dedicated database so they never touch dev/seed data.
$_ENV['DB_DATABASE'] = $_ENV['TEST_DB_DATABASE'] ?? 'hinace_test';
putenv('DB_DATABASE=' . $_ENV['DB_DATABASE']);

$pdo = Connection::get();
(new Migrator($pdo, __DIR__ . '/../database/migrations'))->run();

$pdo->exec(
    "INSERT INTO users (email, password_hash, referral_code)
     SELECT 'system@hinace.internal', '!', 'SYSTEMHOUSE'
     FROM (SELECT 1) AS t
     WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'system@hinace.internal')"
);
