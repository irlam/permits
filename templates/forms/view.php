<?php
require_once __DIR__ . '/../../src/cache-helper.php';
$base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/') . rtrim((string)($_ENV['APP_BASE_PATH'] ?? '/'), '/');
$schema = json_decode($template['json_schema'], true);
$metadata = json_decode($form['metadata'], true);
$metaFields = $metadata['meta'] ?? [];
$items = $metadata['items'] ?? [];
$signatures = $metadata['signatures'] ?? [];
$sectionFieldValues = $metadata['sections'] ?? [];

if (!function_exists('resolveSectionKey')) {
  function resolveSectionKey(array $section, int $index): string
  {
    $raw = '';
    if (isset($section['key']) && is_string($section['key'])) {
      $raw = $section['key'];
    } elseif (isset($section['id']) && is_string($section['id'])) {
      $raw = $section['id'];
    } elseif (isset($section['title']) && is_string($section['title'])) {
      $raw = $section['title'];
    }

    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', $raw ?? ''), '_'));
    return $slug !== '' ? $slug : 'section_' . $index;
  }
}

if (!function_exists('resolveFieldKey')) {
  function resolveFieldKey(array $field, int $index, string $sectionKey): string
  {
    $raw = '';
    if (isset($field['key']) && is_string($field['key'])) {
      $raw = $field['key'];
    } elseif (isset($field['id']) && is_string($field['id'])) {
      $raw = $field['id'];
    } elseif (isset($field['name']) && is_string($field['name'])) {
      $raw = $field['name'];
    } elseif (isset($field['label']) && is_string($field['label'])) {
      $raw = $field['label'];
    }

    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', $raw ?? ''), '_'));
    if ($slug === '') {
      $slug = $sectionKey . '_field_' . $index;
    }

    return $slug;
  }
}
$title = $schema['title'] ?? 'Form';

// Status badge colors
$statusColors = [
  'draft' => '#6b7280',
  'pending' => '#f59e0b',
  'issued' => '#3b82f6',
  'active' => '#10b981',
  'expired' => '#ef4444',
  'closed' => '#6b7280',
];
$statusColor = $statusColors[$form['status']] ?? '#6b7280';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="manifest" href="<?=$base?>/manifest.webmanifest">
  <meta name="theme-color" content="#0ea5e9">
  <title><?=htmlspecialchars($form['ref'] ?? 'Form')?> - <?=htmlspecialchars($title)?></title>
  <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
  <style>
    .view-wrap{max-width:1200px;margin:0 auto;padding:16px}
    .actions{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
    .status-badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:14px;font-weight:600;color:#fff;background:<?=$statusColor?>}
    .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
    .info-item{background:#111827;border:1px solid #1f2937;border-radius:8px;padding:12px}
    .info-label{font-size:12px;color:#94a3b8;margin-bottom:4px}
    .info-value{font-size:16px;color:#e5e7eb;font-weight:500}
    .section-card{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:16px;margin-bottom:16px}
    .section-title{font-size:18px;font-weight:600;margin-bottom:12px;color:#e5e7eb}
    .checklist-item{border:1px solid #1f2937;border-radius:8px;padding:12px;margin-bottom:8px;background:#0a101a}
    .checklist-status{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;margin-left:8px}
    .status-pass{background:#10b981;color:#fff}
    .status-fail{background:#ef4444;color:#fff}
    .status-na{background:#6b7280;color:#fff}
    .sig-display{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}
    .sig-box{border:1px solid #1f2937;border-radius:8px;padding:8px;background:#0a101a}
    .sig-img{width:100%;height:auto;background:#fff;border-radius:4px}
    .attachment-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
    .attachment-item{border:1px solid #1f2937;border-radius:8px;padding:8px;background:#0a101a;text-align:center}
    .attachment-img{width:100%;height:120px;object-fit:cover;border-radius:4px;margin-bottom:8px}
    .event-item{border-left:3px solid #3b82f6;padding:8px 12px;margin-bottom:8px;background:#0a101a;border-radius:4px}
    .event-time{font-size:12px;color:#94a3b8}
    .event-type{font-weight:600;color:#e5e7eb;text-transform:capitalize}
    @media print{
      html,body{background:#fff!important;color:#111!important}
      .top,.actions{display:none!important}
      .card,.section-card,.info-item{background:#fff!important;border-color:#bbb!important}
    }
  </style>
</head>
<body>
<header class="top">
  <h1>View Permit</h1>
  <a class="btn" href="<?=$base?>/">← Back to Home</a>
</header>

<div class="view-wrap">
  <div class="actions">
    <button class="btn" onclick="window.print()">🖨️ Print / PDF</button>
  <a class="btn" href="<?=$base?>/form/<?=htmlspecialchars($form['id'])?>/edit">✏️ Edit</a>
  <button class="btn" onclick="if(confirm('Copy this form to create a new one?')) window.location.href='<?=$base?>/form/<?=htmlspecialchars($form['id'])?>/duplicate'">📋 Duplicate</button>
    <button class="btn" onclick="toggleQR()">📱 QR Code</button>
    <button class="btn" onclick="if(confirm('Delete this form?')) deleteForm('<?=htmlspecialchars($form['id'])?>')">🗑️ Delete</button>
    <span class="status-badge"><?=strtoupper(htmlspecialchars($form['status']))?></span>
  </div>

  <!-- QR Code Section (Hidden by default) -->
  <div id="qrSection" style="display:none;margin-bottom:16px;padding:16px;background:#111827;border:1px solid #1f2937;border-radius:12px;text-align:center">
    <h3 style="margin:0 0 12px 0;color:#e5e7eb">📱 QR Code for this Permit</h3>
    <p style="color:#94a3b8;margin-bottom:12px;font-size:14px">Scan to view permit on mobile device</p>
    <div style="background:#fff;display:inline-block;padding:16px;border-radius:8px">
  <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?=urlencode(($base ?: 'http://localhost') . '/form/' . $form['id'])?>" 
           alt="QR Code" 
           style="display:block;width:200px;height:200px">
    </div>
    <p style="margin-top:12px;color:#94a3b8;font-size:12px">Ref: <?=htmlspecialchars($form['ref'])?></p>
    <button class="btn" onclick="downloadQR()" style="margin-top:8px">💾 Download QR Code</button>
  </div>

  <div class="section-card">
    <div class="section-title"><?=htmlspecialchars($title)?></div>
    <div class="info-grid">
      <div class="info-item">
        <div class="info-label">Permit Reference</div>
        <div class="info-value"><?=htmlspecialchars($form['ref'] ?? 'N/A')?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Site / Block</div>
        <div class="info-value"><?=htmlspecialchars($form['site_block'] ?? 'N/A')?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Valid From</div>
        <div class="info-value"><?=htmlspecialchars($form['valid_from'] ?? 'N/A')?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Valid To</div>
        <div class="info-value"><?=htmlspecialchars($form['valid_to'] ?? 'N/A')?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Status</div>
        <div class="info-value"><?=htmlspecialchars($form['status'])?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Created</div>
        <div class="info-value"><?=htmlspecialchars($form['created_at'])?></div>
      </div>
    </div>
  </div>

  <!-- Form Fields -->
  <div class="section-card">
    <div class="section-title">Form Details</div>
    <div class="info-grid">
      <?php foreach(($schema['meta']['fields'] ?? []) as $field): ?>
        <?php $value = $metaFields[$field['key']] ?? 'N/A'; ?>
        <div class="info-item">
          <div class="info-label"><?=htmlspecialchars($field['label'])?></div>
          <div class="info-value"><?=htmlspecialchars($value)?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Checklist Sections -->
  <?php foreach(($schema['sections'] ?? []) as $sIdx => $section):
        $sectionKey = resolveSectionKey($section, $sIdx);
        $sectionFields = $section['fields'] ?? [];
        $storedSection = $sectionFieldValues[$sectionKey] ?? [];
  ?>
    <div class="section-card">
      <div class="section-title"><?=htmlspecialchars($section['title'])?></div>
      <?php if (!empty($sectionFields) && is_array($sectionFields)): ?>
        <div class="info-grid" style="margin-bottom:12px;">
          <?php foreach ($sectionFields as $fIdx => $field):
              if (!is_array($field)) { continue; }
              $fieldLabel = (string)($field['label'] ?? 'Field');
              $fieldKey = resolveFieldKey($field, $fIdx, $sectionKey);
              $rawValue = $storedSection[$fieldKey] ?? null;
              if (is_array($rawValue)) {
                  $displayValue = implode(', ', array_map('strval', $rawValue));
              } elseif ($rawValue === null || $rawValue === '') {
                  $displayValue = 'N/A';
              } else {
                  $displayValue = (string)$rawValue;
              }
          ?>
            <div class="info-item">
              <div class="info-label"><?=htmlspecialchars($fieldLabel)?></div>
              <div class="info-value"><?=htmlspecialchars($displayValue)?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php foreach(($section['items'] ?? []) as $iIdx => $itemText): ?>
        <?php 
          $itemKey = "s{$sIdx}_{$iIdx}";
          $itemData = $items[$itemKey] ?? [];
          $done = isset($itemData['done']) && $itemData['done'] ? '✓' : '☐';
          $status = $itemData['status'] ?? 'na';
          $note = $itemData['note'] ?? '';
        ?>
        <div class="checklist-item">
          <div>
            <span><?=$done?></span>
            <?=htmlspecialchars($itemText)?>
            <span class="checklist-status status-<?=$status?>"><?=strtoupper($status)?></span>
          </div>
          <?php if($note): ?>
            <div style="margin-top:8px;padding:8px;background:#111827;border-radius:4px;font-size:14px;color:#94a3b8">
              <strong>Note:</strong> <?=htmlspecialchars($note)?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <!-- Signatures -->
  <?php if(!empty($signatures)): ?>
    <div class="section-card">
      <div class="section-title">Signatures</div>
      <div class="sig-display">
        <?php foreach(($schema['signatures'] ?? []) as $sigKey): ?>
          <?php $sigData = $signatures[$sigKey] ?? null; ?>
          <div class="sig-box">
            <div style="font-weight:600;margin-bottom:4px"><?=ucwords(str_replace('_', ' ', $sigKey))?></div>
            <?php if($sigData): ?>
              <img class="sig-img" src="<?=htmlspecialchars($sigData)?>" alt="Signature">
            <?php else: ?>
              <div style="padding:40px;text-align:center;color:#6b7280;border:1px dashed #1f2937;border-radius:4px">Not signed</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Attachments -->
  <div class="section-card">
    <div class="section-title">Attachments (<?=count($attachments)?>)</div>
    
    <!-- Upload Form -->
    <form id="uploadForm" style="margin-bottom:16px;padding:12px;background:#0a101a;border:1px dashed #1f2937;border-radius:8px">
      <input type="file" id="fileInput" accept="image/*,.pdf,.doc,.docx" style="margin-right:8px">
      <button type="submit" class="btn">📤 Upload</button>
      <span id="uploadStatus" style="margin-left:8px;color:#94a3b8"></span>
    </form>
    
    <?php if(!empty($attachments)): ?>
      <div class="attachment-grid">
        <?php foreach($attachments as $att): ?>
          <div class="attachment-item" id="att-<?=htmlspecialchars($att['id'])?>">
            <?php if(strpos($att['kind'], 'image') !== false): ?>
              <img class="attachment-img" src="<?=htmlspecialchars($att['url'])?>" alt="Attachment">
            <?php else: ?>
              <div class="attachment-img" style="display:flex;align-items:center;justify-content:center;background:#1f2937">
                📄
              </div>
            <?php endif; ?>
            <div style="font-size:12px;color:#94a3b8"><?=htmlspecialchars($att['kind'])?></div>
            <div style="display:flex;gap:4px;margin-top:4px">
              <a href="<?=htmlspecialchars($att['url'])?>" class="btn" style="font-size:11px;padding:4px 8px;flex:1" target="_blank">View</a>
              <button class="btn" style="font-size:11px;padding:4px 8px" onclick="deleteAttachment('<?=htmlspecialchars($att['id'])?>')">🗑️</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div style="padding:20px;text-align:center;color:#6b7280">No attachments yet</div>
    <?php endif; ?>
  </div>

  <!-- Event History -->
  <?php if(!empty($events)): ?>
    <div class="section-card">
      <div class="section-title">History</div>
      <?php foreach($events as $evt): ?>
        <div class="event-item">
          <div class="event-type"><?=htmlspecialchars($evt['type'])?></div>
          <div class="event-time">
            <?=htmlspecialchars($evt['at'])?> 
            <?php if($evt['by_user']): ?>by <?=htmlspecialchars($evt['by_user'])?><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<script>
function deleteForm(id) {
  fetch('/api/forms/' + id, { method: 'DELETE' })
    .then(r => r.json())
    .then(data => {
      if(data.ok) {
        alert('Form deleted');
        window.location.href = '/';
      } else {
        alert('Error: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(err => alert('Error: ' + err));
}

// QR Code functions
function toggleQR() {
  const qrSection = document.getElementById('qrSection');
  qrSection.style.display = qrSection.style.display === 'none' ? 'block' : 'none';
}

function downloadQR() {
  const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=<?=urlencode(($base ?: 'http://localhost') . '/form/' . $form['id'])?>';
  const a = document.createElement('a');
  a.href = qrUrl;
  a.download = 'permit-qr-<?=htmlspecialchars($form['ref'])?>.png';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

// File upload handling
document.getElementById('uploadForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const fileInput = document.getElementById('fileInput');
  const statusEl = document.getElementById('uploadStatus');
  
  if(!fileInput.files.length) {
    statusEl.textContent = 'Please select a file';
    statusEl.style.color = '#ef4444';
    return;
  }
  
  const formData = new FormData();
  formData.append('file', fileInput.files[0]);
  
  statusEl.textContent = 'Uploading...';
  statusEl.style.color = '#3b82f6';
  
  fetch('/api/forms/<?=htmlspecialchars($form['id'])?>/attachments', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if(data.ok) {
      statusEl.textContent = '✓ Uploaded! Refreshing...';
      statusEl.style.color = '#10b981';
      setTimeout(() => location.reload(), 1000);
    } else {
      statusEl.textContent = '✗ ' + (data.error || 'Upload failed');
      statusEl.style.color = '#ef4444';
    }
  })
  .catch(err => {
    statusEl.textContent = '✗ Error: ' + err;
    statusEl.style.color = '#ef4444';
  });
});

function deleteAttachment(id) {
  if(!confirm('Delete this attachment?')) return;
  
  fetch('/api/attachments/' + id, { method: 'DELETE' })
    .then(r => r.json())
    .then(data => {
      if(data.ok) {
        document.getElementById('att-' + id).remove();
        alert('Attachment deleted');
      } else {
        alert('Error: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(err => alert('Error: ' + err));
}
</script>
</body>
</html>
