<?php
require_once __DIR__ . '/../../src/cache-helper.php';
$schema = json_decode($schemaJson, true);
$title  = $schema['title'] ?? 'Form';
$meta = $existingData['meta'] ?? [];
$items = $existingData['items'] ?? [];
$sectionValues = $existingData['sections'] ?? [];

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

if (!function_exists('normaliseFieldOptions')) {
  function normaliseFieldOptions($options): array
  {
    $normalised = [];

    if (!is_iterable($options)) {
      return $normalised;
    }

    foreach ($options as $option) {
      if (is_array($option)) {
        $value = (string)($option['value'] ?? ($option[0] ?? ''));
        if ($value === '') {
          continue;
        }
        $label = (string)($option['label'] ?? ($option[1] ?? $value));
      } else {
        $value = (string)$option;
        if ($value === '') {
          continue;
        }
        $label = $value;
      }

      $normalised[] = [
        'value' => $value,
        'label' => $label,
      ];
    }

    return $normalised;
  }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Edit: <?=htmlspecialchars($title)?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0ea5e9">
  <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
  <style>
    .status-selector{margin:16px 0;padding:16px;background:#111827;border:1px solid #1f2937;border-radius:12px}
    .status-selector label{display:block;margin-bottom:8px;font-weight:600}
    .status-selector select{width:100%;padding:10px;background:#0a101a;border:1px solid #1f2937;border-radius:8px;color:#e5e7eb;font-size:16px}
  </style>
</head>
<body>
<div class="wrap">
  <header class="page-head">
    <h1>Edit: <?=htmlspecialchars($title)?></h1>
  <?php $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/') . rtrim((string)($_ENV['APP_BASE_PATH'] ?? '/'), '/'); ?>
  <a class="btn" href="<?=$base?>/form/<?=htmlspecialchars($form['id'])?>">← Cancel</a>
  </header>

  <div class="status-selector">
    <label>Form Status</label>
    <select id="statusSelect">
      <option value="draft" <?=$form['status']==='draft'?'selected':''?>>Draft</option>
      <option value="pending" <?=$form['status']==='pending'?'selected':''?>>Pending Review</option>
      <option value="issued" <?=$form['status']==='issued'?'selected':''?>>Issued</option>
      <option value="active" <?=$form['status']==='active'?'selected':''?>>Active</option>
      <option value="expired" <?=$form['status']==='expired'?'selected':''?>>Expired</option>
      <option value="closed" <?=$form['status']==='closed'?'selected':''?>>Closed</option>
    </select>
  </div>

  <form id="form" onsubmit="return updateForm(event)">
    <input type="hidden" name="template_id" value="<?=htmlspecialchars($schema['id'] ?? 'hot-works-v1')?>">
    <section class="meta">
      <?php foreach(($schema['meta']['fields']??[]) as $f): ?>
        <?php $val = $meta[$f['key']] ?? ''; ?>
        <div class="field">
          <label><?=htmlspecialchars($f['label'])?></label>
          <?php if(($f['type']??'')==='select'): ?>
            <select name="meta[<?=$f['key']?>]">
              <?php foreach($f['options'] as $op): ?>
                <option <?=$val===$op?'selected':''?>><?=htmlspecialchars($op)?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif(($f['type']??'')==='textarea'): ?>
            <textarea name="meta[<?=$f['key']?>]" placeholder=""><?=htmlspecialchars($val)?></textarea>
          <?php elseif(($f['type']??'')==='datetime'): ?>
            <input type="datetime-local" name="meta[<?=$f['key']?>]" value="<?=htmlspecialchars($val)?>">
          <?php else: ?>
            <input type="text" name="meta[<?=$f['key']?>]" placeholder="" value="<?=htmlspecialchars($val)?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>

    <?php foreach(($schema['sections']??[]) as $sIdx=>$sec):
        $sectionKey = resolveSectionKey($sec, $sIdx);
        $sectionFields = $sec['fields'] ?? [];
        $sectionStored = $sectionValues[$sectionKey] ?? [];
    ?>
      <details class="card" open>
        <summary><strong class="cp"><?=htmlspecialchars($sec['title'])?></strong></summary>
        <div class="items">
          <?php foreach(($sec['items']??[]) as $i=>$txt): 
            $id="s{$sIdx}_$i"; 
            $itemData = $items[$id] ?? [];
            $checked = !empty($itemData['done']) ? 'checked' : '';
            $status = $itemData['status'] ?? 'na';
            $note = $itemData['note'] ?? '';
          ?>
            <div class="item">
              <div class="row">
                <input type="checkbox" name="items[<?=$id?>][done]" <?=$checked?>>
                <div class="label-text"><?=htmlspecialchars($txt)?></div>
                <div class="status">
                  <label class="pill"><input type="radio" name="items[<?=$id?>][status]" value="pass" <?=$status==='pass'?'checked':''?>>Pass</label>
                  <label class="pill"><input type="radio" name="items[<?=$id?>][status]" value="fail" <?=$status==='fail'?'checked':''?>>Fail</label>
                  <label class="pill"><input type="radio" name="items[<?=$id?>][status]" value="na" <?=$status==='na'?'checked':''?>>N/A</label>
                </div>
              </div>
              <div class="note"><textarea name="items[<?=$id?>][note]" placeholder="Notes (optional)"><?=htmlspecialchars($note)?></textarea></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (!empty($sectionFields) && is_array($sectionFields)): ?>
          <div class="meta" style="padding-top:12px;">
            <?php foreach ($sectionFields as $fIdx => $field):
                if (!is_array($field)) { continue; }
                $fieldLabel = (string)($field['label'] ?? 'Field');
                $fieldType = strtolower((string)($field['type'] ?? 'text'));
                $fieldRequired = !empty($field['required']);
                $fieldPlaceholder = (string)($field['placeholder'] ?? '');
                $fieldOptions = normaliseFieldOptions($field['options'] ?? []);
                $fieldKey = resolveFieldKey($field, $fIdx, $sectionKey);
                $storedValue = $sectionStored[$fieldKey] ?? ($field['default'] ?? '');
                $valueList = is_array($storedValue)
                    ? array_values(array_map('strval', $storedValue))
                    : [(string)$storedValue];
                $fieldDescription = (string)($field['description'] ?? '');
                $inputName = "sections[{$sectionKey}][{$fieldKey}]";
                $isMultiple = $fieldType === 'multiselect' || (!empty($field['multiple']) && $fieldType === 'select');
                if ($isMultiple) {
                    $inputName .= '[]';
                }
            ?>
              <div class="field">
                <label><?=htmlspecialchars($fieldLabel)?><?php if ($fieldRequired): ?><span class="required">*</span><?php endif; ?></label>
                <?php if ($fieldType === 'textarea'): ?>
                  <textarea name="<?=$inputName?>" <?= $fieldRequired ? 'required' : '' ?> placeholder="<?=htmlspecialchars($fieldPlaceholder)?>"><?=htmlspecialchars($valueList[0] ?? '')?></textarea>
                <?php elseif (in_array($fieldType, ['select', 'multiselect'], true) && !empty($fieldOptions)): ?>
                  <select name="<?=$inputName?>" <?= $isMultiple ? 'multiple' : '' ?> <?= $fieldRequired ? 'required' : '' ?>>
                    <?php if (!$isMultiple): ?>
                      <option value="">Select...</option>
                    <?php endif; ?>
                    <?php foreach ($fieldOptions as $option): ?>
                      <?php $isSelected = in_array($option['value'], $valueList, true); ?>
                      <option value="<?=htmlspecialchars($option['value'])?>" <?= $isSelected ? 'selected' : '' ?>><?=htmlspecialchars($option['label'])?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif (in_array($fieldType, ['radio', 'checkbox'], true) && !empty($fieldOptions)): ?>
                  <div style="display:flex;flex-direction:column;gap:6px;">
                    <?php foreach ($fieldOptions as $oIdx => $option):
                        $optionId = $sectionKey . '_' . $fieldKey . '_' . $oIdx;
                        $optionName = $fieldType === 'checkbox' ? $inputName . '[]' : $inputName;
                        $isChecked = in_array($option['value'], $valueList, true);
                    ?>
                      <label style="display:flex;align-items:center;gap:8px;">
                        <input type="<?=$fieldType === 'checkbox' ? 'checkbox' : 'radio'?>" id="<?=htmlspecialchars($optionId)?>" name="<?=htmlspecialchars($optionName)?>" value="<?=htmlspecialchars($option['value'])?>" <?= $fieldRequired && $fieldType === 'radio' ? 'required' : '' ?> <?= $isChecked ? 'checked' : '' ?>>
                        <span><?=htmlspecialchars($option['label'])?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php elseif (in_array($fieldType, ['date', 'time', 'datetime', 'datetime-local'], true)): ?>
                  <?php $inputType = $fieldType === 'datetime' ? 'datetime-local' : $fieldType; ?>
                  <input type="<?=$inputType?>" name="<?=$inputName?>" <?= $fieldRequired ? 'required' : '' ?> value="<?=htmlspecialchars((string)($valueList[0] ?? ''))?>" placeholder="<?=htmlspecialchars($fieldPlaceholder)?>">
                <?php elseif ($fieldType === 'number'): ?>
                  <input type="number" name="<?=$inputName?>" <?= $fieldRequired ? 'required' : '' ?> value="<?=htmlspecialchars((string)($valueList[0] ?? ''))?>" placeholder="<?=htmlspecialchars($fieldPlaceholder)?>"<?php
                    if (isset($field['min'])) { echo ' min="' . htmlspecialchars((string)$field['min']) . '"'; }
                    if (isset($field['max'])) { echo ' max="' . htmlspecialchars((string)$field['max']) . '"'; }
                    if (isset($field['step'])) { echo ' step="' . htmlspecialchars((string)$field['step']) . '"'; }
                  ?>>
                <?php elseif (in_array($fieldType, ['email', 'tel', 'url'], true)): ?>
                  <input type="<?=$fieldType?>" name="<?=$inputName?>" <?= $fieldRequired ? 'required' : '' ?> value="<?=htmlspecialchars((string)($valueList[0] ?? ''))?>" placeholder="<?=htmlspecialchars($fieldPlaceholder)?>">
                <?php elseif ($fieldType === 'boolean'): ?>
                  <select name="<?=$inputName?>" <?= $fieldRequired ? 'required' : '' ?>>
                    <option value="">Select...</option>
                    <option value="yes" <?= in_array('yes', $valueList, true) ? 'selected' : '' ?>>Yes</option>
                    <option value="no" <?= in_array('no', $valueList, true) ? 'selected' : '' ?>>No</option>
                  </select>
                <?php else: ?>
                  <input type="text" name="<?=$inputName?>" <?= $fieldRequired ? 'required' : '' ?> value="<?=htmlspecialchars((string)($valueList[0] ?? ''))?>" placeholder="<?=htmlspecialchars($fieldPlaceholder)?>">
                <?php endif; ?>
                <?php if ($fieldDescription !== ''): ?>
                  <small class="field-description"><?=htmlspecialchars($fieldDescription)?></small>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>

    <section class="card sig-wrap">
      <div class="sig">
        <div class="sigtitle">Signatures</div>
        <p style="color:#94a3b8;font-size:14px;margin-bottom:12px">Note: Signatures from the original form are preserved. You can clear and re-sign if needed.</p>
        <div class="siggrid">
          <?php 
            $existingSigs = $existingData['signatures'] ?? [];
            foreach(($schema['signatures']??[]) as $sig): 
              $cid="sig_".$sig; 
              $existingSig = $existingSigs[$cid] ?? null;
          ?>
            <div class="sigbox">
              <div class="sigtitle"><?=ucwords(str_replace('_',' ', $sig))?></div>
              <canvas id="<?=$cid?>" width="1000" height="320" data-existing="<?=htmlspecialchars($existingSig ?? '')?>"></canvas>
              <div class="rowish"><span class="muted">Sign above</span><button class="btn" type="button" onclick="clearSig('<?=$cid?>')">Clear</button></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <div class="tools" style="margin:20px 0"><button class="btn btn-accent" type="submit">💾 Update Form</button></div>
  </form>
</div>

<script src="/assets/app.js"></script>
<script>
const sigPads = {};
function initSig(id){
  const c=document.getElementById(id), ctx=c.getContext('2d');
  ctx.lineWidth=2; ctx.strokeStyle='#111827'; ctx.lineCap='round'; ctx.lineJoin='round';
  const clear=()=>{ 
    ctx.fillStyle='#fff'; 
    ctx.fillRect(0,0,c.width,c.height); 
    ctx.fillStyle='#111827'; 
    ctx.globalAlpha=.08; 
    ctx.font="28px system-ui"; 
    ctx.fillText("Signed",16,c.height-16); 
    ctx.globalAlpha=1; 
  };
  
  // Load existing signature if present
  const existing = c.dataset.existing;
  if(existing) {
    const img = new Image();
    img.onload = () => ctx.drawImage(img, 0, 0);
    img.src = existing;
  } else {
    clear();
  }
  
  let draw=false,last=null;
  c.addEventListener('pointerdown',e=>{draw=true;last=pt(e);c.setPointerCapture(e.pointerId)});
  c.addEventListener('pointermove',e=>{if(!draw)return;const p=pt(e);ctx.beginPath();ctx.moveTo(last.x,last.y);ctx.lineTo(p.x,p.y);ctx.stroke();last=p;});
  c.addEventListener('pointerup',()=>draw=false); 
  c.addEventListener('pointerleave',()=>draw=false);
  function pt(e){const r=c.getBoundingClientRect();return {x:(e.clientX-r.left)*(c.width/r.width),y:(e.clientY-r.top)*(c.height/r.height)}}
  sigPads[id]={clear,el:c};
}
function clearSig(id){ sigPads[id]?.clear(); }
document.querySelectorAll('canvas[id^="sig_"]').forEach(c=>initSig(c.id));

async function updateForm(ev){
  ev.preventDefault();
  const f = document.getElementById('form');
  const fd = new FormData(f);
  const form = { 
    template_id: fd.get('template_id'), 
    status: document.getElementById('statusSelect').value,
    meta: {}, 
    items:{} 
  };
  
  for (const [k,v] of fd.entries()){
    if(k.startsWith('meta[')){
      form.meta[k.slice(5,-1)]=v;
      continue;
    }
    if(k.startsWith('items[')){
      const key=k.slice(6);
      const [id, name] = key.split('][');
      const cleanId=id.replace(']','');
      const cleanName=name.replace(']','');
      form.items[cleanId] ??={};
      form.items[cleanId][cleanName]=v;
      continue;
    }
    if(k.startsWith('sections[')){
      const match = k.match(/^sections\[(.+?)\]\[(.+?)\](\[\])?$/);
      if(!match){
        continue;
      }
      const sectionKey = match[1];
      const fieldKey = match[2];
      const isArray = Boolean(match[3]);
      if(!form.sections){
        form.sections = {};
      }
      if(!form.sections[sectionKey]){
        form.sections[sectionKey] = {};
      }
      if(isArray){
        if(!Array.isArray(form.sections[sectionKey][fieldKey])){
          form.sections[sectionKey][fieldKey] = [];
        }
        form.sections[sectionKey][fieldKey].push(v);
      } else if (form.sections[sectionKey][fieldKey] !== undefined) {
        const current = form.sections[sectionKey][fieldKey];
        if(Array.isArray(current)){
          current.push(v);
        } else {
          form.sections[sectionKey][fieldKey] = [current, v];
        }
      } else {
        form.sections[sectionKey][fieldKey] = v;
      }
    }
  }

  if(form.sections){
    Object.keys(form.sections).forEach(sectionKey => {
      if(form.sections[sectionKey] && typeof form.sections[sectionKey] === 'object' && Object.keys(form.sections[sectionKey]).length === 0){
        delete form.sections[sectionKey];
      }
    });
    if(Object.keys(form.sections).length === 0){
      delete form.sections;
    }
  }
  
  form.signatures = {};
  Object.keys(sigPads).forEach(k=> form.signatures[k] = sigPads[k].el.toDataURL('image/png'));
  
  const r = await fetch('/api/forms/<?=htmlspecialchars($form['id'])?>', {
    method:'PUT', 
    headers:{'Content-Type':'application/json'}, 
    body: JSON.stringify(form)
  });
  
  const j = await r.json(); 
  if(j.ok){ 
    alert('Form updated successfully!'); 
  location.href='<?=$base?>/form/<?=htmlspecialchars($form['id'])?>'; 
  } else { 
    alert('Update failed: ' + (j.error || 'Unknown error')); 
  }
}
</script>
</body>
</html>
