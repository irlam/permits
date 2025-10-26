<?php
$schema = json_decode($schemaJson, true);
$title  = $schema['title'] ?? 'Form';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?=htmlspecialchars($title)?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0ea5e9">
  <link rel="stylesheet" href="/assets/app.css">
  <style>
    @media print {
      html,body{background:#fff!important;color:#111!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:12px}
      #summaryReport{display:none!important}
      .label-text, th, td, textarea { white-space:normal!important; overflow:visible!important; text-overflow:clip!important; word-break:break-word!important; hyphens:auto!important; }
      .sig canvas{background:#fff!important;border:1px solid #bbb!important}
    }
  </style>
</head>
<body>
<div class="wrap">
  <header class="page-head">
    <h1><?=htmlspecialchars($title)?></h1>
    <button class="btn" onclick="window.print()">Print / PDF</button>
  </header>

  <form id="form" onsubmit="return saveForm(event)">
    <input type="hidden" name="template_id" value="<?=htmlspecialchars($schema['id'] ?? 'hot-works-v1')?>">
    <section class="meta">
      <?php foreach(($schema['meta']['fields']??[]) as $f): ?>
        <div class="field">
          <label><?=htmlspecialchars($f['label'])?></label>
          <?php if(($f['type']??'')==='select'): ?>
            <select name="meta[<?=$f['key']?>]"><?php foreach($f['options'] as $op): ?><option><?=htmlspecialchars($op)?></option><?php endforeach;?></select>
          <?php elseif(($f['type']??'')==='textarea'): ?>
            <textarea name="meta[<?=$f['key']?>]" placeholder=""></textarea>
          <?php elseif(($f['type']??'')==='datetime'): ?>
            <input type="datetime-local" name="meta[<?=$f['key']?>]">
          <?php else: ?>
            <input type="text" name="meta[<?=$f['key']?>]" placeholder="">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>

    <?php foreach(($schema['sections']??[]) as $sIdx=>$sec): ?>
      <details class="card" <?= $sIdx<2?'open':''?>>
        <summary><strong class="cp"><?=htmlspecialchars($sec['title'])?></strong></summary>
        <div class="items">
          <?php foreach(($sec['items']??[]) as $i=>$txt): $id="s{$sIdx}_$i"; ?>
            <div class="item">
              <div class="row">
                <input type="checkbox" name="items[<?=$id?>][done]">
                <div class="label-text"><?=htmlspecialchars($txt)?></div>
                <div class="status">
                  <label class="pill"><input type="radio" name="items[<?=$id?>][status]" value="pass">Pass</label>
                  <label class="pill"><input type="radio" name="items[<?=$id?>][status]" value="fail">Fail</label>
                  <label class="pill"><input type="radio" name="items[<?=$id?>][status]" value="na" checked>N/A</label>
                </div>
              </div>
              <div class="note"><textarea name="items[<?=$id?>][note]" placeholder="Notes (optional)"></textarea></div>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>

    <section class="card sig-wrap">
      <div class="sig">
        <div class="sigtitle">Signatures</div>
        <div class="siggrid">
          <?php foreach(($schema['signatures']??[]) as $sig): $cid="sig_".$sig; ?>
            <div class="sigbox">
              <div class="sigtitle"><?=ucwords(str_replace('_',' ', $sig))?></div>
              <canvas id="<?=$cid?>" width="1000" height="320"></canvas>
              <div class="rowish"><span class="muted">Sign above</span><button class="btn" type="button" onclick="clearSig('<?=$cid?>')">Clear</button></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <div class="tools"><button class="btn btn-accent" type="submit">Save</button></div>
  </form>
</div>

<script src="/assets/app.js"></script>
<script>
const sigPads = {};
function initSig(id){
  const c=document.getElementById(id), ctx=c.getContext('2d');
  ctx.lineWidth=2; ctx.strokeStyle='#111827'; ctx.lineCap='round'; ctx.lineJoin='round';
  const clear=()=>{ ctx.fillStyle='#fff'; ctx.fillRect(0,0,c.width,c.height); ctx.fillStyle='#111827'; ctx.globalAlpha=.08; ctx.font="28px system-ui"; ctx.fillText("Signed",16,c.height-16); ctx.globalAlpha=1; };
  clear();
  let draw=false,last=null;
  c.addEventListener('pointerdown',e=>{draw=true;last=pt(e);c.setPointerCapture(e.pointerId)});
  c.addEventListener('pointermove',e=>{if(!draw)return;const p=pt(e);ctx.beginPath();ctx.moveTo(last.x,last.y);ctx.lineTo(p.x,p.y);ctx.stroke();last=p;});
  c.addEventListener('pointerup',()=>draw=false); c.addEventListener('pointerleave',()=>draw=false);
  function pt(e){const r=c.getBoundingClientRect();return {x:(e.clientX-r.left)*(c.width/r.width),y:(e.clientY-r.top)*(c.height/r.height)}}
  sigPads[id]={clear,el:c};
}
function clearSig(id){ sigPads[id]?.clear(); }
document.querySelectorAll('canvas[id^="sig_"]').forEach(c=>initSig(c.id));

async function saveForm(ev){
  ev.preventDefault();
  const f = document.getElementById('form');
  const fd = new FormData(f);
  const form = { template_id: fd.get('template_id'), meta: {}, items:{} };
  for (const [k,v] of fd.entries()){
    if(k.startsWith('meta[')){ form.meta[k.slice(5,-1)]=v; }
    if(k.startsWith('items[')){ const key=k.slice(6); const [id, name] = key.split(']['); const cleanId=id.replace(']',''); const cleanName=name.replace(']',''); form.items[cleanId] ??={}; form.items[cleanId][cleanName]=v; }
  }
  form.signatures = {};
  Object.keys(sigPads).forEach(k=> form.signatures[k] = sigPads[k].el.toDataURL('image/png'));
  const r = await fetch('/api/forms', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(form)});
  const j = await r.json(); if(j.ok){ alert('Saved: '+j.id); location.href='/'; } else { alert('Save failed'); }
}
</script>
</body>
</html>
