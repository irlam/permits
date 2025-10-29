<?php
use Psr\Http\Message\ResponseInterface as Res;
use Psr\Http\Message\ServerRequestInterface as Req;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/approval-notifications.php';

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
  $tpls = getTemplates($db);
  ob_start(); include __DIR__ . '/../templates/dashboard.php'; $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

// Render a new form from a template
$app->get('/new/{templateId}', function(Req $req, Res $res, $args) use ($db) {
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
  $raw = (string)$req->getBody();
  $b = json_decode($raw, true);
  if (!is_array($b)) { $b = $req->getParsedBody(); }
  if (!is_array($b)) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Invalid JSON']));
    return $res->withHeader('Content-Type','application/json')->withStatus(400);
  }

  $id = Uuid::uuid4()->toString();
  $ins = $db->pdo->prepare("INSERT INTO forms (id,template_id,site_block,ref,status,holder_id,issuer_id,valid_from,valid_to,metadata)
                             VALUES (?,?,?,?,?,?,?,?,?,?)");
  $ins->execute([
    $id,
    $b['template_id'] ?? 'unknown',
    $b['meta']['block'] ?? 'Block 1',
    $b['meta']['permitNo'] ?? 'AUTO',
    $b['status'] ?? 'draft',
    $b['holder_id'] ?? null,
    $b['issuer_id'] ?? null,
    $b['meta']['validFrom'] ?? null,
    $b['meta']['validTo'] ?? null,
    json_encode($b, JSON_UNESCAPED_UNICODE)
  ]);

  $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
  $evt->execute([
    Uuid::uuid4()->toString(),
    $id,
    'created',
    'web',
    json_encode(['ip'=>($_SERVER['REMOTE_ADDR'] ?? '')], JSON_UNESCAPED_UNICODE)
  ]);

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

  $approvalDecision = resolvePermitApprovalDecision($db, $form);
  
  ob_start(); 
  include __DIR__ . '/../templates/forms/view.php'; 
  $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

// Edit form page
$app->get('/form/{formId}/edit', function(Req $req, Res $res, $args) use ($db) {
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
$app->get('/form/{formId}/duplicate', function(Req $req, Res $res, $args) use ($db) {
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
    'web',
    json_encode(['ip'=>($_SERVER['REMOTE_ADDR'] ?? ''), 'duplicated_from'=>$formId], JSON_UNESCAPED_UNICODE)
  ]);
  
  // Redirect to edit page for the new form
  return $res->withHeader('Location', '/form/' . $newId . '/edit')->withStatus(302);
});

// Update a form
$app->put('/api/forms/{formId}', function(Req $req, Res $res, $args) use ($db, $root) {
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
  
  // Update form
  $upd = $db->pdo->prepare("UPDATE forms SET 
    site_block=?, ref=?, status=?, valid_from=?, valid_to=?, metadata=?, updated_at=NOW()
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
      'web',
      json_encode(['old'=>$oldStatus, 'new'=>$newStatus])
    ]);
  }
  
  // Log general update
  $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
  $evt->execute([
    Uuid::uuid4()->toString(),
    $formId,
    'updated',
    'web',
    json_encode(['ip'=>($_SERVER['REMOTE_ADDR'] ?? '')])
  ]);
  
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
    try {
      cancelApprovalLinksForPermit($db, $formId, 'status_changed');
    } catch (\Throwable $e) {
      error_log('Failed to invalidate approval links after status change: ' . $e->getMessage());
    }
  }

  $res->getBody()->write(json_encode(['ok'=>true]));
  return $res->withHeader('Content-Type','application/json');
});

// Delete a form
$app->delete('/api/forms/{formId}', function(Req $req, Res $res, $args) use ($db) {
  $formId = $args['formId'];

  try {
    $deleted = deletePermit($db, $formId);
  } catch (RuntimeException $e) {
    $status = isLoggedIn() ? 403 : 401;
    $res->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
    return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
  } catch (InvalidArgumentException $e) {
    $res->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
    return $res->withStatus(400)->withHeader('Content-Type', 'application/json');
  } catch (Throwable $e) {
    error_log('Permit deletion failed: ' . $e->getMessage());
    $res->getBody()->write(json_encode(['ok' => false, 'error' => 'Failed to delete permit']));
    return $res->withStatus(500)->withHeader('Content-Type', 'application/json');
  }

  if (!$deleted) {
    $res->getBody()->write(json_encode(['ok' => false, 'error' => 'Form not found']));
    return $res->withStatus(404)->withHeader('Content-Type', 'application/json');
  }

  $res->getBody()->write(json_encode(['ok' => true]));
  return $res->withHeader('Content-Type', 'application/json');
});

// Upload attachment to a form
$app->post('/api/forms/{formId}/attachments', function(Req $req, Res $res, $args) use ($db, $root) {
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
  $uploadDir = $root . '/uploads';
  if(!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
  }
  
  // Generate unique filename
  $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
  $filename = Uuid::uuid4()->toString() . '.' . $ext;
  $filepath = $uploadDir . '/' . $filename;
  
  if(!move_uploaded_file($file['tmp_name'], $filepath)) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Failed to move uploaded file']));
    return $res->withStatus(500)->withHeader('Content-Type','application/json');
  }
  
  // Save to database
  $id = Uuid::uuid4()->toString();
  $url = '/uploads/' . $filename;
  $kind = $file['type'];
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
    'web',
    json_encode(['filename'=>$file['name']])
  ]);
  
  $res->getBody()->write(json_encode(['ok'=>true,'id'=>$id,'url'=>$url]));
  return $res->withHeader('Content-Type','application/json');
});

// Delete attachment
$app->delete('/api/attachments/{attachmentId}', function(Req $req, Res $res, $args) use ($db, $root) {
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
  $userId   = $b['user_id'] ?? 'anon';

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
