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

// Get template ID from query string or resume an existing draft via unique link
$template_id = $_GET['template'] ?? null;
$draft_link = $_GET['draft'] ?? null;
$reopen_permit_id = $_GET['reopen'] ?? null;

if (!$template_id && !$draft_link && !$reopen_permit_id) {
    header('Location: ' . $app->url('/'));
    exit;
}

// Load existing draft if present
$existingPermit = null;
$isUpdate = false;  // true = edit draft, false = create new
if ($draft_link) {
    try {
        $st = $db->pdo->prepare("SELECT * FROM forms WHERE unique_link = ? AND status = 'draft' LIMIT 1");
        $st->execute([$draft_link]);
        $existingPermit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingPermit) {
            $template_id = $existingPermit['template_id'];
            $isUpdate = true;  // This is updating an existing draft
        }
    } catch (Exception $e) {
        // Ignore; we'll fall back to normal flow
    }
}

// Load permit to reopen if present - creates a NEW permit based on existing data
if ($reopen_permit_id) {
    try {
        $st = $db->pdo->prepare("SELECT * FROM forms WHERE id = ? LIMIT 1");
        $st->execute([$reopen_permit_id]);
        $existingPermit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingPermit) {
            $template_id = $existingPermit['template_id'];
            $isUpdate = false;  // Always create a NEW permit when reopening
        }
    } catch (Exception $e) {
        // Ignore; we'll fall back to normal flow
    }
}

// Get template details
try {
    $stmt = $db->pdo->prepare("SELECT * FROM form_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        header('Location: ' . $app->url('/'));
        exit;
    }
} catch (Exception $e) {
    die("Error loading template: " . $e->getMessage());
}

$formStructure = [];

try {
    if (!empty($template['form_structure'])) {
        $decodedStructure = json_decode((string)$template['form_structure'], true);
        if (is_array($decodedStructure)) {
            $formStructure = $decodedStructure;
        }
    }

    if ((empty($formStructure) || !is_array($formStructure)) && !empty($template['json_schema'])) {
        $schemaDecoded = json_decode((string)$template['json_schema'], true);
        if (is_array($schemaDecoded)) {
            $formStructure = \Permits\FormTemplateSeeder::buildPublicFormStructure($schemaDecoded);
        }
    }
} catch (\Throwable $e) {
    $formStructure = [];
}

// Handle form submission
$success = false;
$error = null;
$permit_id = null;
$unique_link = null;
// Prefill data when editing a draft
$existingData = [];
if ($existingPermit && $isUpdate) {
    // Only set IDs when editing an existing draft
    $permit_id = $existingPermit['id'];
    $unique_link = $existingPermit['unique_link'];
    $existingData = json_decode((string)($existingPermit['form_data'] ?? ''), true) ?: [];
} elseif ($existingPermit && !$isUpdate) {
    // When reopening a permit, only use the form data, not the IDs
    $existingData = json_decode((string)($existingPermit['form_data'] ?? ''), true) ?: [];
}

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
        // Generate IDs early so we can store media predictably
        if (!$isUpdate) {
            $permit_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            $unique_link = md5($permit_id . time() . $holder_email);
        }
        $ref_number = $existingPermit['ref_number'] ?? ('PTW-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT));

        // Collect permit data using the parsed structure (values + optional notes)
        $permit_data = [];
        foreach ($formStructure as $section) {
            if (!isset($section['fields']) || !is_array($section['fields'])) {
                continue;
            }

            foreach ($section['fields'] as $field) {
                if (!is_array($field) || empty($field['name'])) {
                    continue;
                }

                $fieldName = (string)$field['name'];
                $rawValue = $_POST[$fieldName] ?? ($existingData[$fieldName] ?? '');

                if (is_array($rawValue)) {
                    $rawValue = array_values(array_filter(array_map('trim', $rawValue), static function ($value) {
                        return $value !== '' && $value !== null;
                    }));
                    $value = implode(', ', $rawValue);
                } else {
                    $value = trim((string)$rawValue);
                }

                $permit_data[$fieldName] = $value;

                // Optional note paired with tri-state or any field
                $noteKey = $fieldName . '_note';
                if (isset($_POST[$noteKey])) {
                    $permit_data[$noteKey] = trim((string)$_POST[$noteKey]);
                } elseif (isset($existingData[$noteKey])) {
                    $permit_data[$noteKey] = trim((string)$existingData[$noteKey]);
                }
            }
        }

        // Handle media uploads (images/videos) for fields ending with _media
        $uploadErrors = [];
        $uploadedAny = false;
        $baseUploadDir = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $permit_id;
        if (!is_dir($baseUploadDir)) {
            @mkdir($baseUploadDir, 0775, true);
        }
        $allowedTypes = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/quicktime','video/webm'];
        foreach ($formStructure as $section) {
            if (empty($section['fields']) || !is_array($section['fields'])) { continue; }
            foreach ($section['fields'] as $field) {
                if (!is_array($field) || empty($field['name'])) { continue; }
                $name = (string)$field['name'];
                $mediaKey = $name . '_media';
                if (empty($_FILES[$mediaKey]) || empty($_FILES[$mediaKey]['name'])) { continue; }
                $files = $_FILES[$mediaKey];
                $paths = [];
                $count = is_array($files['name']) ? count($files['name']) : 0;
                for ($i=0; $i<$count; $i++) {
                    $origName = (string)$files['name'][$i];
                    if ($origName === '') { continue; }
                    $tmp = (string)$files['tmp_name'][$i];
                    $type = (string)$files['type'][$i];
                    $err = (int)$files['error'][$i];
                    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { continue; }
                    if (!in_array($type, $allowedTypes, true)) { $uploadErrors[] = 'Rejected file type for ' . $origName; continue; }
                    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($origName));
                    $target = $baseUploadDir . DIRECTORY_SEPARATOR . (time() . '_' . $i . '_' . $safeName);
                    if (@move_uploaded_file($tmp, $target)) {
                        $uploadedAny = true;
                        $rel = 'uploads/' . $permit_id . '/' . basename($target);
                        $paths[] = $rel;
                    }
                }
                if (!empty($paths)) {
                    $permit_data[$mediaKey] = implode(', ', $paths);
                } elseif (!empty($existingData[$mediaKey])) {
                    // Preserve previously uploaded media when editing and no new files were added
                    $permit_data[$mediaKey] = (string)$existingData[$mediaKey];
                }
            }
        }
        
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
    <title><?php echo $existingPermit ? 'Edit Draft' : 'Create Permit'; ?> - <?php echo htmlspecialchars($template['name']); ?></title>
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
        /* Choice button group (Yes / No / N/A) */
        .choice-group { display: flex; flex-wrap: wrap; gap: 8px; }
        .choice-group.vertical { flex-direction: column; gap: 10px; }
        /* Hide native radios by default; labels are the visual buttons */
        .choice-input { position: absolute; opacity: 0; width: 0; height: 0; }
        .choice-pill {
            display: inline-block;
            padding: 14px 18px;
            border-radius: 999px;
            border: 2px solid #e5e7eb;
            background: #ffffff;
            color: #374151;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            transition: all .15s ease;
            text-align: center;
            min-width: 90px;
        }
        .choice-pill:empty::before { content: attr(data-label); }
        .choice-pill:hover { border-color: #a5b4fc; box-shadow: 0 2px 8px rgba(102,126,234,.15); }
        .choice-input:focus + .choice-pill { outline: 2px solid #a5b4fc; outline-offset: 2px; }
        .choice-group.vertical .choice-pill { width: 100%; border-radius: 24px; }
        .choice-input:checked + .choice-pill { color: #ffffff; border-color: transparent; box-shadow: 0 6px 18px rgba(0,0,0,.15); }
        .choice-input:checked + .choice-pill.choice-yes { background: #9bb837; }
        .choice-input:checked + .choice-pill.choice-no { background: #e24b4b; }
        .choice-input:checked + .choice-pill.choice-na { background: #277ba6; }
        .choice-pill.choice-yes:hover { border-color: #9bb837; }
        .choice-pill.choice-no:hover { border-color: #e24b4b; }
        .choice-pill.choice-na:hover { border-color: #277ba6; }
        /* Mobile fallback: show native radios as well for maximum compatibility */
        @media (max-width: 640px) {
            .choice-group { gap: 10px; }
            .choice-input { position: static; opacity: 1; width: 18px; height: 18px; margin-right: 8px; }
            .choice-pill { display: inline-flex; align-items: center; gap: 10px; min-height: 44px; padding: 10px 14px; }
        }
        /* Per-question utilities */
        .field-toolbar { display:flex; gap:12px; align-items:center; margin: 8px 0 6px; flex-wrap: wrap; }
        .tool-link { display:inline-flex; align-items:center; gap:6px; color:#4f46e5; background:#eef2ff; border:1px solid #c7d2fe; padding:8px 12px; border-radius:10px; font-weight:600; text-decoration:none; cursor:pointer; }
        .tool-link:hover { background:#e0e7ff; }
        .note-box { display:none; margin-top:8px; }
        .note-box textarea { width:100%; min-height:80px; border:2px solid #e5e7eb; border-radius:8px; padding:10px; }
        .media-box { display:none; margin-top:8px; }
    .media-box input[type=file] { display:block; width:100%; padding:10px; border:2px dashed #c7d2fe; border-radius:10px; background:#f8fafc; }
    .media-actions { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:8px; }
    .media-note { font-size:12px;color:#64748b;margin-top:6px; }
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
                    <?php if (!empty($isDraftAction)): ?>
                        <h2>📝 Draft Saved</h2>
                        <p>You can resume editing this permit using the link below.</p>
                    <?php else: ?>
                        <h2>✅ Permit Submitted Successfully!</h2>
                        <p><strong>Reference:</strong> #<?php echo htmlspecialchars($ref_number ?? 'N/A'); ?></p>
                        <p>Your permit is now awaiting manager approval.</p>
                    <?php endif; ?>
                    <p style="margin-top: 16px;">
                        You can check the status anytime on the homepage<br>
                        by entering your email address.
                    </p>
                    <?php if (!empty($unique_link)): ?>
                        <div style="margin-top: 12px; font-size: 14px; color:#374151;">
                            <div><strong>Edit Link:</strong> <a href="<?php echo htmlspecialchars($app->url('create-permit-public.php?draft=' . urlencode($unique_link))); ?>">Resume editing</a></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($notification_enabled)): ?>
                        <p style="margin-top: 16px; font-size: 14px;">
                            🔔 We'll send you a notification when your permit is approved!
                        </p>
                    <?php endif; ?>
                    <div style="margin-top: 24px;">
                        <a href="<?php echo htmlspecialchars($app->url('/')); ?>" class="btn btn-primary">← Back to Homepage</a>
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
                
                <form method="POST" id="permitForm" enctype="multipart/form-data">
                    <?php if ($existingPermit): ?>
                        <input type="hidden" name="permit_id" value="<?php echo htmlspecialchars($existingPermit['id']); ?>">
                    <?php endif; ?>
                    
                    <!-- Your Information Section -->
                    <div class="section-title">Your Information</div>
                    
                    <div class="form-group">
                        <label>Your Name <span class="required">*</span></label>
                        <input type="text" name="holder_name" required placeholder="John Smith" value="<?php echo htmlspecialchars($existingPermit['holder_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Your Email <span class="required">*</span></label>
                        <input type="email" name="holder_email" required placeholder="john@example.com" value="<?php echo htmlspecialchars($existingPermit['holder_email'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Your Phone Number</label>
                        <input type="tel" name="holder_phone" placeholder="+44 7700 900000" value="<?php echo htmlspecialchars($existingPermit['holder_phone'] ?? ''); ?>">
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
                    <?php foreach ($formStructure as $section):
                        if (!isset($section['fields']) || !is_array($section['fields'])) {
                            continue;
                        }
                    ?>
                        <div class="section-title"><?php echo htmlspecialchars($section['title']); ?></div>
                        
                        <?php foreach ($section['fields'] as $field):
                            if (!is_array($field) || empty($field['name'])) {
                                continue;
                            }

                            $fieldType = $field['type'] ?? 'text';
                            $fieldName = (string)$field['name'];
                            $fieldLabel = (string)($field['label'] ?? $fieldName);
                            $fieldRequired = !empty($field['required']);
                            $fieldPlaceholder = (string)($field['placeholder'] ?? '');
                            $fieldOptions = $field['options'] ?? [];
                        ?>
                            <div class="form-group">
                                <label>
                                    <?php echo htmlspecialchars($fieldLabel); ?>
                                    <?php if ($fieldRequired): ?>
                                        <span class="required">*</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($fieldType === 'textarea'): ?>
                                    <textarea 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                    ><?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?></textarea>
                                <?php elseif ($fieldType === 'select'): ?>
                                    <select 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                    >
                                        <option value="">Select...</option>
                                        <?php foreach ($fieldOptions as $option):
                                            if (!is_array($option)) {
                                                $optionValue = $optionLabel = (string)$option;
                                            } else {
                                                $optionValue = (string)($option['value'] ?? ($option[0] ?? ''));
                                                $optionLabel = (string)($option['label'] ?? ($option[1] ?? $optionValue));
                                            }
                                            if ($optionValue === '') { continue; }
                                        ?>
                                            <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo ((string)($existingData[$fieldName] ?? '') === (string)$optionValue) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($optionLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($fieldType === 'select_multiple'): ?>
                                    <select 
                                        name="<?php echo htmlspecialchars($fieldName); ?>[]"
                                        multiple
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                    >
                                        <?php $existingVals = array_values(array_filter(array_map('trim', explode(',', (string)($existingData[$fieldName] ?? ''))))); ?>
                                        <?php foreach ($fieldOptions as $option):
                                            if (!is_array($option)) {
                                                $optionValue = $optionLabel = (string)$option;
                                            } else {
                                                $optionValue = (string)($option['value'] ?? ($option[0] ?? ''));
                                                $optionLabel = (string)($option['label'] ?? ($option[1] ?? $optionValue));
                                            }
                                            if ($optionValue === '') { continue; }
                                        ?>
                                            <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo in_array((string)$optionValue, $existingVals, true) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($optionLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($fieldType === 'radio'): ?>
                                    <?php $isScoreItem = !empty($field['scoreItem']); ?>
                                    <div class="choice-group <?php echo $isScoreItem ? 'vertical' : ''; ?>" role="radiogroup" aria-label="<?php echo htmlspecialchars($fieldLabel); ?>">
                                        <?php 
                                            $firstOption = true; 
                                            $existingVal = (string)($existingData[$fieldName] ?? '');
                                            foreach ($fieldOptions as $option):
                                                if (!is_array($option)) {
                                                    $optionValue = $optionLabel = (string)$option;
                                                } else {
                                                    $optionValue = (string)($option['value'] ?? ($option[0] ?? ''));
                                                    $optionLabel = (string)($option['label'] ?? ($option[1] ?? $optionValue));
                                                }
                                                if ($optionValue === '') { continue; }
                                                $optionId = $fieldName . '_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($optionValue));
                                                $variant = in_array(strtolower($optionValue), ['yes','no','na'], true) ? 'choice-' . strtolower($optionValue) : '';
                                        ?>
                                            <input class="choice-input" type="radio" name="<?php echo htmlspecialchars($fieldName); ?>" value="<?php echo htmlspecialchars($optionValue); ?>" id="<?php echo htmlspecialchars($optionId); ?>" <?php echo ($fieldRequired && $firstOption) ? 'required' : ''; ?> <?php echo ($existingVal !== '' && (string)$existingVal === (string)$optionValue) ? 'checked' : ''; ?>>
                                            <label class="choice-pill <?php echo htmlspecialchars($variant); ?>" for="<?php echo htmlspecialchars($optionId); ?>"><?php echo htmlspecialchars($optionLabel); ?></label>
                                        <?php $firstOption = false; endforeach; ?>
                                    </div>
                                    <?php if ($isScoreItem): ?>
                                        <div class="field-toolbar">
                                            <button type="button" class="tool-link toggle-note" data-for="<?php echo htmlspecialchars($fieldName); ?>">📝 Note</button>
                                            <button type="button" class="tool-link toggle-media" data-for="<?php echo htmlspecialchars($fieldName); ?>">🖼️ Media</button>
                                        </div>
                                        <div class="note-box" id="note_<?php echo htmlspecialchars($fieldName); ?>">
                                            <textarea name="<?php echo htmlspecialchars($fieldName); ?>_note" placeholder="Add a note..."><?php echo htmlspecialchars((string)($existingData[$fieldName . '_note'] ?? '')); ?></textarea>
                                        </div>
                                        <div class="media-box" id="media_<?php echo htmlspecialchars($fieldName); ?>">
                                            <div class="media-actions">
                                                <button type="button" class="btn media-btn" data-target="camera" data-name="<?php echo htmlspecialchars($fieldName); ?>_media">📷 Take Photo/Video</button>
                                                <button type="button" class="btn btn-secondary media-btn" data-target="gallery" data-name="<?php echo htmlspecialchars($fieldName); ?>_media">🖼️ Choose from Gallery</button>
                                            </div>
                                            <input class="hidden-input" type="file" name="<?php echo htmlspecialchars($fieldName); ?>_media[]" accept="image/*,video/*" capture="environment" multiple style="display:none">
                                            <input class="hidden-input" type="file" name="<?php echo htmlspecialchars($fieldName); ?>_media[]" accept="image/*,video/*" multiple style="display:none">
                                            <div class="media-note">Tip: On mobile, use your camera or photo library.</div>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($fieldType === 'date'): ?>
                                    <input 
                                        type="date" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'time'): ?>
                                    <input 
                                        type="time" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'datetime'): ?>
                                    <input 
                                        type="datetime-local" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'number'): ?>
                                    <input 
                                        type="number" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'email'): ?>
                                    <input 
                                        type="email" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'tel'): ?>
                                    <input 
                                        type="tel" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php else: ?>
                                    <input 
                                        type="text" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    
                    <!-- Submit -->
                    <div style="margin-top: 32px;">
                        <div style="display:flex; gap:12px; flex-wrap:wrap;">
                            <button type="submit" name="action" value="save_draft" class="btn btn-secondary" style="flex:1; min-width:200px;">
                                📝 Save Draft
                            </button>
                            <button type="submit" name="action" value="submit" class="btn btn-primary" style="flex:2; min-width:240px;">
                                ✅ Submit Permit for Approval
                            </button>
                        </div>
                    </div>
                    
                    <div style="margin-top: 16px; text-align: center;">
                        <a href="<?php echo htmlspecialchars($app->url('/')); ?>" class="btn btn-secondary">← Cancel</a>
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
        // Save Draft should bypass HTML5 required on dynamic fields
        (function(){
            var form = document.getElementById('permitForm');
            if (!form) return;
            var saveBtn = form.querySelector('button[name="action"][value="save_draft"]');
            if (saveBtn) {
                saveBtn.addEventListener('click', function(){
                    form.dataset.draft = '1';
                });
            }
            form.addEventListener('submit', function(){
                if (form.dataset.draft === '1') {
                    var nodes = form.querySelectorAll('[required]');
                    nodes.forEach(function(el){
                        if (el.name !== 'holder_name' && el.name !== 'holder_email') {
                            el.removeAttribute('required');
                        }
                    });
                }
            });
        })();
        // Toggle Note/Media per score item
        document.addEventListener('click', function(e){
            var t = e.target;
            // Proxy clicks for media buttons to the appropriate hidden file inputs
            if (t && t.closest && t.closest('.media-btn')) {
                var btn = t.closest('.media-btn');
                var box = btn.closest('.media-box');
                if (box) {
                    var inputs = box.querySelectorAll('input[type=file]');
                    var target = btn.getAttribute('data-target');
                    var input = Array.prototype.find.call(inputs, function(el){
                        return target === 'camera' ? el.hasAttribute('capture') : !el.hasAttribute('capture');
                    });
                    if (input) { input.click(); }
                }
            }
            if (t && t.classList && t.classList.contains('toggle-note')) {
                var name = t.getAttribute('data-for');
                var box = document.getElementById('note_' + name);
                if (box) { box.style.display = (box.style.display === 'block') ? 'none' : 'block';
                    if (box.style.display === 'block') { var ta = box.querySelector('textarea'); if (ta) ta.focus(); }
                }
            }
            if (t && t.classList && t.classList.contains('toggle-media')) {
                var name2 = t.getAttribute('data-for');
                var box2 = document.getElementById('media_' + name2);
                if (box2) { box2.style.display = (box2.style.display === 'block') ? 'none' : 'block';
                    if (box2.style.display === 'block') { var fi = box2.querySelector('input[type=file]'); if (fi) fi.focus(); }
                }
            }
        });
        // Auto-open note box when there's existing content
        document.addEventListener('DOMContentLoaded', function(){
            document.querySelectorAll('.note-box').forEach(function(box){
                var ta = box.querySelector('textarea');
                if (ta && ta.value.trim() !== '') { box.style.display = 'block'; }
            });
        });
    </script>
</body>
</html>