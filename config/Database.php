<?php
declare(strict_types=1);

function sams_db_config(): array
{
    return [
        'dsn' => getenv('SAMS_DB_DSN') ?: '',
        'host' => getenv('SAMS_DB_HOST') ?: 'localhost',
        'port' => getenv('SAMS_DB_PORT') ?: '3306',

        // FIXED: your database name in phpMyAdmin is sams_db
        'name' => getenv('SAMS_DB_NAME') ?: 'sams_db',

        'user' => getenv('SAMS_DB_USER') ?: 'root',
        'pass' => getenv('SAMS_DB_PASSWORD') ?: '',
        'charset' => getenv('SAMS_DB_CHARSET') ?: 'utf8mb4',
    ];
}

function sams_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = sams_db_config();
    $appEnv = strtolower((string) (getenv('APP_ENV') ?: 'local'));

    if ($appEnv !== 'local' && $appEnv !== 'development') {
        if (($config['user'] === 'root' || $config['user'] === '') && $config['pass'] === '') {
            throw new RuntimeException('Refusing insecure DB credentials outside local/development. Set SAMS_DB_USER and SAMS_DB_PASSWORD.');
        }
    }

    if ($config['dsn'] !== '') {
        $dsn = $config['dsn'];
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );
    }

    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    } catch (PDOException $exception) {
        // If localhost fails, try 127.0.0.1 as a local-only fallback.
        if ($config['dsn'] !== '' || $config['host'] !== 'localhost') {
            throw new RuntimeException('Unable to connect to the SAMS database: ' . $exception->getMessage());
        }

        try {
            $dsn2 = sprintf(
                'mysql:host=127.0.0.1;port=%s;dbname=%s;charset=%s',
                $config['port'],
                $config['name'],
                $config['charset']
            );
            $pdo = new PDO($dsn2, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $exception2) {
            throw new RuntimeException('Unable to connect to the SAMS database: ' . $exception2->getMessage());
        }
    }

    return $pdo;
}

function sams_setting(string $key, ?string $default = null): ?string
{
    try {
        $statement = sams_pdo()->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1'
        );

        $statement->execute([
            'setting_key' => $key
        ]);

        $value = $statement->fetchColumn();

        return $value !== false ? (string) $value : $default;
    } catch (Throwable $exception) {
        return $default;
    }
}