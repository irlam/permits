<?php
/**
 * API: Record Start Work event for a permit
 *
 * Input (JSON or form):
 * - link: unique public link of the permit (preferred)
 * The unguessable public link is required.
 */

use Permits\DatabaseMaintenance;

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
header('Content-Type: application/json');

try {
    // Only POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Ensure column exists
    if (class_exists(DatabaseMaintenance::class)) {
        try { DatabaseMaintenance::ensureFormsColumns($db); } catch (\Throwable $e) { /* ignore */ }
    }

    // Parse input
    $raw = file_get_contents('php://input');
    $data = [];
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $data = $decoded; }
    }
    // Also accept form-encoded
    if (empty($data)) { $data = $_POST; }

    $unique_link = isset($data['link']) ? (string)$data['link'] : null;
    if (!$unique_link || strlen($unique_link) < 32) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid public link is required']);
        exit;
    }

    // Load permit
    $stmt = $db->pdo->prepare("SELECT * FROM forms WHERE unique_link = ? LIMIT 1");
    $stmt->execute([$unique_link]);

    $permit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$permit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Permit not found']);
        exit;
    }

    if (strtolower((string)$permit['status']) !== 'active') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Permit is not active']);
        exit;
    }

    if (!empty($permit['work_started_at']) && $permit['work_started_at'] !== '0000-00-00 00:00:00') {
        echo json_encode(['success' => true, 'message' => 'Already recorded', 'work_started_at' => $permit['work_started_at']]);
        exit;
    }

    // Update
    $nowExpr = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    $upd = $db->pdo->prepare("UPDATE forms SET work_started_at = $nowExpr, updated_at = $nowExpr WHERE id = ?");
    $upd->execute([$permit['id']]);

    // Reload timestamp
    $get = $db->pdo->prepare("SELECT work_started_at FROM forms WHERE id = ?");
    $get->execute([$permit['id']]);
    $ts = $get->fetchColumn();

    if (function_exists('logActivity')) {
        logActivity('work_started', 'permit', 'form', $permit['id'], 'Work started recorded via public view');
    }

    echo json_encode(['success' => true, 'work_started_at' => $ts]);
} catch (\Throwable $e) {
    error_log('Start-work request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to record work start']);
}
