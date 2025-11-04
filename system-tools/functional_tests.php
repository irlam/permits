<?php
declare(strict_types=1);

/**
 * system-tools/functional_tests.php
 * Functional test runner for the Permits application
 */

// Load the test database configuration
$testDbConfig = require_once __DIR__ . '/../config/test_database.php';

echo "=== Functional Tests for Defect Tracker ===\n\n";

// Display test database configuration
echo "Test Database Configuration:\n";
echo "Driver: " . ($testDbConfig['driver'] ?? 'not set') . "\n";
echo "Host: " . ($testDbConfig['host'] ?? 'not set') . "\n";
echo "Database: " . ($testDbConfig['database'] ?? 'not set') . "\n";
echo "\n";

// Test 1: Verify test database config is loaded
function test_database_config_loaded($config): bool {
    if (!is_array($config)) {
        echo "❌ FAILED: Test database config is not an array\n";
        return false;
    }
    
    $requiredKeys = ['driver', 'host', 'database'];
    foreach ($requiredKeys as $key) {
        if (!isset($config[$key])) {
            echo "❌ FAILED: Missing required config key: {$key}\n";
            return false;
        }
    }
    
    echo "✅ PASSED: Test database config loaded successfully\n";
    return true;
}

// Test 2: Verify bootstrap can be loaded
function test_bootstrap_exists(): bool {
    $bootstrapPath = __DIR__ . '/../src/bootstrap.php';
    if (!file_exists($bootstrapPath)) {
        echo "❌ FAILED: Bootstrap file does not exist at {$bootstrapPath}\n";
        return false;
    }
    
    echo "✅ PASSED: Bootstrap file exists\n";
    return true;
}

// Test 3: Verify required directories exist
function test_required_directories(): bool {
    $requiredDirs = [
        __DIR__ . '/../src',
        __DIR__ . '/../config',
        __DIR__ . '/../tests',
        __DIR__ . '/../vendor',
    ];
    
    $allExist = true;
    foreach ($requiredDirs as $dir) {
        if (!is_dir($dir)) {
            echo "❌ FAILED: Required directory does not exist: {$dir}\n";
            $allExist = false;
        }
    }
    
    if ($allExist) {
        echo "✅ PASSED: All required directories exist\n";
    }
    
    return $allExist;
}

// Run tests
echo "Running Functional Tests:\n";
echo "========================\n\n";

$results = [];
$results[] = test_database_config_loaded($testDbConfig);
$results[] = test_bootstrap_exists();
$results[] = test_required_directories();

// Summary
echo "\n========================\n";
echo "Test Summary:\n";
$passed = count(array_filter($results));
$total = count($results);
echo "{$passed}/{$total} tests passed\n";

if ($passed === $total) {
    echo "\n✅ All tests passed!\n";
    exit(0);
} else {
    echo "\n❌ Some tests failed\n";
    exit(1);
}
