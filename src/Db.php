<?php
namespace Permits;
use PDO;

class Db {
  public PDO $pdo;
  public function __construct(string $dsn, ?string $user = null, ?string $pass = null) {
    $opts = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $this->pdo = new PDO($dsn, $user ?: null, $pass ?: null, $opts);
    if (strpos($dsn, 'sqlite:') === 0) {
      $this->pdo->exec('PRAGMA foreign_keys = ON;');
    }
  }
}
