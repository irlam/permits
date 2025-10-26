<?php
declare(strict_types=1);

namespace Permits;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database connection manager
 * 
 * Provides a secure PDO connection to MySQL or SQLite databases
 * with proper configuration and error handling.
 * 
 * @package Permits
 */
final class Db
{
    /** Public for convenience DI in legacy code */
    public PDO $pdo;

    /**
     * Initialize database connection based on DB_DRIVER environment variable
     * 
     * @throws RuntimeException If database driver is unsupported or connection fails
     */
    public function __construct()
    {
        $driver = strtolower((string)($_ENV['DB_DRIVER'] ?? 'mysql'));

        // Common PDO options
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($driver === 'mysql') {
            $this->pdo = $this->connectMySql($opts);
            return;
        }

        if ($driver === 'sqlite') {
            $this->pdo = $this->connectSqlite($opts);
            return;
        }

        throw new RuntimeException("Unsupported DB_DRIVER '{$driver}'. Use 'mysql' or 'sqlite'.");
    }

    /**
     * Connect to MySQL using .env settings.
     * 
     * @param array<int,int> $opts PDO options
     * @return PDO Configured MySQL PDO connection
     * @throws RuntimeException If MySQL configuration is incomplete or connection fails
     */
    private function connectMySql(array $opts): PDO
    {
        $host     = trim((string)($_ENV['DB_HOST']     ?? '127.0.0.1'));
        $port     = (string)($_ENV['DB_PORT']          ?? '3306');
        $dbname   = trim((string)($_ENV['DB_DATABASE'] ?? ''));
        $user     = (string)($_ENV['DB_USERNAME']      ?? '');
        $pass     = (string)($_ENV['DB_PASSWORD']      ?? '');
        $charset  = (string)($_ENV['DB_CHARSET']       ?? 'utf8mb4');
        $collate  = (string)($_ENV['DB_COLLATION']     ?? 'utf8mb4_unicode_ci');
        $sqlMode  = (string)($_ENV['DB_SQL_MODE']      ?? ''); // optional; leave empty to keep server default

        if ($dbname === '' || $user === '') {
            throw new RuntimeException('MySQL config incomplete: set DB_DATABASE and DB_USERNAME in .env');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            $pdo = new PDO($dsn, $user, $pass, $opts);
        } catch (PDOException $e) {
            // Add a hint while preserving the original message
            throw new RuntimeException('MySQL connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }

        // Session-level sane defaults (safe to re-run)
        $pdo->exec("SET NAMES {$charset} COLLATE {$collate}");
        if ($sqlMode !== '') {
            // Let you override, e.g. STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION
            $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($sqlMode));
        }

        // Optional: keep time zone aligned with app timezone if provided
        // $tz = (string)($_ENV['APP_TIMEZONE'] ?? 'Europe/London');
        // $pdo->exec("SET time_zone = " . $pdo->quote($tz));

        return $pdo;
    }

    /**
     * Connect to SQLite using .env settings. Creates folder/file if missing.
     * 
     * @param array<int,int> $opts PDO options
     * @return PDO Configured SQLite PDO connection
     * @throws RuntimeException If SQLite directory/file is not writable or connection fails
     */
    private function connectSqlite(array $opts): PDO
    {
        // Default to /data/permits.sqlite under project root if not provided
        $defaultPath = \realpath(__DIR__ . '/..') . '/data/permits.sqlite';
        $path = (string)($_ENV['DB_SQLITE_PATH'] ?? $defaultPath);

        $dir = \dirname($path);

        if (!\is_dir($dir)) {
            // Best-effort create
            @\mkdir($dir, 0775, true);
        }
        if (!\is_dir($dir)) {
            throw new RuntimeException("SQLite directory does not exist and could not be created: {$dir}");
        }
        if (!\is_writable($dir)) {
            throw new RuntimeException("SQLite directory is not writable by PHP: {$dir}");
        }

        if (!\file_exists($path)) {
            // Touch the file so PDO can open it; set group-writable for shared hosting
            @\touch($path);
            @\chmod($path, 0664);
        }
        if (!\is_writable($path)) {
            throw new RuntimeException("SQLite file is not writable by PHP: {$path}");
        }

        $dsn = "sqlite:{$path}";
        try {
            $pdo = new PDO($dsn, null, null, $opts);
        } catch (PDOException $e) {
            throw new RuntimeException('SQLite connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }

        // Pragmas for decent concurrency & integrity on shared hosting
        $pdo->exec('PRAGMA journal_mode = WAL;');      // better concurrent reads
        $pdo->exec('PRAGMA synchronous = NORMAL;');    // performance tradeoff
        $pdo->exec('PRAGMA foreign_keys = ON;');       // enforce FKs

        return $pdo;
    }
}
