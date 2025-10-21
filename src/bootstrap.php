<?php
/**
 * Permits System - Application Bootstrap
 * 
 * Description: Initializes the application environment, loads configuration, and sets up dependencies
 * Name: bootstrap.php
 * Last Updated: 21/10/2025 19:22:30 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Load Composer dependencies (Slim Framework, database drivers, utilities)
 * - Initialize environment variables from .env file
 * - Configure database connection with PDO
 * - Create and configure Slim Framework application instance
 * - Set up middleware for parsing JSON request bodies
 * 
 * Returns:
 * - Array containing [$app, $db, $root] for use in routes and other scripts
 */

use Slim\Factory\AppFactory;
use Dotenv\Dotenv;
use Permits\Db;

// Load Composer autoloader for all dependencies
require __DIR__ . '/../vendor/autoload.php';

// Get the absolute path to the application root directory
$root = realpath(__DIR__ . '/..');

// Load environment variables from .env file (safely, without throwing errors if missing)
$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

/**
 * Helper function to retrieve environment variables with optional default values
 * 
 * @param string $k The environment variable key
 * @param mixed $d Default value if the key doesn't exist
 * @return mixed The environment variable value or default
 */
function envv($k, $d = null) { return $_ENV[$k] ?? $d; }

// Build database DSN (Data Source Name) from environment, supporting __ROOT__ placeholder
// Defaults to SQLite database in data/app.sqlite if not configured
$dsn = str_replace('__ROOT__', $root, envv('DB_DSN', "sqlite:$root/data/app.sqlite"));

// Create database connection with credentials from environment variables
$db  = new Db($dsn, envv('DB_USER',''), envv('DB_PASS',''));

// Create Slim Framework application instance
$app = AppFactory::create();

// Add middleware to automatically parse JSON request bodies
// This allows routes to access JSON data via $request->getParsedBody()
$app->addBodyParsingMiddleware();

// Return configured components for use throughout the application
return [$app, $db, $root];
