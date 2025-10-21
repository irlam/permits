<?php
/**
 * Permits System - Database Connection Manager
 * 
 * Description: Manages database connections with PDO and applies necessary configuration
 * Name: Db.php
 * Last Updated: 21/10/2025 19:22:30 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Create and configure PDO database connections
 * - Set PDO attributes for error handling and fetch modes
 * - Enable SQLite foreign key constraints when using SQLite
 * - Provide a reusable database connection instance
 * 
 * Features:
 * - Supports multiple database drivers (SQLite, MySQL, PostgreSQL)
 * - Throws exceptions on database errors for better error handling
 * - Returns associative arrays by default for easier data access
 * - Disables prepared statement emulation for better security
 */

namespace Permits;
use PDO;

/**
 * Database connection wrapper class
 * 
 * Provides a configured PDO instance with security best practices
 */
class Db {
  /**
   * @var PDO The PDO database connection instance
   */
  public PDO $pdo;
  
  /**
   * Constructor - Initialize database connection with configuration
   * 
   * @param string $dsn Data Source Name (e.g., 'sqlite:/path/to/db.sqlite' or 'mysql:host=localhost;dbname=permits')
   * @param string|null $user Database username (optional for SQLite)
   * @param string|null $pass Database password (optional for SQLite)
   */
  public function __construct(string $dsn, ?string $user = null, ?string $pass = null) {
    // Configure PDO with security and usability best practices
    $opts = [
      // Throw exceptions on errors instead of silent failures
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      
      // Return rows as associative arrays by default
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      
      // Use native prepared statements for better security (no emulation)
      PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    // Create PDO connection with the provided credentials
    $this->pdo = new PDO($dsn, $user ?: null, $pass ?: null, $opts);
    
    // SQLite-specific configuration: enable foreign key constraints
    // SQLite disables foreign keys by default, so we explicitly enable them
    if (strpos($dsn, 'sqlite:') === 0) {
      $this->pdo->exec('PRAGMA foreign_keys = ON;');
    }
  }
}
