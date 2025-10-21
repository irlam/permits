<?php
// admin-templates.php — upload/list JSON form templates (protected by ADMIN_KEY)
header('X-Robots-Tag: noindex, nofollow', true);
require __DIR__ . '/vendor/autoload.php';
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

$key = $_GET['key'] ?? 'pick-a-long-random-string';
if (!$key || !isset($_ENV['ADMIN_KEY']) || !hash_equals($_ENV['ADMIN_KEY'], $key)) {
  http_response_code(403);
  echo "<h1>403 Forbidden</h1><p>Missing/wrong key.</p>";
  exit;
}

$msg = null; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tpl'])) {
  try {
    if (!is_uploaded_file($_FILES['tpl']['tmp_name'])) throw new RuntimeException('Upload failed');
    $raw = file_get_contents($_FILES['tpl']['tmp_name']);
    $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $id   = $j['id']   ?? null;
    $name = $j['title'] ?? ($j['name'] ?? null);
    if (!$id || !$name) throw new RuntimeException('Template must include "id" and "title"');
    // Upsert
    $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
      $sql = "INSERT INTO form_templates (id,name,version,json_schema,created_by,published_at,updated_at)
              VALUES (?,?,?,?,?,NOW(),NOW())
              ON DUPLICATE KEY UPDATE name=VALUES(name), version=VALUES(version), json_schema=VALUES(json_schema), updated_at=NOW()";
    } else {
      $sql = "INSERT INTO form_templates (id,name,version,json_schema,created_by,published_at)
              VALUES (?,?,?,?,?,datetime('now'))
              ON CONFLICT(id) DO UPDATE SET name=excluded.name, version=excluded.version, json_schema=excluded.json_schema";
    }
    $ver = intval($j['version'] ?? 1);
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([$id, $name, $ver, json_encode($j, JSON_UNESCAPED_UNICODE), 'admin']);
    $msg = "Template \"$name\" (" . htmlspecialchars($id) . ") saved.";
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$rows = $db->pdo->query("SELECT id,name,version,created_at FROM form_templates ORDER BY name,version DESC")->fetchAll();
?><!doctype html>
<meta charset="utf-8">
<title>Templates Admin</title>
<style>
  body{font-family:system-ui,Segoe UI,Roboto,Arial;max-width:920px;margin:32px auto;padding:0 16px}
  .card{border:1px solid #ddd;border-radius:12px;padding:16px;margin-bottom:16px}
  .ok{color:#15803d}.err{color:#b91c1c}
  table{width:100%;border-collapse:collapse}
  th,td{border:1px solid #ddd;padding:8px;text-align:left}
  .muted{color:#6b7280}
</style>
<h1>Templates Admin</h1>
<p class="muted">Keyed access OK.</p>
<?php if($msg): ?><p class="ok">✓ <?=htmlspecialchars($msg)?></p><?php endif; ?>
<?php if($err): ?><p class="err">✗ <?=htmlspecialchars($err)?></p><?php endif; ?>

<div class="card">
  <h2>Upload JSON template</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="tpl" accept="application/json" required>
    <button>Upload</button>
  </form>
  <p class="muted">JSON must include <code>id</code>, <code>title</code>, <code>meta.fields</code>, <code>sections</code>.</p>
</div>

<div class="card">
  <h2>Existing templates</h2>
  <table>
    <thead><tr><th>ID</th><th>Name</th><th>Version</th><th>Created</th><th>Open</th></tr></thead>
    <tbody>
    <?php foreach($rows as $r): ?>
      <tr>
        <td><?=htmlspecialchars($r['id'])?></td>
        <td><?=htmlspecialchars($r['name'])?></td>
        <td><?=intval($r['version'])?></td>
        <td><?=htmlspecialchars($r['created_at'])?></td>
        <td><a href="/new/<?=htmlspecialchars($r['id'])?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
