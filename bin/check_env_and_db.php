<?php
declare(strict_types=1);
date_default_timezone_set('Europe/London');

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
  // Ensure .env is loaded the same way your app does
  $dotenv = Dotenv\Dotenv::createImmutable($root);
  $dotenv->safeLoad();

  $driver = $_ENV['DB_DRIVER'] ?? '(missing)';
  $host   = $_ENV['DB_HOST']   ?? '(missing)';
  $db     = $_ENV['DB_DATABASE'] ?? '(missing)';
  $user   = $_ENV['DB_USERNAME'] ?? '(missing)';

  echo "ENV loaded. DRIVER={$driver}, HOST={$host}, DB={$db}, USER={$user}\n";

  if ($driver !== 'mysql') {
    throw new RuntimeException("DB_DRIVER must be 'mysql' here.");
  }

  $dsn  = "mysql:host={$host};port=" . ($_ENV['DB_PORT'] ?? '3306') . ";dbname={$db};charset=" . ($_ENV['DB_CHARSET'] ?? 'utf8mb4');
  $pdo  = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  $row = $pdo->query('SELECT 1 AS ok')->fetch();
  echo "DB OK: " . json_encode($row) . "\n";

  // Check VAPID presence (not validity)
  $vp = $_ENV['VAPID_PUBLIC_KEY']  ?? '';
  $vr = $_ENV['VAPID_PRIVATE_KEY'] ?? '';
  echo "VAPID set? public=" . ($vp !== '' ? 'yes' : 'no') . ", private=" . ($vr !== '' ? 'yes' : 'no') . "\n";

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
}
