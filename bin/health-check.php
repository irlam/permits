<?php
declare(strict_types=1);

/**
 * Database Health Check Script
 * 
 * Verifies database connection and table structure
 * Run this to ensure the database is properly configured
 * 
 * Usage: php bin/health-check.php
 */

echo "=== Permits System Health Check ===\n\n";

// Check database connection
echo "1. Testing database connection...\n";
try {
    [$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
    echo "   ✓ Database connection successful\n";
} catch (\Throwable $e) {
    echo "   ✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Check required tables
echo "\n2. Checking required tables...\n";
$requiredTables = [
    'form_templates',
    'forms',
    'form_events',
    'attachments',
    'users',
    'email_queue',
    'activity_log',
];

$missingTables = [];
foreach ($requiredTables as $table) {
    try {
        $stmt = $db->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        echo "   ✓ Table '{$table}' exists\n";
    } catch (\PDOException $e) {
        echo "   ✗ Table '{$table}' missing or inaccessible\n";
        $missingTables[] = $table;
    }
}

if (!empty($missingTables)) {
    echo "\n⚠ Missing tables found. Run migrations:\n";
    echo "   php bin/migrate-features.php\n";
}

// Check database driver
echo "\n3. Database configuration...\n";
$driver = $_ENV['DB_DRIVER'] ?? 'unknown';
echo "   Driver: {$driver}\n";

if ($driver === 'mysql') {
    echo "   Host: " . ($_ENV['DB_HOST'] ?? 'not set') . "\n";
    echo "   Database: " . ($_ENV['DB_DATABASE'] ?? 'not set') . "\n";
} elseif ($driver === 'sqlite') {
    echo "   Path: " . ($_ENV['DB_SQLITE_PATH'] ?? 'default') . "\n";
}

// Check write permissions
echo "\n4. Checking write permissions...\n";
$writableDirs = [
    'backups',
    'data',
];

foreach ($writableDirs as $dir) {
    $path = $root . '/' . $dir;
    if (is_dir($path)) {
        if (is_writable($path)) {
            echo "   ✓ Directory '{$dir}' is writable\n";
        } else {
            echo "   ✗ Directory '{$dir}' is not writable\n";
        }
    } else {
        echo "   ⚠ Directory '{$dir}' does not exist\n";
    }
}

// Check environment variables
echo "\n5. Checking environment configuration...\n";
$requiredEnv = ['APP_URL', 'DB_DRIVER'];
$missingEnv = [];

foreach ($requiredEnv as $key) {
    if (!empty($_ENV[$key])) {
        echo "   ✓ {$key} is set\n";
    } else {
        echo "   ✗ {$key} is not set\n";
        $missingEnv[] = $key;
    }
}

// Check PHP extensions
echo "\n6. Checking PHP extensions...\n";

// Determine required extensions based on database driver
$driver = $_ENV['DB_DRIVER'] ?? 'mysql';
$requiredExtensions = [
    'pdo',
    'mbstring',
    'json',
    'gd',
];

// Add database-specific PDO driver
if ($driver === 'mysql') {
    $requiredExtensions[] = 'pdo_mysql';
} elseif ($driver === 'sqlite') {
    $requiredExtensions[] = 'pdo_sqlite';
}

foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "   ✓ Extension '{$ext}' loaded\n";
    } else {
        echo "   ⚠ Extension '{$ext}' not loaded\n";
    }
}

// Summary
echo "\n=== Health Check Summary ===\n";
if (empty($missingTables) && empty($missingEnv)) {
    echo "✓ All checks passed! System is healthy.\n";
} else {
    echo "⚠ Some issues found. Please review the output above.\n";
    if (!empty($missingTables)) {
        echo "\n  Action required: Run database migrations\n";
    }
    if (!empty($missingEnv)) {
        echo "\n  Action required: Configure missing environment variables in .env\n";
    }
}

echo "\n";
