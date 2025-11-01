<?php
/**
 * Admin Backup Utility
 *
 * Creates full application backups (files + database) that can be
 * restored on a different server. Produces a timestamped ZIP inside
 * the project "backups" directory and offers direct download.
 */

require __DIR__ . '/../vendor/autoload.php';
[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$stmt = $db->pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetchColumn();
if ($user !== 'admin') {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$errors = [];
$messages = [];
$backupDir = $root . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

$download = $_GET['download'] ?? null;
if ($download) {
    $safeName = basename($download);
    $path = realpath($backupDir . '/' . $safeName);
    if ($path === false || !str_starts_with($path, realpath($backupDir) . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit('Backup not found');
    }
    if (!is_file($path)) {
        http_response_code(404);
        exit('Backup not found');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    $includeVendor = isset($_POST['include_vendor']);
    $includeBackups = isset($_POST['include_backups']);
    try {
        $result = create_full_backup($root, $db->pdo, [
            'includeVendor'  => $includeVendor,
            'includeBackups' => $includeBackups,
        ]);
        $messages[] = 'Backup created: ' . htmlspecialchars($result['name']) . ' (' . format_bytes($result['size']) . ')';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$existingBackups = list_backups($backupDir);

function create_full_backup(string $root, PDO $pdo, array $options): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension required.');
    }
    set_time_limit(0);
    $timestamp = date('Ymd_His');
    $zipName = 'permits_backup_' . $timestamp . '.zip';
    $zipPath = $root . '/backups/' . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create backup archive.');
    }

    $includeVendor = !empty($options['includeVendor']);
    $includeBackups = !empty($options['includeBackups']);

    $excludePrefixes = [
        '.git',
        '.github',
        '.vscode',
        'node_modules',
    ];
    if (!$includeVendor) {
        $excludePrefixes[] = 'vendor';
    }
    if (!$includeBackups) {
        $excludePrefixes[] = 'backups';
    }

    $filterIterator = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function (SplFileInfo $current) use ($root, $excludePrefixes): bool {
            $relative = substr($current->getPathname(), strlen($root) + 1);
            if ($relative === false || $relative === '') {
                return true;
            }
            return !should_exclude($relative, $excludePrefixes);
        }
    );

    $directoryIterator = new RecursiveIteratorIterator(
        $filterIterator,
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($directoryIterator as $path => $info) {
        $relative = substr($path, strlen($root) + 1);
        if ($relative === false) {
            continue;
        }

        if ($info->isDir()) {
            $zip->addEmptyDir($relative);
            continue;
        }

        if (!$zip->addFile($path, $relative)) {
            throw new RuntimeException('Failed to add file to archive: ' . $relative);
        }
    }

    $driver = strtolower($_ENV['DB_DRIVER'] ?? 'mysql');
    $databaseName = (string)($_ENV['DB_DATABASE'] ?? '');
    $sqlDump = export_database($pdo, $driver, $databaseName);
    if ($sqlDump !== '') {
        $zip->addFromString('database/database.sql', $sqlDump);
    }

    $manifest = build_manifest($root, $databaseName, $includeVendor, $includeBackups);
    $zip->addFromString('MANIFEST.txt', $manifest);

    $readme = build_readme($databaseName);
    $zip->addFromString('README.md', $readme);

    $zip->close();
    @chmod($zipPath, 0640);

    return [
        'path' => $zipPath,
        'name' => $zipName,
        'size' => filesize($zipPath) ?: 0,
    ];
}

function should_exclude(string $relativePath, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if ($prefix === '') {
            continue;
        }
        if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
            return true;
        }
    }
    return false;
}

function export_database(PDO $pdo, string $driver, string $databaseName): string
{
    if ($driver === 'mysql') {
        return export_mysql_database($pdo, $databaseName);
    }
    if ($driver === 'sqlite') {
        return export_sqlite_database($pdo);
    }
    return '';
}

function export_mysql_database(PDO $pdo, string $databaseName): string
{
    $pdo->exec('SET SESSION sql_quote_show_create = 1');
    $sql = "-- Permit System MySQL backup\n";
    $sql .= '-- Generated at ' . date('c') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tablesStmt = $pdo->query('SHOW FULL TABLES');
    if (!$tablesStmt) {
        return '';
    }

    $tables = $tablesStmt->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $row) {
        $table = $row[0];
        $type = strtoupper($row[1] ?? 'BASE TABLE');
        if ($type === 'VIEW') {
            $createViewStmt = $pdo->query("SHOW CREATE VIEW `{$table}`");
            if ($createViewStmt) {
                $createView = $createViewStmt->fetch(PDO::FETCH_NUM)[1] ?? '';
                $sql .= "DROP VIEW IF EXISTS `{$table}`;\n{$createView};\n\n";
            }
            continue;
        }

        $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
        if (!$createStmt) {
            continue;
        }
        $createSql = $createStmt->fetch(PDO::FETCH_NUM)[1] ?? '';
        if ($createSql === '') {
            continue;
        }

        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n";

        $dataStmt = $pdo->query("SELECT * FROM `{$table}`");
        if (!$dataStmt) {
            continue;
        }
        while ($rowData = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_keys($rowData);
            $quotedColumns = array_map(fn ($col) => '`' . str_replace('`', '``', $col) . '`', $columns);
            $values = [];
            foreach ($rowData as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $pdo->quote((string)$value);
                }
            }
            $sql .= 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        if ($dataStmt->rowCount() > 0) {
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    return $sql;
}

function export_sqlite_database(PDO $pdo): string
{
    $sql = "-- Permit System SQLite backup\n";
    $sql .= '-- Generated at ' . date('c') . "\n\n";

    $schemaStmt = $pdo->query("SELECT name, type, sql FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%'");
    if (!$schemaStmt) {
        return '';
    }

    $items = $schemaStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $name = $item['name'];
        $type = $item['type'];
        $definition = $item['sql'];
        if (!$name || !$definition) {
            continue;
        }
        $drop = $type === 'view' ? 'DROP VIEW IF EXISTS' : 'DROP TABLE IF EXISTS';
        $sql .= "$drop `{$name}`;\n{$definition};\n\n";
        if ($type === 'view') {
            continue;
        }
        $dataStmt = $pdo->query("SELECT * FROM `{$name}`");
        if (!$dataStmt) {
            continue;
        }
        while ($rowData = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_keys($rowData);
            $quotedColumns = array_map(fn ($col) => '`' . str_replace('`', '``', $col) . '`', $columns);
            $values = [];
            foreach ($rowData as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $pdo->quote((string)$value);
                }
            }
            $sql .= 'INSERT INTO `' . $name . '` (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        if ($dataStmt->rowCount() > 0) {
            $sql .= "\n";
        }
    }

    return $sql;
}

function build_manifest(string $root, string $databaseName, bool $includeVendor, bool $includeBackups): string
{
    $manifest = "============================================================\n";
    $manifest .= "PERMIT SYSTEM BACKUP MANIFEST\n";
    $manifest .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $manifest .= "Root Path: {$root}\n";
    $manifest .= "Database: " . ($databaseName !== '' ? $databaseName : 'N/A') . "\n";
    $manifest .= "Includes vendor/: " . ($includeVendor ? 'yes' : 'no') . "\n";
    $manifest .= "Includes backups/: " . ($includeBackups ? 'yes' : 'no') . "\n";
    $manifest .= "============================================================\n";
    return $manifest;
}

function build_readme(string $databaseName): string
{
    $readme = "# Permit System Backup\n\n";
    $readme .= "Generated at " . date('c') . "\n\n";
    $readme .= "## Restore Files\n";
    $readme .= "Unzip the archive into the target document root (preserving directories).\n";
    $readme .= "```bash\nzip -F permits_backup.zip --out restored.zip\n```\n";
    $readme .= "## Restore Database\n";
    if ($databaseName !== '') {
        $readme .= "```bash\nmysql -u USER -p {$databaseName} < database/database.sql\n```\n";
    } else {
        $readme .= "See database/database.sql for statements\n";
    }
    $readme .= "\nUpdate .env/APP_URL and other environment variables for the new server.\n";
    return $readme;
}

function list_backups(string $backupDir): array
{
    if (!is_dir($backupDir)) {
        return [];
    }
    $items = glob($backupDir . '/*.zip') ?: [];
    rsort($items);
    return array_map(static function (string $path): array {
        return [
            'name' => basename($path),
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'created' => filemtime($path) ?: time(),
        ];
    }, $items);
}

function format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B','KB','MB','GB','TB'];
    $power = min((int)floor(log($bytes, 1024)), count($units) - 1);
    return number_format($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Backup Utility</title>
    <link rel="stylesheet" href="<?= asset('/assets/app.css') ?>">
    <style>
        body { background:#0f172a; color:#e2e8f0; font-family:system-ui, -apple-system, sans-serif; margin:0; }
        .wrap { max-width: 960px; margin: 0 auto; padding: 32px 16px 80px; }
        a.back { color:#60a5fa; text-decoration:none; display:inline-flex; align-items:center; gap:6px; margin-bottom:20px; }
        a.back:hover { text-decoration:underline; }
        h1 { font-size:30px; margin-bottom:12px; }
        .card { background:#111827; border:1px solid #1f2937; border-radius:16px; padding:24px; margin-bottom:24px; box-shadow:0 30px 60px rgba(15,23,42,0.35); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:none; border-radius:10px; padding:12px 22px; font-weight:600; cursor:pointer; font-size:15px; transition:background 0.2s ease; }
        .btn-primary { background:#3b82f6; color:#fff; }
        .btn-primary:hover { background:#2563eb; }
        .btn-secondary { background:rgba(59,130,246,0.12); color:#e2e8f0; border:1px solid #475569; }
        .btn-secondary:hover { background:rgba(59,130,246,0.2); }
        .alert { border-radius:12px; padding:16px 18px; margin-bottom:16px; font-size:14px; }
        .alert-success { background:rgba(34,197,94,0.12); border:1px solid rgba(34,197,94,0.45); color:#bbf7d0; }
        .alert-error { background:rgba(248,113,113,0.12); border:1px solid rgba(248,113,113,0.4); color:#fecaca; }
        .options { display:grid; gap:12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-top:16px; }
        .option { background:#0a101a; border:1px solid #1f2937; border-radius:12px; padding:16px; }
        label { display:flex; align-items:center; gap:10px; cursor:pointer; }
        input[type="checkbox"] { width:18px; height:18px; }
        table { width:100%; border-collapse:collapse; margin-top:12px; }
        th, td { padding:12px; text-align:left; border-bottom:1px solid #1f2937; font-size:14px; }
        th { color:#94a3b8; font-weight:600; }
        tbody tr:hover { background:rgba(59,130,246,0.08); }
        .meta { color:#94a3b8; font-size:13px; margin-top:8px; }
        @media (max-width:640px) {
            table, tbody, tr, td, th { display:block; }
            th { border-bottom:none; margin-top:16px; }
            td { border-bottom:none; padding:6px 0; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>Backup Utility</h1>
        <p class="meta">Create a full backup (application files and database) for quick migration to another server.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success"><?= $message ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <h2 style="margin-top:0;">Create Backup</h2>
            <form method="post">
                <div class="options">
                    <div class="option">
                        <label>
                            <input type="checkbox" name="include_vendor" value="1" checked>
                            Include <code>vendor/</code> directory
                        </label>
                        <p class="meta">Keeps Composer dependencies packed. Uncheck if you prefer to run <code>composer install</code> after restore.</p>
                    </div>
                    <div class="option">
                        <label>
                            <input type="checkbox" name="include_backups" value="1">
                            Include existing backups
                        </label>
                        <p class="meta">Leave unchecked to avoid nested archives.</p>
                    </div>
                </div>
                <button type="submit" name="create_backup" value="1" class="btn btn-primary" style="margin-top:18px;">
                    🚀 Generate Backup
                </button>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Existing Backups</h2>
            <?php if (empty($existingBackups)): ?>
                <p class="meta">No backups found yet. Create one above.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Created</th>
                            <th>Size</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($existingBackups as $backup): ?>
                            <tr>
                                <td><?= htmlspecialchars($backup['name']) ?></td>
                                <td><?= date('Y-m-d H:i:s', $backup['created']) ?></td>
                                <td><?= format_bytes((int)$backup['size']) ?></td>
                                <td>
                                    <a class="btn btn-secondary" href="?download=<?= urlencode($backup['name']) ?>">Download</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Restore Checklist</h2>
            <ul style="color:#cbd5f5; font-size:14px; line-height:1.6;">
                <li>Upload the ZIP to the new server and extract it in the document root.</li>
                <li>Create a new database and import <code>database/database.sql</code>.</li>
                <li>Update <code>.env</code> with new database/user credentials and APP_URL.</li>
                <li>Set correct file permissions for <code>storage</code>/<code>backups</code> if needed.</li>
                <li>Remove downloaded backups from the server after verifying restore.</li>
            </ul>
        </div>
    </div>
</body>
</html>
