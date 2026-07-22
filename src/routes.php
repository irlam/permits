<?php
use Psr\Http\Message\ResponseInterface as Res;
use Psr\Http\Message\ServerRequestInterface as Req;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/approval-notifications.php';
require_once __DIR__ . '/Auth.php';

/** @return array<string,mixed>|null */
function routeUser($db): ?array {
  startSession();
  if (empty($_SESSION['user_id'])) { return null; }
  $stmt = $db->pdo->prepare("SELECT id,email,name,role,status FROM users WHERE id=? AND status='active'");
  $stmt->execute([$_SESSION['user_id']]);
  return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function routeJson(Res $res, int $status, array $body): Res {
  $res->getBody()->write(json_encode($body, JSON_UNESCAPED_SLASHES));
  return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
}

function routeRequireUser($db, Res $res, array $roles = []): array {
  $user = routeUser($db);
  if (!$user) { return [null, routeJson($res, 401, ['ok'=>false, 'error'=>'Authentication required'])]; }
  if ($roles && !in_array(strtolower((string)$user['role']), $roles, true)) {
    return [null, routeJson($res, 403, ['ok'=>false, 'error'=>'Insufficient permissions'])];
  }
  return [$user, null];
}

/**
 * Routes file.
 * Assumes $app, $db, $root are already in scope from index.php:
 *   [$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
 */

// Simple in-memory cache for frequently accessed data (templates list)
$templateCache = null;
function getTemplates($db) {
    global $templateCache;
    if ($templateCache === null) {
        $templateCache = $db->pdo->query("SELECT id,name,version FROM form_templates ORDER BY name,version DESC")->fetchAll();
    }
    return $templateCache;
}

// Home: list templates + recent forms
$app->get('/', function(Req $req, Res $res) use ($db) {
  $tpls = getTemplates($db);
  
  // Build search query
  $params = $req->getQueryParams();
  $where = [];
  $binds = [];
  
  if(!empty($params['search'])) {
    $search = '%' . $params['search'] . '%';
    $where[] = "(ref LIKE ? OR site_block LIKE ? OR metadata LIKE ?)";
    $binds[] = $search;
    $binds[] = $search;
    $binds[] = $search;
  }
  
  if(!empty($params['status'])) {
    $where[] = "status = ?";
    $binds[] = $params['status'];
  }
  
  if(!empty($params['template'])) {
    $where[] = "template_id = ?";
    $binds[] = $params['template'];
  }
  
  if(!empty($params['date_from'])) {
    $where[] = "created_at >= ?";
    $binds[] = $params['date_from'];
  }
  
  if(!empty($params['date_to'])) {
    $where[] = "created_at <= ?";
    $binds[] = $params['date_to'] . ' 23:59:59';
  }
  
  $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
  $sql = "SELECT id,template_id,site_block,ref,status,valid_to,created_at FROM forms $whereClause ORDER BY created_at DESC LIMIT 100";
  $stmt = $db->pdo->prepare($sql);
  $stmt->execute($binds);
  $forms = $stmt->fetchAll();
  
  ob_start(); include __DIR__ . '/../templates/layout.php'; $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

// Dashboard: statistics and overview
$app->get('/dashboard', function(Req $req, Res $res) use ($db) {
  [, $denied] = routeRequireUser($db, $res);
  if ($denied) { return $denied; }
  $tpls = getTemplates($db);
  ob_start(); include __DIR__ . '/../templates/dashboard.php'; $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

// Render a new form from a template
$app->get('/new/{templateId}', function(Req $req, Res $res, $args) use ($db) {
  [, $denied] = routeRequireUser($db, $res, ['admin','manager']);
  if ($denied) { return $denied; }
  $id = $args['templateId'];
  $stmt = $db->pdo->prepare("SELECT * FROM form_templates WHERE id=?");
  $stmt->execute([$id]);
  $tpl = $stmt->fetch();
  if (!$tpl) {
    $res->getBody()->write("Template not found");
    return $res->withStatus(404);
  }
  $schemaJson = $tpl['json_schema'];
  ob_start(); include __DIR__ . '/../templates/forms/renderer.php'; $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

// Create/save a form (JSON body)
$app->post('/api/forms', function(Req $req, Res $res) use ($db, $root) {
  [$user, $denied] = routeRequireUser($db, $res, ['admin','manager']);
  if ($denied) { return $denied; }
  $raw = (string)$req->getBody();
  $b = json_decode($raw, true);
  if (!is_array($b)) { $b = $req->getParsedBody(); }
  if (!is_array($b)) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Invalid JSON']));
    return $res->withHeader('Content-Type','application/json')->withStatus(400);
  }

  $templateId = (string)($b['template_id'] ?? '');
  $validStatuses = ['draft','pending_approval'];
  $status = strtolower((string)($b['status'] ?? 'draft'));
  if (!in_array($status, $validStatuses, true)) {
    return routeJson($res, 422, ['ok'=>false,'error'=>'Invalid initial status']);
  }
  $templateCheck = $db->pdo->prepare('SELECT id FROM form_templates WHERE id=? AND active=1');
  $templateCheck->execute([$templateId]);
  if (!$templateCheck->fetchColumn()) {
    return routeJson($res, 422, ['ok'=>false,'error'=>'Invalid template']);
  }

  $id = Uuid::uuid4()->toString();
  $db->pdo->beginTransaction();
  try {
  $ins = $db->pdo->prepare("INSERT INTO forms (id,template_id,site_block,ref,status,holder_id,issuer_id,valid_from,valid_to,metadata)
                             VALUES (?,?,?,?,?,?,?,?,?,?)");
  $ins->execute([
    $id,
    $templateId,
    $b['meta']['block'] ?? 'Block 1',
    $b['meta']['permitNo'] ?? 'AUTO',
    $status,
    $b['holder_id'] ?? null,
    $user['id'],
    $b['meta']['validFrom'] ?? null,
    $b['meta']['validTo'] ?? null,
    json_encode($b, JSON_UNESCAPED_UNICODE)
  ]);

  $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
  $evt->execute([
    Uuid::uuid4()->toString(),
    $id,
    'created',
    $user['id'],
    json_encode(['ip'=>($_SERVER['REMOTE_ADDR'] ?? '')], JSON_UNESCAPED_UNICODE)
  ]);
  $db->pdo->commit();
  } catch (\Throwable $e) {
    if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }
    error_log('Permit create failed: ' . $e->getMessage());
    return routeJson($res, 500, ['ok'=>false,'error'=>'Unable to create permit']);
  }

  $res->getBody()->write(json_encode(['ok'=>true,'id'=>$id]));

  if (isset($b['status']) && strtolower((string)$b['status']) === 'pending_approval') {
    try {
      notifyPendingApprovalRecipients($db, $root, $id);
    } catch (\Throwable $e) {
      error_log('Failed to queue approval notification (api/forms POST): ' . $e->getMessage());
    }
  }

  return $res->withHeader('Content-Type','application/json');
});

// List templates (JSON)
$app->get('/api/templates', function(Req $req, Res $res) use ($db) {
  $rows = getTemplates($db);
  $res->getBody()->write(json_encode($rows));
  return $res->withHeader('Content-Type','application/json');
});

// View a single form by ID
$app->get('/form/{formId}', function(Req $req, Res $res, $args) use ($db) {
  [, $denied] = routeRequireUser($db, $res);
  if ($denied) { return $denied; }
  $formId = $args['formId'];
  $stmt = $db->pdo->prepare("SELECT * FROM forms WHERE id=?");
  $stmt->execute([$formId]);
  $form = $stmt->fetch();
  
  if (!$form) {
    $res->getBody()->write("Form not found");
    return $res->withStatus(404);
  }
  
  // Get the template
  $tplStmt = $db->pdo->prepare("SELECT * FROM form_templates WHERE id=?");
  $tplStmt->execute([$form['template_id']]);
  $template = $tplStmt->fetch();
  
  // Get attachments
  $attStmt = $db->pdo->prepare("SELECT * FROM attachments WHERE form_id=? ORDER BY created_at DESC");
  $attStmt->execute([$formId]);
  $attachments = $attStmt->fetchAll();
  
  // Get events/history
  $evtStmt = $db->pdo->prepare("SELECT * FROM form_events WHERE form_id=? ORDER BY at DESC");
  $evtStmt->execute([$formId]);
  $events = $evtStmt->fetchAll();
  
  ob_start(); 
  include __DIR__ . '/../templates/forms/view.php'; 
  $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

// Edit form page
$app->get('/form/{formId}/edit', function(Req $req, Res $res, $args) use ($db) {
  [, $denied] = routeRequireUser($db, $res, ['admin','manager']);
  if ($denied) { return $denied; }
  $formId = $args['formId'];
  $stmt = $db->pdo->prepare("SELECT * FROM forms WHERE id=?");
  $stmt->execute([$formId]);
  $form = $stmt->fetch();
  
  if (!$form) {
    $res->getBody()->write("Form not found");
    return $res->withStatus(404);
  }
  
  // Get the template
  $tplStmt = $db->pdo->prepare("SELECT * FROM form_templates WHERE id=?");
  $tplStmt->execute([$form['template_id']]);
  $template = $tplStmt->fetch();
  
  $schemaJson = $template['json_schema'];
  $existingData = json_decode($form['metadata'], true);
  
  ob_start(); 
  include __DIR__ . '/../templates/forms/edit.php'; 
  $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

// Duplicate a form (create copy)
$app->post('/form/{formId}/duplicate', function(Req $req, Res $res, $args) use ($db) {
  [$user, $denied] = routeRequireUser($db, $res, ['admin','manager']);
  if ($denied) { return $denied; }
  $formId = $args['formId'];
  $stmt = $db->pdo->prepare("SELECT * FROM forms WHERE id=?");
  $stmt->execute([$formId]);
  $originalForm = $stmt->fetch();
  
  if (!$originalForm) {
    $res->getBody()->write("Form not found");
    return $res->withStatus(404);
  }
  
  // Parse existing metadata
  $metadata = json_decode($originalForm['metadata'], true);
  
  // Update metadata to indicate it's a copy
  if(isset($metadata['meta']['permitNo'])) {
    $metadata['meta']['permitNo'] = $metadata['meta']['permitNo'] . '-COPY';
  }
  
  // Clear dates
  $metadata['meta']['validFrom'] = '';
  $metadata['meta']['validTo'] = '';
  
  // Clear signatures
  $metadata['signatures'] = [];
  
  // Create new form in database
  $newId = Uuid::uuid4()->toString();
  $ins = $db->pdo->prepare("INSERT INTO forms (id,template_id,site_block,ref,status,holder_id,issuer_id,valid_from,valid_to,metadata)
                             VALUES (?,?,?,?,?,?,?,?,?,?)");
  $ins->execute([
    $newId,
    $originalForm['template_id'],
    $originalForm['site_block'],
    ($originalForm['ref'] ?? 'AUTO') . '-COPY',
    'draft', // Always start as draft
    $originalForm['holder_id'],
    $originalForm['issuer_id'],
    null, // Clear valid_from
    null, // Clear valid_to
    json_encode($metadata, JSON_UNESCAPED_UNICODE)
  ]);
  
  // Log event
  $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
  $evt->execute([
    Uuid::uuid4()->toString(),
    $newId,
    'created',
    $user['id'],
    json_encode(['ip'=>($_SERVER['REMOTE_ADDR'] ?? ''), 'duplicated_from'=>$formId], JSON_UNESCAPED_UNICODE)
  ]);
  
  // Redirect to edit page for the new form
  return $res->withHeader('Location', '/form/' . $newId . '/edit')->withStatus(302);
});

// Update a form
$app->put('/api/forms/{formId}', function(Req $req, Res $res, $args) use ($db, $root) {
  [$user, $denied] = routeRequireUser($db, $res, ['admin','manager']);
  if ($denied) { return $denied; }
  $formId = $args['formId'];
  $raw = (string)$req->getBody();
  $b = json_decode($raw, true);
  
  if (!is_array($b)) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Invalid JSON']));
    return $res->withHeader('Content-Type','application/json')->withStatus(400);
  }
  
  // Get current form
  $stmt = $db->pdo->prepare("SELECT * FROM forms WHERE id=?");
  $stmt->execute([$formId]);
  $currentForm = $stmt->fetch();
  
  if (!$currentForm) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Form not found']));
    return $res->withHeader('Content-Type','application/json')->withStatus(404);
  }
  
  $oldStatus = $currentForm['status'];
  $newStatus = $b['status'] ?? $oldStatus;
  $allowedTransitions = [
    'draft' => ['draft','pending_approval'],
    'pending_approval' => ['pending_approval','draft'],
    'active' => ['active'], 'rejected' => ['rejected'], 'closed' => ['closed'], 'expired' => ['expired'],
  ];
  if (!in_array($newStatus, $allowedTransitions[$oldStatus] ?? [$oldStatus], true)) {
    return routeJson($res, 409, ['ok'=>false,'error'=>'Invalid status transition']);
  }
  $now = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
  $db->pdo->beginTransaction();
  try {
  
  // Update form
  $upd = $db->pdo->prepare("UPDATE forms SET 
    site_block=?, ref=?, status=?, valid_from=?, valid_to=?, metadata=?, updated_at=$now
    WHERE id=?");
  $upd->execute([
    $b['meta']['block'] ?? $currentForm['site_block'],
    $b['meta']['permitNo'] ?? $currentForm['ref'],
    $newStatus,
    $b['meta']['validFrom'] ?? $currentForm['valid_from'],
    $b['meta']['validTo'] ?? $currentForm['valid_to'],
    json_encode($b, JSON_UNESCAPED_UNICODE),
    $formId
  ]);
  
  // Log status change
  if($oldStatus !== $newStatus) {
    $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
    $evt->execute([
      Uuid::uuid4()->toString(),
      $formId,
      'status_changed',
      $user['id'],
      json_encode(['old'=>$oldStatus, 'new'=>$newStatus])
    ]);
  }
  
  // Log general update
  $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
  $evt->execute([
    Uuid::uuid4()->toString(),
    $formId,
    'updated',
    $user['id'],
    json_encode(['ip'=>($_SERVER['REMOTE_ADDR'] ?? '')])
  ]);
  $db->pdo->commit();
  } catch (\Throwable $e) {
    if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }
    error_log('Permit update failed: ' . $e->getMessage());
    return routeJson($res, 500, ['ok'=>false,'error'=>'Unable to update permit']);
  }
  
  if (strtolower((string)$newStatus) === 'pending_approval') {
    if ($oldStatus !== 'pending_approval' || empty($currentForm['notified_at'])) {
      try {
        notifyPendingApprovalRecipients($db, $root, $formId);
      } catch (\Throwable $e) {
        error_log('Failed to queue approval notification (api/forms PUT): ' . $e->getMessage());
      }
    }
  } elseif ($oldStatus === 'pending_approval') {
    try {
      clearPendingApprovalNotificationFlag($db, $formId);
    } catch (\Throwable $e) {
      error_log('Failed to clear approval notification flag: ' . $e->getMessage());
    }
  }

  $res->getBody()->write(json_encode(['ok'=>true]));
  return $res->withHeader('Content-Type','application/json');
});

// Delete a form
$app->delete('/api/forms/{formId}', function(Req $req, Res $res, $args) use ($db) {
  [, $denied] = routeRequireUser($db, $res, ['admin']);
  if ($denied) { return $denied; }
  $formId = $args['formId'];
  
  $files = $db->pdo->prepare('SELECT url FROM attachments WHERE form_id=?');
  $files->execute([$formId]);
  $attachmentFiles = $files->fetchAll(\PDO::FETCH_COLUMN);
  $db->pdo->beginTransaction();
  // Delete attachments first (foreign key constraint)
  $db->pdo->prepare("DELETE FROM attachments WHERE form_id=?")->execute([$formId]);
  
  // Delete events
  $db->pdo->prepare("DELETE FROM form_events WHERE form_id=?")->execute([$formId]);
  
  // Delete form
  $stmt = $db->pdo->prepare("DELETE FROM forms WHERE id=?");
  $stmt->execute([$formId]);
  if ($stmt->rowCount() > 0) { $db->pdo->commit(); }
  else { $db->pdo->rollBack(); }
  
  if($stmt->rowCount() > 0) {
    foreach ($attachmentFiles as $url) {
      $path = realpath(dirname(__DIR__) . '/' . ltrim((string)$url, '/'));
      $uploadRoot = realpath(dirname(__DIR__) . '/uploads');
      if ($path && $uploadRoot && str_starts_with($path, $uploadRoot . DIRECTORY_SEPARATOR) && is_file($path)) { @unlink($path); }
    }
    $res->getBody()->write(json_encode(['ok'=>true]));
  } else {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Form not found']));
    return $res->withStatus(404)->withHeader('Content-Type','application/json');
  }
  
  return $res->withHeader('Content-Type','application/json');
});

// Upload attachment to a form
$app->post('/api/forms/{formId}/attachments', function(Req $req, Res $res, $args) use ($db, $root) {
  [$user, $denied] = routeRequireUser($db, $res, ['admin','manager']);
  if ($denied) { return $denied; }
  $formId = $args['formId'];
  
  // Check form exists
  $stmt = $db->pdo->prepare("SELECT id FROM forms WHERE id=?");
  $stmt->execute([$formId]);
  if(!$stmt->fetch()) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Form not found']));
    return $res->withStatus(404)->withHeader('Content-Type','application/json');
  }
  
  if(!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'No file uploaded']));
    return $res->withStatus(400)->withHeader('Content-Type','application/json');
  }
  
  $file = $_FILES['file'];
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 10 * 1024 * 1024) {
    return routeJson($res, 422, ['ok'=>false,'error'=>'Upload must be no larger than 10 MB']);
  }
  $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
  $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf'];
  if (!isset($allowed[$mime])) {
    return routeJson($res, 422, ['ok'=>false,'error'=>'Unsupported file type']);
  }
  $uploadDir = $root . '/uploads';
  if(!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
  }
  
  // Generate unique filename
  $ext = $allowed[$mime];
  $filename = Uuid::uuid4()->toString() . '.' . $ext;
  $filepath = $uploadDir . '/' . $filename;
  
  if(!move_uploaded_file($file['tmp_name'], $filepath)) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Failed to move uploaded file']));
    return $res->withStatus(500)->withHeader('Content-Type','application/json');
  }
  
  // Save to database
  $id = Uuid::uuid4()->toString();
  $url = '/uploads/' . $filename;
  $kind = $mime;
  $meta = json_encode([
    'original_name' => $file['name'],
    'size' => $file['size']
  ]);
  
  $ins = $db->pdo->prepare("INSERT INTO attachments (id, form_id, kind, url, meta) VALUES (?,?,?,?,?)");
  $ins->execute([$id, $formId, $kind, $url, $meta]);
  
  // Log event
  $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
  $evt->execute([
    Uuid::uuid4()->toString(),
    $formId,
    'attachment_added',
    $user['id'],
    json_encode(['filename'=>$file['name']])
  ]);
  
  $res->getBody()->write(json_encode(['ok'=>true,'id'=>$id,'url'=>$url]));
  return $res->withHeader('Content-Type','application/json');
});

// Delete attachment
$app->delete('/api/attachments/{attachmentId}', function(Req $req, Res $res, $args) use ($db, $root) {
  [, $denied] = routeRequireUser($db, $res, ['admin','manager']);
  if ($denied) { return $denied; }
  $attId = $args['attachmentId'];
  
  $stmt = $db->pdo->prepare("SELECT * FROM attachments WHERE id=?");
  $stmt->execute([$attId]);
  $att = $stmt->fetch();
  
  if(!$att) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Attachment not found']));
    return $res->withStatus(404)->withHeader('Content-Type','application/json');
  }
  
  // Delete file from disk
  $filepath = $root . $att['url'];
  if(file_exists($filepath)) {
    unlink($filepath);
  }
  
  // Delete from database
  $db->pdo->prepare("DELETE FROM attachments WHERE id=?")->execute([$attId]);
  
  // Log event
  $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
  $evt->execute([
    Uuid::uuid4()->toString(),
    $att['form_id'],
    'attachment_removed',
    'web',
    json_encode(['url'=>$att['url']])
  ]);
  
  $res->getBody()->write(json_encode(['ok'=>true]));
  return $res->withHeader('Content-Type','application/json');
});

// ----- UPDATED: Push subscription (stores endpoint_hash; upserts for MySQL/SQLite)
$app->post('/api/push/subscribe', function(Req $req, Res $res) use ($db) {
  [$user, $denied] = routeRequireUser($db, $res);
  if ($denied) { return $denied; }
  $b = $req->getParsedBody();
  // Allow raw JSON too
  if (!$b) {
    $raw = (string)$req->getBody();
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) { $b = $tmp; }
  }

  $endpoint = $b['endpoint'] ?? '';
  $p256dh   = $b['keys']['p256dh'] ?? '';
  $auth     = $b['keys']['auth'] ?? '';
  $userId   = $user['id'];

  if (!$endpoint || !$p256dh || !$auth) {
    $res->getBody()->write(json_encode(['ok'=>false, 'error'=>'Invalid subscription payload']));
    return $res->withHeader('Content-Type','application/json')->withStatus(400);
  }

  $hash = hash('sha256', $endpoint);
  $id   = Uuid::uuid4()->toString();

  $driver = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
  if ($driver === 'mysql') {
    // UNIQUE(endpoint_hash) ensures idempotency
    $sql = "INSERT INTO push_subscriptions (id,user_id,endpoint,endpoint_hash,p256dh,auth)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              user_id=VALUES(user_id),
              endpoint=VALUES(endpoint),
              p256dh=VALUES(p256dh),
              auth=VALUES(auth)";
  } else {
    $sql = "INSERT INTO push_subscriptions (id,user_id,endpoint,endpoint_hash,p256dh,auth)
            VALUES (?,?,?,?,?,?)
            ON CONFLICT(endpoint_hash) DO UPDATE SET
              user_id=excluded.user_id,
              endpoint=excluded.endpoint,
              p256dh=excluded.p256dh,
              auth=excluded.auth";
  }

  $stmt = $db->pdo->prepare($sql);
  $stmt->execute([$id, $userId, $endpoint, $hash, $p256dh, $auth]);

  $res->getBody()->write(json_encode(['ok'=>true]));
  return $res->withHeader('Content-Type','application/json');
});
