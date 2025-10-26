<?php
/**
 * Auto Status Update Script
 * 
 * Purpose: Automatically updates permit statuses based on validity dates
 * 
 * What it does:
 * - Checks for permits with valid_to date in the past
 * - Changes status from 'issued' or 'active' to 'expired'
 * - Logs status change in events
 * - Can be run manually or via cron job
 * 
 * Setup as Cron Job:
 * Run daily at midnight: 0 0 * * * /usr/bin/php /path/to/bin/auto-status-update.php
 * Run every hour: 0 * * * * /usr/bin/php /path/to/bin/auto-status-update.php
 * 
 * Manual run: php bin/auto-status-update.php
 */

require __DIR__ . '/../vendor/autoload.php';
use Ramsey\Uuid\Uuid;

// Load environment variables
[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

// Get current timestamp
$now = date('Y-m-d H:i:s');

echo "[" . date('Y-m-d H:i:s') . "] Auto-Status Update Script Starting...\n";

// Find permits that should be expired
// Status must be 'issued' or 'active', and valid_to must be in the past
$query = "SELECT id, ref, status, valid_to 
          FROM forms 
          WHERE status IN ('issued', 'active') 
          AND valid_to IS NOT NULL 
          AND valid_to < ?";

$stmt = $db->pdo->prepare($query);
$stmt->execute([$now]);
$expiredForms = $stmt->fetchAll();

$count = count($expiredForms);
echo "Found $count permit(s) to expire.\n";

if ($count === 0) {
    echo "No permits need status updates.\n";
    exit(0);
}

// Update each expired permit
$updated = 0;
$errors = 0;

foreach ($expiredForms as $form) {
    try {
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
        
        echo "  ✓ Expired: {$form['ref']} (ID: {$form['id']}) - was {$oldStatus}\n";
        $updated++;
        
    } catch (Exception $e) {
        echo "  ✗ Error updating {$form['ref']}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

// Summary
echo "\n";
echo "Summary:\n";
echo "  Total found: $count\n";
echo "  Successfully updated: $updated\n";
echo "  Errors: $errors\n";
echo "[" . date('Y-m-d H:i:s') . "] Auto-Status Update Script Complete.\n";

// Exit with appropriate code
exit($errors > 0 ? 1 : 0);
