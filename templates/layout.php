<?php
/**
 * Main Layout Template
 * 
 * File Path: /templates/layout.php
 * Description: Main permits list with search/filter and navigation
 * Created: 21/10/2025
 * Last Modified: 21/10/2025
 */

// Load cache helper for preventing browser caching
require_once __DIR__ . '/../src/cache-helper.php';

// Load Auth class for admin button
require_once __DIR__ . '/../src/Auth.php';
$auth = new Auth($db);

$base = $_ENV['APP_URL'] ?? '/';
$params = $_GET ?? [];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php cache_meta_tags(); ?>
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0ea5e9">
  <title>Permits</title>
  <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
  <script>
    (function(){
      var NativeWebSocket = window.WebSocket || window.MozWebSocket;
      if(!NativeWebSocket){return;}
      var blockedPattern = /\/ws\/ws(\/|$)/i;

      function createStub(url){
        var eventTargets = {open:[],message:[],error:[],close:[]};
        function dispatch(type, detail){
          var evt; try{evt = new Event(type);}catch(e){evt = {type:type};}
          if(detail){for(var k in detail){if(Object.prototype.hasOwnProperty.call(detail,k)){evt[k]=detail[k];}}}
          (eventTargets[type]||[]).forEach(function(fn){try{fn.call(stub,evt);}catch(err){console.error(err);}});
          var handler = stub['on'+type];
          if(typeof handler === 'function'){try{handler.call(stub,evt);}catch(err){console.error(err);}}
        }
        var stub = {
          url:url,
          readyState:NativeWebSocket.CLOSED,
          bufferedAmount:0,
          extensions:'',
          protocol:'',
          binaryType:'blob',
          onopen:null,
          onmessage:null,
          onerror:null,
          onclose:null,
          addEventListener:function(type,fn){if(eventTargets[type]){eventTargets[type].push(fn);}},
          removeEventListener:function(type,fn){var list=eventTargets[type]; if(!list){return;} var idx=list.indexOf(fn); if(idx>-1){list.splice(idx,1);}},
          dispatchEvent:function(evt){dispatch(evt.type,evt);},
          send:function(){console.warn('Blocked WebSocket send to',url);},
          close:function(){},
        };
        setTimeout(function(){dispatch('error',{message:'Blocked dev websocket'});dispatch('close',{wasClean:true,code:1000,reason:'Blocked dev websocket'});},0);
        return stub;
      }

      function createNative(url,protocols){
        if(NativeWebSocket.length===1){return new NativeWebSocket(url);}
        return protocols!==undefined?new NativeWebSocket(url,protocols):new NativeWebSocket(url);
      }

      function WrappedWebSocket(url,protocols){
        if(typeof url==='string' && blockedPattern.test(url)){
          console.info('Suppressed dev WebSocket:',url);
          return createStub(url);
        }
        return createNative(url,protocols);
      }

      WrappedWebSocket.prototype = NativeWebSocket.prototype;
      if(Object.setPrototypeOf){Object.setPrototypeOf(WrappedWebSocket,NativeWebSocket);} else {WrappedWebSocket.__proto__ = NativeWebSocket;}
      window.WebSocket = WrappedWebSocket;
      if('MozWebSocket' in window){window.MozWebSocket = WrappedWebSocket;}
    })();
  </script>
  <style>
    .search-panel{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:16px;margin-bottom:16px;grid-column:1/-1}
    .search-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end}
    .search-field label{display:block;font-size:12px;color:#94a3b8;margin-bottom:4px}
    .search-field input,.search-field select{width:100%;padding:8px;background:#0a101a;border:1px solid #1f2937;border-radius:6px;color:#e5e7eb}
    .search-actions{display:flex;gap:8px}
  </style>
</head>
<body>
<header class="top">
  <h1>Permits & Registers</h1>
  <div style="display:flex;gap:8px">
    <?php if($auth->isLoggedIn() && $auth->hasRole('admin')): ?>
      <a class="btn" href="/admin.php" style="background:#f59e0b">⚙️ Admin Panel</a>
    <?php endif; ?>
    <a class="btn" href="/dashboard">📊 Dashboard</a>
    <a class="btn" href="/">Home</a>
    <?php if($auth->isLoggedIn()): ?>
      <a class="btn" href="/logout.php">🚪 Logout</a>
    <?php else: ?>
      <a class="btn" href="/login.php">🔐 Login</a>
    <?php endif; ?>
  </div>
</header>

<section class="grid">
  <div class="search-panel">
    <h2>Search & Filter</h2>
    <form class="search-form" method="get">
      <div class="search-field">
        <label>Search</label>
        <input type="text" name="search" placeholder="Ref, location, contractor..." value="<?=htmlspecialchars($params['search'] ?? '')?>">
      </div>
      <div class="search-field">
        <label>Status</label>
        <select name="status">
          <option value="">All Statuses</option>
          <option value="draft" <?=($params['status']??'')==='draft'?'selected':''?>>Draft</option>
          <option value="pending" <?=($params['status']??'')==='pending'?'selected':''?>>Pending</option>
          <option value="issued" <?=($params['status']??'')==='issued'?'selected':''?>>Issued</option>
          <option value="active" <?=($params['status']??'')==='active'?'selected':''?>>Active</option>
          <option value="expired" <?=($params['status']??'')==='expired'?'selected':''?>>Expired</option>
          <option value="closed" <?=($params['status']??'')==='closed'?'selected':''?>>Closed</option>
        </select>
      </div>
      <div class="search-field">
        <label>Template</label>
        <select name="template">
          <option value="">All Templates</option>
          <?php foreach($tpls as $t): ?>
            <option value="<?=htmlspecialchars($t['id'])?>" <?=($params['template']??'')===$t['id']?'selected':''?>><?=htmlspecialchars($t['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="search-field">
        <label>From Date</label>
        <input type="date" name="date_from" value="<?=htmlspecialchars($params['date_from'] ?? '')?>">
      </div>
      <div class="search-field">
        <label>To Date</label>
        <input type="date" name="date_to" value="<?=htmlspecialchars($params['date_to'] ?? '')?>">
      </div>
      <div class="search-actions">
        <button type="submit" class="btn">🔍 Search</button>
        <a href="/" class="btn">Clear</a>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Templates</h2>
    <ul>
      <?php foreach($tpls as $t): ?>
        <li><a class="btn" href="/new/<?=htmlspecialchars($t['id'])?>"><?=htmlspecialchars($t['name'])?> (v<?=intval($t['version'])?>)</a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card">
    <h2>Recent Forms (<?=count($forms)?> results)</h2>
    <?php if(empty($forms)): ?>
      <p style="color:#6b7280;padding:20px;text-align:center">No forms found. Try adjusting your search filters.</p>
    <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Ref</th><th>Template</th><th>Status</th><th>Valid To</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach($forms as $f): ?>
          <tr>
            <td><?=htmlspecialchars($f['ref'])?></td>
            <td><?=htmlspecialchars($f['template_id'])?></td>
            <td><?=htmlspecialchars($f['status'])?></td>
            <td><?=htmlspecialchars($f['valid_to']??'')?></td>
            <td><a class="btn" href="/form/<?=htmlspecialchars($f['id'])?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>

<script src="<?=asset('/assets/app.js')?>"></script>
</body>
</html>