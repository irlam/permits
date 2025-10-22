<?php
/**
 * Permits System - Dashboard Page
 * 
 * Description: Main dashboard showing key metrics and statistics
 * Name: dashboard.php
 * Last Updated: 21/10/2025 21:03:42 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Display key permit statistics and metrics
 * - Show active permits count
 * - Display permits expiring soon
 * - Show status breakdown
 * - Provide recent activity timeline
 * - Quick action buttons
 * 
 * Features:
 * - Real-time statistics
 * - Visual metric cards
 * - Status distribution charts
 * - Recent activity feed
 * - Quick navigation
 */

// Load application bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Get statistics
$stats = [
    'total' => 0,
    'active' => 0,
    'draft' => 0,
    'pending' => 0,
    'issued' => 0,
    'expired' => 0,
    'closed' => 0,
    'expiring_7days' => 0,
    'expiring_30days' => 0,
];

try {
    // Total permits
    $stats['total'] = $db->pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn();
    
    // Status breakdown
    $statusCounts = $db->pdo->query("
        SELECT status, COUNT(*) as count 
        FROM forms 
        GROUP BY status
    ")->fetchAll();
    
    foreach ($statusCounts as $row) {
        $status = $row['status'];
        if (isset($stats[$status])) {
            $stats[$status] = (int)$row['count'];
        }
    }
    
    // Permits expiring in next 7 days
    $driver = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $stats['expiring_7days'] = $db->pdo->query("
            SELECT COUNT(*) 
            FROM forms 
            WHERE status IN ('active', 'issued') 
            AND valid_to IS NOT NULL 
            AND DATEDIFF(valid_to, NOW()) BETWEEN 0 AND 7
        ")->fetchColumn();
        
        $stats['expiring_30days'] = $db->pdo->query("
            SELECT COUNT(*) 
            FROM forms 
            WHERE status IN ('active', 'issued') 
            AND valid_to IS NOT NULL 
            AND DATEDIFF(valid_to, NOW()) BETWEEN 0 AND 30
        ")->fetchColumn();
    } else {
        // SQLite fallback
        $stats['expiring_7days'] = $db->pdo->query("
            SELECT COUNT(*) 
            FROM forms 
            WHERE status IN ('active', 'issued') 
            AND valid_to IS NOT NULL 
            AND julianday(valid_to) - julianday('now') BETWEEN 0 AND 7
        ")->fetchColumn();
        
        $stats['expiring_30days'] = $db->pdo->query("
            SELECT COUNT(*) 
            FROM forms 
            WHERE status IN ('active', 'issued') 
            AND valid_to IS NOT NULL 
            AND julianday(valid_to) - julianday('now') BETWEEN 0 AND 30
        ")->fetchColumn();
    }
    
} catch (\Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}

// Get recent activity
$recentForms = [];
try {
    $stmt = $db->pdo->query("
        SELECT id, ref, site_block, status, created_at, updated_at 
        FROM forms 
        ORDER BY updated_at DESC 
        LIMIT 10
    ");
    $recentForms = $stmt->fetchAll();
} catch (\Exception $e) {
    error_log("Dashboard recent forms error: " . $e->getMessage());
}

// Get recent events
$recentEvents = [];
try {
    $stmt = $db->pdo->query("
        SELECT e.*, f.ref 
        FROM form_events e
        LEFT JOIN forms f ON e.form_id = f.id
        ORDER BY e.at DESC 
        LIMIT 15
    ");
    $recentEvents = $stmt->fetchAll();
} catch (\Exception $e) {
    error_log("Dashboard recent events error: " . $e->getMessage());
}

// Load templates for the sidebar
$tpls = [];
try {
    $tpls = $db->pdo->query("SELECT id, name, version FROM form_templates ORDER BY name, version DESC")->fetchAll();
} catch (\Exception $e) {
    error_log("Dashboard templates error: " . $e->getMessage());
}

$base = $_ENV['APP_URL'] ?? '/';

// Render dashboard template
ob_start();
include __DIR__ . '/templates/dashboard.php';
$html = ob_get_clean();

echo $html;
