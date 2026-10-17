<?php
declare(strict_types=1);

$config = [];
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $config[strtolower(trim($key))] = trim(trim($value), " \t\n\r\0\x0B\"'");
    }
}
function db(): PDO {
    global $config;
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    foreach (['db_host','db_name','db_user'] as $key) {
        if (empty($config[$key])) throw new RuntimeException('Database configuration is missing.');
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'] ?? '3306', $config['db_name']);
    $pdo = new PDO($dsn, $config['db_user'], $config['db_password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
