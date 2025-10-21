<?php
/**
 * Auto Status Update Script
 * 
 * Description: Automatically updates permit statuses based on validity dates
 * Name: auto-status-update.php
 * 
 * What it does:
 * - Checks for permits with valid_to date in the past
 * - Changes status from 'issued' or 'active' to 'expired'
 * - Logs status change in events
 */

require __DIR__ . '/../vendor/autoload.php';
use Ramsey\Uuid\Uuid;

// Load environment variables
[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

// Get current timestamp
$now = date('Y-m-d H:i:s');

// Find permits that should be expired
$query = "SELECT id, ref, status, valid_to 
          FROM forms 
          WHERE status IN ('issued', 'active') 
          AND valid_to IS NOT NULL 
          AND valid_to < ?";

$stmt = $db->pdo->prepare($query);
$stmt->execute([$now]);
$expiredForms = $stmt->fetchAll();

$count = count($expiredForms);

if ($count === 0) {
    exit(0);
}

// Update each expired permit
foreach ($expiredForms as $form) {
    $oldStatus = $form['status'];
    $newStatus = 'expired';
    
    // Update status
    $upd = $db->pdo->prepare("UPDATE forms SET status=?, updated_at=NOW() WHERE id=?");
    $upd->execute([$newStatus, $form['id']]);
    
    // Log status change event
    $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
    $evt->execute([
        Uuid::uuid4()->toString(),
        $form['id'],
        'status_changed',
        'auto-system',
        json_encode([
            'old' => $oldStatus,
            'new' => $newStatus,
            'reason' => 'auto-expired',
            'valid_to' => $form['valid_to']
        ])
    ]);
}

exit(0);