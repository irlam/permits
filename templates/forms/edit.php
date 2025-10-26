<?php
$schema = json_decode($schemaJson, true);
$title  = $schema['title'] ?? 'Form';
$meta = $existingData['meta'] ?? [];
$items = $existingData['items'] ?? [];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Edit: <?=htmlspecialchars($title)?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0ea5e9">
  <link rel="stylesheet" href="/assets/app.css">
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
    <a class="btn" href="/form/<?=htmlspecialchars($form['id'])?>">← Cancel</a>
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

    <?php foreach(($schema['sections']??[]) as $sIdx=>$sec): ?>
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
    if(k.startsWith('meta[')){ form.meta[k.slice(5,-1)]=v; }
    if(k.startsWith('items[')){ 
      const key=k.slice(6); 
      const [id, name] = key.split(']['); 
      const cleanId=id.replace(']',''); 
      const cleanName=name.replace(']',''); 
      form.items[cleanId] ??={}; 
      form.items[cleanId][cleanName]=v; 
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
    location.href='/form/<?=htmlspecialchars($form['id'])?>'; 
  } else { 
    alert('Update failed: ' + (j.error || 'Unknown error')); 
  }
}
</script>
</body>
</html>
