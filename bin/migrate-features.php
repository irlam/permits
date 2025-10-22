<?php
/**
 * Permits System - Advanced Features Database Migration
 * 
 * Description: Creates new database tables for advanced features (email, auth, settings)
 * Name: migrate-features.php
 * Last Updated: 21/10/2025 21:03:42 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Create email_queue table for notification system
 * - Create users table for authentication
 * - Create sessions table for user sessions
 * - Create settings table for application configuration
 * 
 * Usage:
 * Run from command line: php bin/migrate-features.php
 * Or via composer: composer run migrate-features
 */

// Load application bootstrap
[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

echo "Starting Advanced Features Migration...\n";
echo "========================================\n\n";

try {
    // Detect database driver
    $driver = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    echo "Database Driver: $driver\n\n";
    
    // Email Queue Table
    echo "Creating email_queue table...\n";
    if ($driver === 'mysql') {
        $db->pdo->exec("
            CREATE TABLE IF NOT EXISTS email_queue (
                id VARCHAR(36) PRIMARY KEY,
                to_email VARCHAR(255) NOT NULL,
                subject VARCHAR(500) NOT NULL,
                body TEXT NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // SQLite
        $db->pdo->exec("
            CREATE TABLE IF NOT EXISTS email_queue (
                id TEXT PRIMARY KEY,
                to_email TEXT NOT NULL,
                subject TEXT NOT NULL,
                body TEXT NOT NULL,
                status TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME
            )
        ");
        $db->pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_status ON email_queue(status)");
        $db->pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_created ON email_queue(created_at)");
    }
    echo "✓ email_queue table created successfully\n\n";
    
    // Users Table
    echo "Creating users table...\n";
    if ($driver === 'mysql') {
        $db->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id VARCHAR(36) PRIMARY KEY,
                username VARCHAR(100) UNIQUE NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) DEFAULT 'viewer',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME NULL,
                INDEX idx_username (username),
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // SQLite
        $db->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id TEXT PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'viewer',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME
            )
        ");
        $db->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_username ON users(username)");
        $db->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_email ON users(email)");
    }
    echo "✓ users table created successfully\n\n";
    
    // Sessions Table
    echo "Creating sessions table...\n";
    if ($driver === 'mysql') {
        $db->pdo->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id VARCHAR(36) PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                token VARCHAR(255) UNIQUE NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_token (token),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // SQLite
        $db->pdo->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                token TEXT UNIQUE NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        $db->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_session_token ON sessions(token)");
        $db->pdo->exec("CREATE INDEX IF NOT EXISTS idx_session_expires ON sessions(expires_at)");
    }
    echo "✓ sessions table created successfully\n\n";
    
    // Settings Table
    echo "Creating settings table...\n";
    if ($driver === 'mysql') {
        $db->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(100) PRIMARY KEY,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // SQLite
        $db->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    echo "✓ settings table created successfully\n\n";
    
    // Insert default settings
    echo "Inserting default settings...\n";
    $defaults = [
        ['theme', 'dark'],
        ['email_enabled', 'false'],
        ['smtp_host', ''],
        ['smtp_port', '587'],
        ['smtp_user', ''],
        ['smtp_from', 'noreply@permits.local'],
    ];
    
    foreach ($defaults as [$key, $value]) {
        $check = $db->pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key` = ?");
        $check->execute([$key]);
        if ($check->fetchColumn() == 0) {
            $insert = $db->pdo->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?)");
            $insert->execute([$key, $value]);
            echo "  ✓ Added setting: $key = $value\n";
        } else {
            echo "  - Setting already exists: $key\n";
        }
    }
    
    echo "\n========================================\n";
    echo "Migration completed successfully!\n";
    echo "========================================\n";
    
} catch (\Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
