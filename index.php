<?php
/**
 * Permits System - Main Entry Point
 * 
 * Description: Bootstrap and initialize the Permits application using Slim Framework
 * Name: index.php
 * Last Updated: 21/10/2025 19:22:30 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Initializes application environment and dependencies
 * - Loads configuration from environment variables
 * - Sets up database connection
 * - Registers all routes and middleware
 * - Starts the Slim application router
 */

// Initialize application components (Slim app, database connection, root path)
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Load all route definitions for the application
require __DIR__ . '/src/routes.php';

// Start the application and handle incoming requests
$app->run();
