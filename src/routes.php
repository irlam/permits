<?php
/**
 * Permits System - Route Definitions
 * 
 * Description: Defines all HTTP routes and their handlers for the Permits application
 * Name: routes.php
 * Last Updated: 21/10/2025 19:22:30 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Define web routes for viewing and managing permits
 * - Define API endpoints for CRUD operations on forms
 * - Handle file uploads and attachments
 * - Manage push notification subscriptions
 * - Support search, filter, and duplicate functionality
 * 
 * Route Structure:
 * - GET / - Homepage with search and form listing
 * - GET /new/{templateId} - Create new form from template
 * - GET /form/{formId} - View single form details
 * - GET /form/{formId}/edit - Edit existing form
 * - GET /form/{formId}/duplicate - Copy form as new draft
 * - POST /api/forms - Create new form (JSON API)
 * - PUT /api/forms/{formId} - Update existing form (JSON API)
 * - DELETE /api/forms/{formId} - Delete form (JSON API)
 * - GET /api/templates - List all form templates (JSON API)
 * - POST /api/forms/{formId}/attachments - Upload file attachment
 * - DELETE /api/attachments/{attachmentId} - Delete attachment
 * - POST /api/push/subscribe - Register push notification subscription
 * 
 * Dependencies:
 * - $app: Slim Framework application instance
 * - $db: Database connection instance
 * - $root: Application root directory path
 */

use Psr\Http\Message\ResponseInterface as Res;
use Psr\Http\Message\ServerRequestInterface as Req;
use Ramsey\Uuid\Uuid;

/**
 * Route: GET /
 * Homepage - Display templates and recent forms with search/filter functionality
 */
$app->get('/', function(Req $req, Res $res) use ($db) {
  // Load all available form templates for display in sidebar
  $tpls = $db->pdo->query("SELECT id,name,version FROM form_templates ORDER BY name,version DESC")->fetchAll();
  
  // Build dynamic search query based on user-provided filters
  $params = $req->getQueryParams();
  $where = [];  // SQL WHERE conditions
  $binds = [];  // Prepared statement parameters for security
  
  // Filter by search term (checks reference number, location, and metadata JSON)
  if(!empty($params['search'])) {
    $search = '%' . $params['search'] . '%';
    $where[] = "(ref LIKE ? OR site_block LIKE ? OR metadata LIKE ?)";
    $binds[] = $search;
    $binds[] = $search;
    $binds[] = $search;
  }
  
  // Filter by specific status (draft, pending, issued, active, expired, closed)
  if(!empty($params['status'])) {
    $where[] = "status = ?";
    $binds[] = $params['status'];
  }
  
  // Filter by specific template/form type
  if(!empty($params['template'])) {
    $where[] = "template_id = ?";
    $binds[] = $params['template'];
  }
  
  // Filter by start date (forms created on or after this date)
  if(!empty($params['date_from'])) {
    $where[] = "created_at >= ?";
    $binds[] = $params['date_from'];
  }
  
  // Filter by end date (forms created on or before this date, including full day)
  if(!empty($params['date_to'])) {
    $where[] = "created_at <= ?";
    $binds[] = $params['date_to'] . ' 23:59:59';
  }
  
  // Build final SQL query with all filters combined
  $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
  $sql = "SELECT id,template_id,site_block,ref,status,valid_to,created_at FROM forms $whereClause ORDER BY created_at DESC LIMIT 100";
  
  // Execute query with prepared statement parameters for security
  $stmt = $db->pdo->prepare($sql);
  $stmt->execute($binds);
  $forms = $stmt->fetchAll();
  
  // Render homepage template with forms and filters
  ob_start(); include __DIR__ . '/../templates/layout.php'; $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

/**
 * Route: GET /new/{templateId}
 * Render blank form for creating a new permit from a template
 */
$app->get('/new/{templateId}', function(Req $req, Res $res, $args) use ($db) {
  $id = $args['templateId'];
  
  // Load the form template definition from database
  $stmt = $db->pdo->prepare("SELECT * FROM form_templates WHERE id=?");
  $stmt->execute([$id]);
  $tpl = $stmt->fetch();
  
  // Return 404 if template doesn't exist
  if (!$tpl) {
    $res->getBody()->write("Template not found");
    return $res->withStatus(404);
  }
  
  // Extract JSON schema containing form structure, fields, and validation rules
  $schemaJson = $tpl['json_schema'];
  
  // Render the form creation page with empty fields
  ob_start(); include __DIR__ . '/../templates/forms/renderer.php'; $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

/**
 * Route: POST /api/forms
 * Create and save a new form with submitted data
 */
$app->post('/api/forms', function(Req $req, Res $res) use ($db) {
  // Parse JSON request body
  $raw = (string)$req->getBody();
  $b = json_decode($raw, true);
  
  // Fallback to parsed body if JSON decode fails
  if (!is_array($b)) { $b = $req->getParsedBody(); }
  
  // Validate that we have valid data
  if (!is_array($b)) {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Invalid JSON']));
    return $res->withHeader('Content-Type','application/json')->withStatus(400);
  }

  // Generate unique identifier for the new form
  $id = Uuid::uuid4()->toString();
  // Insert new form into database with all metadata
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
    json_encode($b, JSON_UNESCAPED_UNICODE)  // Store complete form data as JSON
  ]);

  // Log the creation event for audit trail
  $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
  $evt->execute([
    Uuid::uuid4()->toString(),
    $id,
    'created',
    'web',
    json_encode(['ip'=>($_SERVER['REMOTE_ADDR'] ?? '')], JSON_UNESCAPED_UNICODE)
  ]);

  // Return success response with the new form ID
  $res->getBody()->write(json_encode(['ok'=>true,'id'=>$id]));
  return $res->withHeader('Content-Type','application/json');
});

/**
 * Route: GET /api/templates
 * List all available form templates (JSON API)
 */
$app->get('/api/templates', function(Req $req, Res $res) use ($db) {
  // Retrieve all templates ordered by name and version
  $rows = $db->pdo->query("SELECT id,name,version FROM form_templates ORDER BY name,version DESC")->fetchAll();
  $res->getBody()->write(json_encode($rows));
  return $res->withHeader('Content-Type','application/json');
});

/**
 * Route: GET /form/{formId}
 * View complete details of a single form/permit
 */
$app->get('/form/{formId}', function(Req $req, Res $res, $args) use ($db) {
  $formId = $args['formId'];
  
  // Load the form from database
  $stmt = $db->pdo->prepare("SELECT * FROM forms WHERE id=?");
  $stmt->execute([$formId]);
  $form = $stmt->fetch();
  
  if (!$form) {
    $res->getBody()->write("Form not found");
    return $res->withStatus(404);
  }
  
  // Get the template definition for this form
  $tplStmt = $db->pdo->prepare("SELECT * FROM form_templates WHERE id=?");
  $tplStmt->execute([$form['template_id']]);
  $template = $tplStmt->fetch();
  
  // Get all file attachments for this form
  $attStmt = $db->pdo->prepare("SELECT * FROM attachments WHERE form_id=? ORDER BY created_at DESC");
  $attStmt->execute([$formId]);
  $attachments = $attStmt->fetchAll();
  
  // Get event history/audit trail for this form
  $evtStmt = $db->pdo->prepare("SELECT * FROM form_events WHERE form_id=? ORDER BY at DESC");
  $evtStmt->execute([$formId]);
  $events = $evtStmt->fetchAll();
  
  // Render the view template with all data
  ob_start(); 
  include __DIR__ . '/../templates/forms/view.php'; 
  $html = ob_get_clean();
  $res->getBody()->write($html);
  return $res;
});

/**
 * Route: GET /form/{formId}/edit
 * Edit page for existing form/permit
 */
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
    'draft',
    $originalForm['holder_id'],
    $originalForm['issuer_id'],
    null,
    null,
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
$app->put('/api/forms/{formId}', function(Req $req, Res $res, $args) use ($db) {
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
  
  $res->getBody()->write(json_encode(['ok'=>true]));
  return $res->withHeader('Content-Type','application/json');
});

// Delete a form
$app->delete('/api/forms/{formId}', function(Req $req, Res $res, $args) use ($db) {
  $formId = $args['formId'];
  
  // Delete attachments first (foreign key constraint)
  $db->pdo->prepare("DELETE FROM attachments WHERE form_id=?")->execute([$formId]);
  
  // Delete events
  $db->pdo->prepare("DELETE FROM form_events WHERE form_id=?")->execute([$formId]);
  
  // Delete form
  $stmt = $db->pdo->prepare("DELETE FROM forms WHERE id=?");
  $stmt->execute([$formId]);
  
  if($stmt->rowCount() > 0) {
    $res->getBody()->write(json_encode(['ok'=>true]));
  } else {
    $res->getBody()->write(json_encode(['ok'=>false,'error'=>'Form not found']));
    return $res->withStatus(404)->withHeader('Content-Type','application/json');
  }
  
  return $res->withHeader('Content-Type','application/json');
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
