<?php
namespace Common\DB;

use PDO;use PDOException;use RuntimeException;

class DBConnection {
    public static function fromEnv(array $overrides = []): PDO {
        $host = $overrides['db_host'] ?? getenv('DB_HOST') ?: 'localhost';
        $user = $overrides['db_user'] ?? getenv('DB_USER') ?: '';
        $pass = $overrides['db_pass'] ?? getenv('DB_PASS') ?: '';
        $name = $overrides['db_name'] ?? (getenv('RETREE_TEST_DB') ?: (getenv('DB_NAME') ?: ''));
        $port = $overrides['db_port'] ?? getenv('DB_PORT') ?: null;
        $socket = $overrides['db_socket'] ?? getenv('DB_SOCKET') ?: null;
        return self::connect($host, $user, $pass, $name, $port, $socket);
    }

    public static function fromConfigFile(string $path, ?string $dbNameOverride = null): PDO {
        if (!is_readable($path)) {
            throw new RuntimeException("Config file not readable: $path");
        }
        $cfg = require $path;
        $host = $cfg['db_host'] ?? 'localhost';
        $user = $cfg['db_user'] ?? '';
        $pass = $cfg['db_pass'] ?? '';
        $name = $dbNameOverride ?: (getenv('RETREE_TEST_DB') ?: ($cfg['db_name'] ?? ''));
        $port = $cfg['db_port'] ?? null;
        $socket = $cfg['db_socket'] ?? null;
        return self::connect($host, $user, $pass, $name, $port, $socket);
    }

    private static function connect(string $host, string $user, string $pass, string $dbName = '', $port = null, $socket = null): PDO {
        $charset = 'utf8mb4';
        $dsn = $socket
            ? "mysql:unix_socket={$socket};dbname={$dbName};charset={$charset}"
            : ( $port ? "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}" : "mysql:host={$host};dbname={$dbName};charset={$charset}" );
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
            return $pdo;
        } catch (PDOException $e) {
            // Attempt fallback to socket when host fails (common in local dev)
            if (!$socket) {
                $fallback = getenv('DB_SOCKET') ?: '/tmp/mysql.sock';
                try {
                    $pdo = new PDO("mysql:unix_socket={$fallback};dbname={$dbName};charset={$charset}", $user, $pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                    ]);
                    return $pdo;
                } catch (PDOException $e2) { /* continue to throw original below */ }
            }
            throw $e;
        }
    }
}
