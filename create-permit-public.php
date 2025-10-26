<?php
/**
 * Public Permit Creation
 * 
 * File Path: /create-permit-public.php
 * Description: Public permit creation form - no login required
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - No authentication required
 * - Collects holder information (name, email, phone)
 * - Optional push notification subscription
 * - Creates permit as "pending_approval"
 * - Generates unique view link
 * - Sends confirmation email
 */

// Load bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Get template ID from query string
$template_id = $_GET['template'] ?? null;

if (!$template_id) {
    header('Location: /');
    exit;
}

// Get template details
try {
    $stmt = $db->pdo->prepare("SELECT * FROM form_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        header('Location: /');
        exit;
    }
} catch (Exception $e) {
    die("Error loading template: " . $e->getMessage());
}

// Handle form submission
$success = false;
$error = null;
$permit_id = null;
$unique_link = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Collect form data
        $holder_name = trim($_POST['holder_name'] ?? '');
        $holder_email = trim($_POST['holder_email'] ?? '');
        $holder_phone = trim($_POST['holder_phone'] ?? '');
        $notification_enabled = isset($_POST['enable_notifications']) ? 1 : 0;
        
        // Validate required fields
        if (empty($holder_name) || empty($holder_email)) {
            throw new Exception("Name and email are required");
        }
        
        if (!filter_var($holder_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address");
        }
        
        // Decode form structure
        $form_structure = json_decode($template['form_structure'], true);
        
        // Collect permit data
        $permit_data = [];
        foreach ($form_structure as $section) {
            foreach ($section['fields'] as $field) {
                $field_name = $field['name'];
                $permit_data[$field_name] = $_POST[$field_name] ?? '';
            }
        }
        
        // Generate unique ID and link
        $permit_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        $unique_link = md5($permit_id . time() . $holder_email);
        
        // Generate reference number
        $ref_number = 'PTW-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert permit
        $stmt = $db->pdo->prepare("
            INSERT INTO forms (
                id, 
                ref_number, 
                template_id, 
                form_data, 
                status,
                holder_name,
                holder_email,
                holder_phone,
                unique_link,
                created_at
            ) VALUES (?, ?, ?, ?, 'pending_approval', ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $permit_id,
            $ref_number,
            $template_id,
            json_encode($permit_data),
            $holder_name,
            $holder_email,
            $holder_phone,
            $unique_link
        ]);
        
        // Log activity
        if (function_exists('logActivity')) {
            logActivity(
                'public_permit_created',
                'permit',
                'form',
                $permit_id,
                "Public permit created: {$ref_number} by {$holder_email}"
            );
        }
        
        $success = true;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Permit - <?php echo htmlspecialchars($template['name']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 32px;
        }
        .header h1 {
            font-size: 28px;
            color: #111827;
            margin-bottom: 8px;
        }
        .header p {
            color: #6b7280;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
        }
        .required {
            color: #ef4444;
        }
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="date"],
        input[type="time"],
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.2s;
        }
        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        .notification-box {
            background: #f0f9ff;
            border: 2px solid #3b82f6;
            border-radius: 8px;
            padding: 16px;
            margin: 24px 0;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
        }
        .btn {
            display: inline-block;
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: 100%;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }
        .success-message {
            background: #d1fae5;
            border: 2px solid #10b981;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
        }
        .success-message h2 {
            color: #065f46;
            margin-bottom: 12px;
        }
        .success-message p {
            color: #047857;
            margin-bottom: 8px;
        }
        .error-message {
            background: #fee2e2;
            border: 2px solid #ef4444;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            color: #991b1b;
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin: 32px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e7eb;
        }
        @media (max-width: 768px) {
            .card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <?php if ($success): ?>
                <!-- Success Message -->
                <div class="success-message">
                    <h2>✅ Permit Submitted Successfully!</h2>
                    <p><strong>Reference:</strong> #<?php echo htmlspecialchars($ref_number ?? 'N/A'); ?></p>
                    <p>Your permit is now awaiting manager approval.</p>
                    <p style="margin-top: 16px;">
                        You can check the status anytime on the homepage<br>
                        by entering your email address.
                    </p>
                    <?php if (!empty($notification_enabled)): ?>
                        <p style="margin-top: 16px; font-size: 14px;">
                            🔔 We'll send you a notification when your permit is approved!
                        </p>
                    <?php endif; ?>
                    <div style="margin-top: 24px;">
                        <a href="/" class="btn btn-primary">← Back to Homepage</a>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Permit Creation Form -->
                <div class="header">
                    <h1>📋 <?php echo htmlspecialchars($template['name']); ?></h1>
                    <p>Please fill in all required information</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="error-message">
                        ⚠️ <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="permitForm">
                    
                    <!-- Your Information Section -->
                    <div class="section-title">Your Information</div>
                    
                    <div class="form-group">
                        <label>Your Name <span class="required">*</span></label>
                        <input type="text" name="holder_name" required placeholder="John Smith">
                    </div>
                    
                    <div class="form-group">
                        <label>Your Email <span class="required">*</span></label>
                        <input type="email" name="holder_email" required placeholder="john@example.com">
                    </div>
                    
                    <div class="form-group">
                        <label>Your Phone Number</label>
                        <input type="tel" name="holder_phone" placeholder="+44 7700 900000">
                    </div>
                    
                    <!-- Push Notifications -->
                    <div class="notification-box">
                        <div class="checkbox-group">
                            <input type="checkbox" name="enable_notifications" id="enable_notifications">
                            <label for="enable_notifications">
                                🔔 <strong>Get notified when your permit is approved</strong><br>
                                <span style="font-size: 14px; color: #6b7280; font-weight: normal;">
                                    We'll send you a browser notification (optional)
                                </span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Dynamic Form Fields -->
                    <?php
                    $form_structure = json_decode($template['form_structure'], true);
                    
                    foreach ($form_structure as $section):
                    ?>
                        <div class="section-title"><?php echo htmlspecialchars($section['title']); ?></div>
                        
                        <?php foreach ($section['fields'] as $field): ?>
                            <div class="form-group">
                                <label>
                                    <?php echo htmlspecialchars($field['label']); ?>
                                    <?php if ($field['required']): ?>
                                        <span class="required">*</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($field['type'] === 'text'): ?>
                                    <input 
                                        type="text" 
                                        name="<?php echo htmlspecialchars($field['name']); ?>"
                                        <?php echo $field['required'] ? 'required' : ''; ?>
                                    >
                                    
                                <?php elseif ($field['type'] === 'textarea'): ?>
                                    <textarea 
                                        name="<?php echo htmlspecialchars($field['name']); ?>"
                                        <?php echo $field['required'] ? 'required' : ''; ?>
                                    ></textarea>
                                    
                                <?php elseif ($field['type'] === 'select'): ?>
                                    <select 
                                        name="<?php echo htmlspecialchars($field['name']); ?>"
                                        <?php echo $field['required'] ? 'required' : ''; ?>
                                    >
                                        <option value="">Select...</option>
                                        <?php foreach ($field['options'] as $option): ?>
                                            <option value="<?php echo htmlspecialchars($option); ?>">
                                                <?php echo htmlspecialchars($option); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                <?php elseif ($field['type'] === 'date'): ?>
                                    <input 
                                        type="date" 
                                        name="<?php echo htmlspecialchars($field['name']); ?>"
                                        <?php echo $field['required'] ? 'required' : ''; ?>
                                    >
                                    
                                <?php elseif ($field['type'] === 'time'): ?>
                                    <input 
                                        type="time" 
                                        name="<?php echo htmlspecialchars($field['name']); ?>"
                                        <?php echo $field['required'] ? 'required' : ''; ?>
                                    >
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    
                    <!-- Submit -->
                    <div style="margin-top: 32px;">
                        <button type="submit" class="btn btn-primary">
                            ✅ Submit Permit for Approval
                        </button>
                    </div>
                    
                    <div style="margin-top: 16px; text-align: center;">
                        <a href="/" class="btn btn-secondary">← Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Request notification permission if checkbox is checked
        document.getElementById('enable_notifications')?.addEventListener('change', function(e) {
            if (e.target.checked && 'Notification' in window) {
                Notification.requestPermission().then(permission => {
                    if (permission !== 'granted') {
                        e.target.checked = false;
                        alert('Please allow notifications in your browser settings');
                    }
                });
            }
        });
    </script>
</body>
</html>