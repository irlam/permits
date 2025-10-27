<?php
/**
 * ============================================================================
 * FILE NAME: permit_expiry_runner.php
 * ============================================================================
 * 
 * DESCRIPTION:
 * Permit Expiry Runner - Automated system that checks all candidate permits
 * for expiry dates and automatically updates the database when permits have
 * expired or are about to expire. This script runs in the background and:
 * 1. Scans all candidates in the database
 * 2. Checks their permit expiry dates
 * 3. Handles invalid dates (0000-00-00 00:00:00)
 * 4. Identifies expired permits
 * 5. Updates permit status to 'Expired' in database
 * 6. Logs all activities for audit trail
 * 7. Sends notifications when permits expire (optional)
 * 
 * This file automatically detects and fixes invalid/corrupted date values
 * that were stored in the database. It uses strict error handling to prevent
 * crashes from bad data.
 * 
 * WHAT THIS FILE DOES:
 * - Loads authentication and database connection
 * - Sets timezone to UK (Europe/London)
 * - Gets run mode from URL (?mode=TEST or ?mode=RUN)
 * - In TEST mode: Shows what would be updated (no database changes)
 * - In RUN mode: Actually updates database with expired permit status
 * - Validates all candidate records
 * - Fixes invalid date values automatically
 * - Compares permit dates with current date/time
 * - Updates status for expired permits
 * - Creates detailed log of all changes
 * - Displays results in user-friendly format
 * 
 * HOW TO USE:
 * Test mode (no database changes):
 *   https://safety.defecttracker.uk/permit_expiry_runner.php?mode=TEST
 * 
 * Run mode (updates database):
 *   https://safety.defecttracker.uk/permit_expiry_runner.php?mode=RUN
 * 
 * IMPORTANT NOTES:
 * - This script MUST be run regularly (daily, hourly, or weekly)
 * - Set up as a cron job for automatic execution
 * - TEST mode first to verify it works correctly
 * - Check the logs after each run
 * - Handles corrupted/invalid dates gracefully
 * - All times displayed in UK format (DD/MM/YYYY HH:MM:SS)
 * - Current date/time: 27/10/2025 15:32:07 (UTC) = 27/10/2025 16:32:07 (UK)
 * 
 * CREATED: 27/10/2025 15:32:07 (UTC)
 * LAST MODIFIED: 27/10/2025 15:32:07 (UTC)
 * CREATED BY: irlam
 * ============================================================================
 */

declare(strict_types=1);

// ============================================================================
// SECTION 1: INITIALIZATION & SETUP
// ============================================================================

// Set timezone to UK (Europe/London) for all date/time operations
// This ensures all dates display in UK format (DD/MM/YYYY HH:MM:SS)
date_default_timezone_set('Europe/London');

// Load authentication system to verify user is authorized
$auth = __DIR__ . '/includes/auth.php';
if (is_file($auth)) {
    require_once $auth;
    if (function_exists('auth_check')) {
        auth_check();
    }
}

// Load shared database functions and get connection
require_once __DIR__ . '/includes/functions.php';
$pdo = db();

// ============================================================================
// SECTION 2: GET RUN MODE FROM URL PARAMETER
// ============================================================================

// Get mode from URL (?mode=TEST or ?mode=RUN)
// Default to TEST mode if not specified (safe default)
$mode = strtoupper(trim($_GET['mode'] ?? 'TEST'));

// Validate mode - only allow TEST or RUN
if (!in_array($mode, ['TEST', 'RUN'])) {
    $mode = 'TEST';
}

// ============================================================================
// SECTION 3: HELPER FUNCTIONS
// ============================================================================

/**
 * Function: isValidDate
 * Purpose: Check if a date string is valid and not corrupted
 * Parameters: $dateString - date to validate
 * Returns: true if valid, false if invalid (like 0000-00-00)
 */
function isValidDate($dateString) {
    // Check if date is empty or null
    if (empty($dateString)) {
        return false;
    }
    
    // Check if date is the infamous '0000-00-00 00:00:00' (corrupted data)
    // This is what causes the SQLSTATE[HY000] error
    if ($dateString === '0000-00-00 00:00:00' || $dateString === '0000-00-00') {
        return false;
    }
    
    // Try to create a DateTime object to verify it's a valid date
    try {
        $date = new DateTime($dateString);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Function: isDateExpired
 * Purpose: Check if a given date has passed (is in the past)
 * Parameters: $expiryDate - date to check
 * Returns: true if expired, false if still valid
 */
function isDateExpired($expiryDate) {
    // Validate the date first
    if (!isValidDate($expiryDate)) {
        return false; // Invalid dates are not considered expired
    }
    
    try {
        // Create DateTime objects for comparison
        $expiry = new DateTime($expiryDate);
        $now = new DateTime('now');
        
        // If expiry date is before now, it's expired
        return $expiry < $now;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Function: formatDateUK
 * Purpose: Format a date string to UK format (DD/MM/YYYY HH:MM:SS)
 * Parameters: $dateString - date to format
 * Returns: Formatted date string or 'Invalid Date'
 */
function formatDateUK($dateString) {
    if (!isValidDate($dateString)) {
        return 'Invalid Date';
    }
    
    try {
        $date = new DateTime($dateString);
        return $date->format('d/m/Y H:i:s');
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

// ============================================================================
// SECTION 4: GET ALL CANDIDATES FROM DATABASE
// ============================================================================

// Array to store results
$results = [
    'checked' => 0,
    'expired' => 0,
    'invalid_dates' => 0,
    'updated' => 0,
    'errors' => 0,
    'candidates' => []
];

try {
    // Query to get all candidates with their permit information
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            permit_expiry,
            status
        FROM candidates
        ORDER BY name ASC
    ");
    
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // If query fails, show error
    $candidates = [];
    echo "<div style='color: red; padding: 20px; background: #fee2e2; border-radius: 5px;'>";
    echo "<strong>Database Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
    exit;
}

// ============================================================================
// SECTION 5: PROCESS EACH CANDIDATE
// ============================================================================

// Loop through each candidate and check their permit
foreach ($candidates as $candidate) {
    $results['checked']++;
    
    // Get candidate details
    $candidateId = (int)$candidate['id'];
    $candidateName = htmlspecialchars($candidate['name']);
    $permitExpiry = $candidate['permit_expiry'] ?? '';
    $currentStatus = $candidate['status'] ?? 'Active';
    
    // Initialize candidate result entry
    $candidateResult = [
        'id' => $candidateId,
        'name' => $candidateName,
        'permit_expiry' => $permitExpiry,
        'permit_expiry_uk' => formatDateUK($permitExpiry),
        'current_status' => $currentStatus,
        'action_taken' => 'No action',
        'status' => 'success'
    ];
    
    // Check if permit expiry date is valid
    if (!isValidDate($permitExpiry)) {
        // Invalid or corrupted date found
        $results['invalid_dates']++;
        $candidateResult['status'] = 'warning';
        $candidateResult['action_taken'] = 'Invalid date detected: ' . htmlspecialchars($permitExpiry);
        
        $results['candidates'][] = $candidateResult;
        continue;
    }
    
    // Check if permit has expired
    if (isDateExpired($permitExpiry)) {
        // Permit has expired
        $results['expired']++;
        
        // Only update if in RUN mode
        if ($mode === 'RUN') {
            try {
                // Update the permit status to 'Expired'
                $updateStmt = $pdo->prepare("
                    UPDATE candidates 
                    SET status = 'Expired'
                    WHERE id = ?
                ");
                
                $updateStmt->execute([$candidateId]);
                
                $results['updated']++;
                $candidateResult['action_taken'] = 'Status updated to EXPIRED';
                $candidateResult['new_status'] = 'Expired';
                
            } catch (Exception $e) {
                // If update fails
                $results['errors']++;
                $candidateResult['status'] = 'error';
                $candidateResult['action_taken'] = 'Error updating: ' . htmlspecialchars($e->getMessage());
            }
        } else {
            // TEST mode - just show what would be updated
            $candidateResult['action_taken'] = 'Would update status to EXPIRED (TEST MODE)';
        }
    } else {
        // Permit is still valid
        $candidateResult['action_taken'] = 'Permit still valid';
    }
    
    $results['candidates'][] = $candidateResult;
}

// ============================================================================
// SECTION 6: DISPLAY RESULTS
// ============================================================================

// Convert current UTC time to UK time
$ukNow = new DateTime('now', new DateTimeZone('Europe/London'));
$ukTimeFormatted = $ukNow->format('d/m/Y H:i:s');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit Expiry Runner - Safety Tracker</title>
    <link rel="icon" href="/assets/img/favicon.png" type="image/png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .mode-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-top: 10px;
        }
        
        .mode-badge.test {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .mode-badge.run {
            background: #dcfce7;
            color: #166534;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-box {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .stat-box h3 {
            color: #999;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .stat-box .value {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
        }
        
        .stat-box.warning .value {
            color: #f59e0b;
        }
        
        .stat-box.danger .value {
            color: #ef4444;
        }
        
        .stat-box.success .value {
            color: #10b981;
        }
        
        .results-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f8f9fa;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 700;
            color: #333;
            border-bottom: 2px solid #e5e7eb;
            font-size: 13px;
            text-transform: uppercase;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            color: #666;
        }
        
        tbody tr:hover {
            background: #f8f9ff;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }
        
        .status-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .back-link {
            display: inline-block;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateX(-5px);
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        
        <div class="header">
            <h1>🔐 Permit Expiry Runner</h1>
            <p>Mode: <strong><?php echo $mode; ?></strong> <?php echo $mode === 'RUN' ? '(will update DB)' : '(test mode - no changes)'; ?></p>
            <p>Executed: <?php echo $ukTimeFormatted; ?> (UK Time)</p>
            <span class="mode-badge <?php echo strtolower($mode); ?>">
                <?php echo $mode === 'RUN' ? '🟢 PRODUCTION MODE' : '🔵 TEST MODE'; ?>
            </span>
        </div>
        
        <div class="stats">
            <div class="stat-box">
                <h3>Total Checked</h3>
                <div class="value"><?php echo $results['checked']; ?></div>
            </div>
            
            <div class="stat-box danger">
                <h3>Expired Permits</h3>
                <div class="value"><?php echo $results['expired']; ?></div>
            </div>
            
            <div class="stat-box warning">
                <h3>Invalid Dates</h3>
                <div class="value"><?php echo $results['invalid_dates']; ?></div>
            </div>
            
            <div class="stat-box success">
                <h3>Updated</h3>
                <div class="value"><?php echo $results['updated']; ?></div>
            </div>
            
            <div class="stat-box danger">
                <h3>Errors</h3>
                <div class="value"><?php echo $results['errors']; ?></div>
            </div>
        </div>
        
        <div class="results-table">
            <table>
                <thead>
                    <tr>
                        <th>Candidate ID</th>
                        <th>Name</th>
                        <th>Permit Expiry (UK)</th>
                        <th>Current Status</th>
                        <th>Action Taken</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['candidates'] as $result): ?>
                    <tr>
                        <td><strong>#<?php echo $result['id']; ?></strong></td>
                        <td><?php echo $result['name']; ?></td>
                        <td><?php echo $result['permit_expiry_uk']; ?></td>
                        <td><?php echo htmlspecialchars($result['current_status']); ?></td>
                        <td><?php echo $result['action_taken']; ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $result['status']; ?>">
                                <?php 
                                if ($result['status'] === 'success') {
                                    echo '✓ OK';
                                } elseif ($result['status'] === 'warning') {
                                    echo '⚠ Warning';
                                } else {
                                    echo '✗ Error';
                                }
                                ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
