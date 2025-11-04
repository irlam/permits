<?php
// config/test_database.php
// Test database configuration
// This file provides database configuration for testing environments

return [
    'driver' => getenv('TEST_DB_DRIVER') ?: 'sqlite',
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', // Ignored for SQLite
    'port' => (int) (getenv('TEST_DB_PORT') ?: 3306), // Ignored for SQLite
    'database' => getenv('TEST_DB_DATABASE') ?: ':memory:',
    'username' => getenv('TEST_DB_USERNAME') ?: '',
    'password' => getenv('TEST_DB_PASSWORD') ?: '',
    'charset' => getenv('TEST_DB_CHARSET') ?: 'utf8mb4',
    'collation' => getenv('TEST_DB_COLLATION') ?: 'utf8mb4_unicode_ci',
];
