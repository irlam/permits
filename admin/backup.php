<?php
/**
 * Admin Backup Utility
 *
 * Creates full application backups (files + database) that can be
 * restored on a different server. Archives are stored in a private
 * directory outside the document root and are available to admins only.
 */

require __DIR__ . '/../vendor/autoload.php';
use Permits\Csrf;
use Permits\BackupStorage;
use Permits\SystemSettings;

[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Auth.php';

$auth = new Auth($db);
$currentUser = $auth->requireRoles(['admin']);

$errors = [];
$messages = [];
$backupSettings = SystemSettings::load($db, [
    'backup_path', 'backup_retention', 'backup_include_vendor', 'backup_delete_after_download',
], [
    'backup_path' => (string)($_ENV['BACKUP_PATH'] ?? ''),
    'backup_retention' => '5',
    'backup_include_vendor' => 'true',
    'backup_delete_after_download' => 'false',
]);
$backupPath = trim((string)$backupSettings['backup_path']);
$backupRetention = max(1, min(50, (int)$backupSettings['backup_retention']));
$defaultIncludeVendor = filter_var($backupSettings['backup_include_vendor'], FILTER_VALIDATE_BOOLEAN);
$deleteAfterDownload = filter_var($backupSettings['backup_delete_after_download'], FILTER_VALIDATE_BOOLEAN);
$backupDir = null;
try {
    $backupDir = BackupStorage::ensure($root, $backupPath);
} catch (Throwable $storageError) {
    $errors[] = $storageError->getMessage();
}

$download = $_GET['download'] ?? null;
if ($download) {
    if (!is_string($backupDir)) {
        http_response_code(503);
        exit('Backup storage is unavailable');
    }
    $safeName = basename($download);
    if (preg_match('/^permits_backup_\d{8}_\d{6}\.zip$/', $safeName) !== 1) {
        http_response_code(404);
        exit('Backup not found');
    }
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
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    if ($deleteAfterDownload && !@unlink($path)) {
        error_log('Downloaded backup could not be removed from private storage: ' . $safeName);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_backup_settings'])) {
    try {
        $submittedPath = trim((string)($_POST['backup_path'] ?? ''));
        $submittedRetention = filter_var(
            $_POST['backup_retention'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 50]]
        );
        if ($submittedPath === '') {
            throw new RuntimeException('Enter an absolute private backup directory.');
        }
        if ($submittedRetention === false) {
            throw new RuntimeException('Backup retention must be between 1 and 50 archives.');
        }

        $validatedPath = BackupStorage::ensure($root, $submittedPath);
        $submittedIncludeVendor = isset($_POST['backup_include_vendor']);
        $submittedDeleteAfterDownload = isset($_POST['backup_delete_after_download']);
        SystemSettings::save($db, [
            'backup_path' => $validatedPath,
            'backup_retention' => (string)$submittedRetention,
            'backup_include_vendor' => $submittedIncludeVendor ? 'true' : 'false',
            'backup_delete_after_download' => $submittedDeleteAfterDownload ? 'true' : 'false',
        ]);

        $backupPath = $validatedPath;
        $backupRetention = $submittedRetention;
        $defaultIncludeVendor = $submittedIncludeVendor;
        $deleteAfterDownload = $submittedDeleteAfterDownload;
        $backupDir = $validatedPath;
        $messages[] = 'Backup configuration saved and the private directory was verified.';
    } catch (Throwable $settingsError) {
        $errors[] = $settingsError->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::validateRequest('admin-backup', true)) {
    http_response_code(419);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Page expired</title><h1>Page expired</h1><p>Refresh the backup page and try again.</p>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    $includeVendor = isset($_POST['include_vendor']);
    try {
        if (!is_string($backupDir)) {
            throw new RuntimeException('Private backup storage is unavailable.');
        }
        $result = create_full_backup($root, $backupDir, $db->pdo, [
            'includeVendor'  => $includeVendor,
        ]);
        prune_backups($backupDir, $backupRetention);
        $messages[] = 'Backup created: ' . htmlspecialchars($result['name']) . ' (' . format_bytes($result['size']) . ')';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_backup'])) {
    try {
        if (!is_string($backupDir)) {
            throw new RuntimeException('Private backup storage is unavailable.');
        }
        $safeName = basename((string)$_POST['delete_backup']);
        if (preg_match('/^permits_backup_\d{8}_\d{6}\.zip$/', $safeName) !== 1) {
            throw new RuntimeException('Backup not found.');
        }
        $path = realpath($backupDir . DIRECTORY_SEPARATOR . $safeName);
        if ($path === false || !str_starts_with($path, $backupDir . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException('Backup not found.');
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Unable to delete the backup.');
        }
        $messages[] = 'Backup deleted.';
    } catch (Throwable $deleteError) {
        $errors[] = $deleteError->getMessage();
    }
}

$existingBackups = is_string($backupDir) ? list_backups($backupDir) : [];

function create_full_backup(string $root, string $backupDir, PDO $pdo, array $options): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension required.');
    }
    set_time_limit(0);
    $timestamp = date('Ymd_His');
    $zipName = 'permits_backup_' . $timestamp . '.zip';
    $zipPath = $backupDir . DIRECTORY_SEPARATOR . $zipName;

    $zip = new ZipArchive();
    try {
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create backup archive.');
        }

    $includeVendor = !empty($options['includeVendor']);
    $excludePrefixes = [
        '.git',
        '.github',
        '.phpunit.cache',
        '.vscode',
        'backups',
        'data/mail',
        'database/database.sql',
        'node_modules',
        'storage',
        'work-composer.phar',
    ];
    if (!$includeVendor) {
        $excludePrefixes[] = 'vendor';
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

    $manifest = build_manifest($databaseName, $includeVendor);
    $zip->addFromString('MANIFEST.txt', $manifest);

    $readme = build_readme($databaseName);
    $zip->addFromString('README.md', $readme);

        if (!$zip->close()) {
            throw new RuntimeException('Unable to finish the backup archive.');
        }
        @chmod($zipPath, 0640);
        $archiveSize = filesize($zipPath) ?: 0;
    } catch (Throwable $backupError) {
        @$zip->close();
        @unlink($zipPath);
        throw $backupError;
    }

    return [
        'path' => $zipPath,
        'name' => $zipName,
        'size' => $archiveSize,
    ];
}

function should_exclude(string $relativePath, array $prefixes): bool
{
    $relativePath = str_replace('\\', '/', $relativePath);
    if ($relativePath === '.env' || str_starts_with($relativePath, '.env.')) {
        return true;
    }
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

function build_manifest(string $databaseName, bool $includeVendor): string
{
    $manifest = "============================================================\n";
    $manifest .= "PERMIT SYSTEM BACKUP MANIFEST\n";
    $manifest .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $manifest .= "Database: " . ($databaseName !== '' ? $databaseName : 'N/A') . "\n";
    $manifest .= "Includes vendor/: " . ($includeVendor ? 'yes' : 'no') . "\n";
    $manifest .= "Secrets (.env) and earlier backup archives: excluded\n";
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
    $readme .= "\nThe .env secrets file is deliberately excluded. Create a fresh .env and set APP_URL, database, mail and notification credentials for the new server.\n";
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

function prune_backups(string $backupDir, int $retention): void
{
    $items = glob($backupDir . DIRECTORY_SEPARATOR . 'permits_backup_*.zip') ?: [];
    usort($items, static fn (string $left, string $right): int => (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0));
    foreach (array_slice($items, max(1, $retention)) as $expiredBackup) {
        if (!@unlink($expiredBackup)) {
            error_log('Expired backup could not be removed: ' . basename($expiredBackup));
        }
    }
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
        <p class="meta">Create a full backup (application files and database) for recovery or migration. Archives are stored outside the public website directory.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success"><?= $message ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <h2 style="margin-top:0;">Backup Configuration</h2>
            <p class="meta">These are the only server-file options editable here. The directory must be an absolute, private path outside <code>httpdocs</code> and permitted by the host's <code>open_basedir</code> policy.</p>
            <form method="post">
                <?= Csrf::getFormField('admin-backup') ?>
                <div class="field" style="margin-top:16px;">
                    <label for="backup_path" style="display:block;font-weight:600;">Private backup directory</label>
                    <input id="backup_path" name="backup_path" type="text" value="<?= htmlspecialchars($backupPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="/absolute/private/path/permits-backups" required style="width:100%;margin-top:8px;padding:11px 12px;border-radius:9px;border:1px solid #475569;background:#0a101a;color:#e2e8f0;box-sizing:border-box;">
                    <p class="meta">For this Netcup layout, try <code>/var/www/vhosts/hosting215226.ae97b.netcup.net/tmp/permits-private-backups</code>.</p>
                </div>
                <div class="options">
                    <div class="option">
                        <label for="backup_retention">Keep the latest
                            <input id="backup_retention" name="backup_retention" type="number" min="1" max="50" value="<?= (int)$backupRetention ?>" style="width:70px;padding:8px;border-radius:8px;border:1px solid #475569;background:#111827;color:#e2e8f0;">
                            ZIP files
                        </label>
                    </div>
                    <div class="option">
                        <label><input type="checkbox" name="backup_include_vendor" value="1" <?= $defaultIncludeVendor ? 'checked' : '' ?>> Include <code>vendor/</code> by default</label>
                    </div>
                    <div class="option">
                        <label><input type="checkbox" name="backup_delete_after_download" value="1" <?= $deleteAfterDownload ? 'checked' : '' ?>> Delete server copy after download</label>
                        <p class="meta">Use this only when each downloaded ZIP is immediately moved to safe off-site storage.</p>
                    </div>
                </div>
                <button type="submit" name="save_backup_settings" value="1" class="btn btn-primary" style="margin-top:18px;">Save &amp; Verify Configuration</button>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Create Backup</h2>
            <p class="meta">Backups contain permit records, account data and private links. Download them promptly, store them in encrypted off-site storage, then remove the server copy. The <code>.env</code> secrets file and earlier backups are always excluded.</p>
            <form method="post">
                <?= Csrf::getFormField('admin-backup') ?>
                <div class="options">
                    <div class="option">
                        <label>
                            <input type="checkbox" name="include_vendor" value="1" <?= $defaultIncludeVendor ? 'checked' : '' ?>>
                            Include <code>vendor/</code> directory
                        </label>
                        <p class="meta">Keeps Composer dependencies packed. Uncheck if you prefer to run <code>composer install</code> after restore.</p>
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
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this server backup?');">
                                        <?= Csrf::getFormField('admin-backup') ?>
                                        <button class="btn btn-secondary" type="submit" name="delete_backup" value="<?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?>">Delete</button>
                                    </form>
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
                <li>Set private permissions for <code>data</code> and the external backup folder, plus writable permissions for <code>uploads</code>.</li>
                <li>Remove downloaded backups from the server after verifying restore.</li>
            </ul>
        </div>
    </div>
</body>
</html>
