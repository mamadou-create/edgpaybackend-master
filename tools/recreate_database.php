<?php

declare(strict_types=1);

function parseDotEnv(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException(".env not found at: {$path}");
    }

    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Failed to read .env at: {$path}");
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = substr($line, $pos + 1);

        // Remove surrounding quotes ("...") or ('...')
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $vars[$key] = $value;
    }

    return $vars;
}

$envPath = __DIR__ . '/../.env';
$vars = parseDotEnv($envPath);

$connection = $vars['DB_CONNECTION'] ?? 'mysql';
if ($connection !== 'mysql' && $connection !== 'mariadb') {
    fwrite(STDERR, "This tool supports only mysql/mariadb. Current DB_CONNECTION={$connection}\n");
    exit(2);
}

$host = $vars['DB_HOST'] ?? '127.0.0.1';
$port = (int)($vars['DB_PORT'] ?? '3306');
$database = $vars['DB_DATABASE'] ?? '';
$username = $vars['DB_USERNAME'] ?? '';
$password = $vars['DB_PASSWORD'] ?? '';

if ($database === '') {
    fwrite(STDERR, "DB_DATABASE is empty in .env\n");
    exit(2);
}

try {
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Basic hardening for identifier usage
    $safeDb = str_replace('`', '``', $database);

    $pdo->exec("DROP DATABASE IF EXISTS `{$safeDb}`");
    $pdo->exec("CREATE DATABASE `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    fwrite(STDOUT, "OK: recreated database '{$database}' on {$host}:{$port}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
