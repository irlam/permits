(() => {
  'use strict';

  const section = document.getElementById('status-checker');
  if (!section) return;

  const header = section.querySelector('.section__header');
  const title = header?.querySelector('.section__title');
  const lead = header?.querySelector('.section__lead');
  const form = section.querySelector('form.status-form');

  if (title) title.textContent = 'Current permits on site';
  if (lead) {
    lead.textContent = 'See active, pending, suspended and recently expired permits without needing a permit number.';
  }

  const style = document.createElement('style');
  style.textContent = `
    .live-permit-board { display:grid; gap:18px; }
    .live-permit-board__stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
    .live-permit-stat { border:1px solid rgba(148,163,184,.18); border-radius:16px; padding:16px 18px; background:rgba(15,23,42,.72); display:grid; gap:4px; }
    .live-permit-stat--suspended { border-color:rgba(251,146,60,.45); background:rgba(124,45,18,.18); }
    .live-permit-stat--expired { border-color:rgba(248,113,113,.55); background:rgba(127,29,29,.28); }
    .live-permit-stat__value { font-size:30px; font-weight:750; line-height:1; }
    .live-permit-stat__label { color:rgba(203,213,225,.82); font-size:14px; }
    .live-permit-board__tools { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .live-permit-search { flex:1 1 260px; min-height:46px; border-radius:12px; border:1px solid rgba(148,163,184,.28); background:rgba(15,23,42,.8); color:#e5e7eb; padding:10px 14px; font-size:16px; }
    .live-permit-search:focus { outline:2px solid rgba(var(--brand-primary-rgb),.65); outline-offset:2px; }
    .live-permit-updated { color:rgba(148,163,184,.82); font-size:13px; }
    .live-permit-grid { display:grid; gap:12px; }
    .live-permit-card { border:1px solid rgba(148,163,184,.18); border-radius:18px; background:rgba(15,23,42,.86); padding:18px; display:grid; gap:11px; }
    .live-permit-card--pending { border-left:4px solid #eab308; }
    .live-permit-card--active { border-left:4px solid #22c55e; }
    .live-permit-card--suspended { border:1px solid rgba(251,146,60,.48); border-left:5px solid #f97316; background:rgba(67,20,7,.62); }
    .live-permit-card--expired { border:1px solid rgba(248,113,113,.6); border-left:6px solid #ef4444; background:rgba(69,10,10,.82); }
    .live-permit-card__top { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap; }
    .live-permit-card__title { margin:0; font-size:18px; font-weight:700; }
    .live-permit-card__ref { margin-top:3px; color:rgba(148,163,184,.9); font-size:13px; }
    .live-permit-card__meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:8px 14px; color:rgba(226,232,240,.9); font-size:14px; }
    .live-permit-card__meta strong { color:#f8fafc; }
    .live-permit-card__note { margin:0; color:rgba(203,213,225,.82); font-size:14px; }
    .live-permit-card--suspended .live-permit-card__note { color:#fed7aa; font-weight:700; }
    .live-permit-card--expired .live-permit-card__note { color:#fecaca; font-weight:800; }
    .live-permit-empty { border:1px dashed rgba(148,163,184,.32); border-radius:18px; padding:24px; text-align:center; color:rgba(203,213,225,.9); }
    .permit-specific-lookup { border-top:1px solid rgba(148,163,184,.18); padding-top:18px; }
    .permit-specific-lookup > summary { cursor:pointer; font-weight:700; color:#e2e8f0; list-style:none; display:flex; align-items:center; gap:8px; padding:4px 0; }
    .permit-specific-lookup > summary::-webkit-details-marker { display:none; }
    .permit-specific-lookup > summary::before { content:'▸'; transition:transform .15s ease; }
    .permit-specific-lookup[open] > summary::before { transform:rotate(90deg); }
    .permit-specific-lookup__hint { margin:8px 0 14px; color:rgba(148,163,184,.88); font-size:14px; }
    @media (max-width:800px) { .live-permit-board__stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media (max-width:600px) {
      .live-permit-board__stats { grid-template-columns:1fr; }
      .live-permit-board__tools { align-items:stretch; }
      .live-permit-board__tools .btn { width:100%; }
      .live-permit-card { padding:16px; }
    }
  `;
  document.head.appendChild(style);

  const board = document.createElement('div');
  board.className = 'live-permit-board';
  board.innerHTML = `
    <div class="live-permit-board__stats" aria-label="Current permit totals">
      <article class="live-permit-stat live-permit-stat--expired">
        <span class="live-permit-stat__value" data-live-count="expired">–</span>
        <span class="live-permit-stat__label">Expired in last 24h — stop work</span>
      </article>
      <article class="live-permit-stat live-permit-stat--suspended">
        <span class="live-permit-stat__value" data-live-count="suspended">–</span>
        <span class="live-permit-stat__label">Suspended — stop work</span>
      </article>
      <article class="live-permit-stat">
        <span class="live-permit-stat__value" data-live-count="pending">–</span>
        <span class="live-permit-stat__label">Pending / acceptance</span>
      </article>
      <article class="live-permit-stat">
        <span class="live-permit-stat__value" data-live-count="active">–</span>
        <span class="live-permit-stat__label">Active now</span>
      </article>
    </div>
    <div class="live-permit-board__tools">
      <label class="sr-only" for="livePermitSearch">Search current permits</label>
      <input class="live-permit-search" id="livePermitSearch" type="search" placeholder="Search permit type, reference or area">
      <button class="btn btn-secondary" type="button" data-live-refresh>Refresh permits</button>
    </div>
    <div class="live-permit-updated" data-live-updated aria-live="polite">Loading current permits…</div>
    <div class="live-permit-grid" data-live-list aria-live="polite"></div>
  `;

  if (header) header.insertAdjacentElement('afterend', board);
  else section.insertAdjacentElement('afterbegin', board);

  if (form) {
    const hadLookupAttempt = Boolean(form.querySelector('#check_reference')?.value);
    const details = document.createElement('details');
    details.className = 'permit-specific-lookup';
    if (hadLookupAttempt) details.open = true;

    const summary = document.createElement('summary');
    summary.textContent = 'Find a specific permit';
    const hint = document.createElement('p');
    hint.className = 'permit-specific-lookup__hint';
    hint.textContent = 'If the permit is not current, use the email address and reference from the permit email.';

    form.parentNode.insertBefore(details, form);
    details.appendChild(summary);
    details.appendChild(hint);
    details.appendChild(form);
    while (details.nextSibling) details.appendChild(details.nextSibling);
  }

  const list = board.querySelector('[data-live-list]');
  const updated = board.querySelector('[data-live-updated]');
  const search = board.querySelector('#livePermitSearch');
  const refresh = board.querySelector('[data-live-refresh]');
  const pendingCount = board.querySelector('[data-live-count="pending"]');
  const activeCount = board.querySelector('[data-live-count="active"]');
  const suspendedCount = board.querySelector('[data-live-count="suspended"]');
  const expiredCount = board.querySelector('[data-live-count="expired"]');
  let permits = [];

  function escapeHtml(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function formatDate(value) {
    if (!value) return '';
    const normalized = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('en-GB', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' }).format(date);
  }

  function render() {
    const term = String(search?.value || '').toLowerCase().trim();
    const visible = permits.filter((permit) => !term || [permit.permit_type, permit.reference, permit.location, permit.status_label].some((value) => String(value || '').toLowerCase().includes(term)));

    if (!list) return;
    if (visible.length === 0) {
      list.innerHTML = `<div class="live-permit-empty">${term ? 'No permits match that search.' : 'There are no current or recently expired permits at the moment.'}</div>`;
      return;
    }

    list.innerHTML = visible.map((permit) => {
      const state = ['pending', 'active', 'suspended', 'expired'].includes(permit.status) ? permit.status : 'pending';
      const expired = state === 'expired';
      const suspended = state === 'suspended';
      const pending = state === 'pending';
      const location = permit.location ? `<span><strong>Area:</strong> ${escapeHtml(permit.location)}</span>` : '';
      const submitted = permit.submitted_at ? `<span><strong>Submitted:</strong> ${escapeHtml(formatDate(permit.submitted_at))}</span>` : '';
      const validTo = permit.valid_to ? `<span><strong>${expired ? 'Expired:' : 'Valid until:'}</strong> ${escapeHtml(formatDate(permit.valid_to))}</span>` : '';
      const badgeClass = (expired || suspended) ? 'status-badge--danger' : (pending ? 'status-badge--warning' : 'status-badge--success');
      const note = expired
        ? 'STOP WORK. This permit has expired and no longer authorises the work. Obtain fresh valid authorisation before work resumes.'
        : (suspended
          ? 'STOP WORK. This permit must be revalidated and accepted before work resumes.'
          : (pending
            ? (permit.status_label === 'Awaiting Holder Acceptance' ? 'Manager approved; holder acceptance is still required before work starts.' : 'Awaiting manager approval.')
            : 'Approved, accepted and currently live on site.'));

      return `
        <article class="live-permit-card live-permit-card--${state}">
          <div class="live-permit-card__top">
            <div><h3 class="live-permit-card__title">${escapeHtml(permit.permit_type || 'Permit')}</h3><div class="live-permit-card__ref">Ref #${escapeHtml(permit.reference || '—')}</div></div>
            <span class="status-badge ${badgeClass}">${escapeHtml(permit.status_label || state)}</span>
          </div>
          <div class="live-permit-card__meta">${location}${submitted}${validTo}</div>
          <p class="live-permit-card__note">${escapeHtml(note)}</p>
        </article>`;
    }).join('');
  }

  async function loadPermits() {
    if (refresh) { refresh.disabled = true; refresh.textContent = 'Refreshing…'; }
    try {
      const endpoint = new URL('api/current-permits.php', window.location.href); endpoint.hash = '';
      const response = await fetch(endpoint.toString(), { method:'GET', headers:{'Accept':'application/json'}, cache:'no-store', credentials:'same-origin' });
      if (!response.ok) throw new Error(`Status feed failed: ${response.status}`);
      const payload = await response.json();
      if (!payload || payload.success !== true || !Array.isArray(payload.permits)) throw new Error('Invalid status response');

      permits = payload.permits;
      if (pendingCount) pendingCount.textContent = String(payload.counts?.pending ?? 0);
      if (activeCount) activeCount.textContent = String(payload.counts?.active ?? 0);
      if (suspendedCount) suspendedCount.textContent = String(payload.counts?.suspended ?? 0);
      if (expiredCount) expiredCount.textContent = String(payload.counts?.expired ?? 0);
      if (updated) updated.textContent = `Last updated ${new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'}).format(new Date())}`;
      render();
    } catch (error) {
      console.error('[Permits] Unable to refresh current permit board:', error);
      if (updated) updated.textContent = 'Unable to refresh current permits right now.';
      if (list) list.innerHTML = '<div class="live-permit-empty">Current permit status is temporarily unavailable. Please try Refresh permits.</div>';
    } finally {
      if (refresh) { refresh.disabled = false; refresh.textContent = 'Refresh permits'; }
    }
  }

  search?.addEventListener('input', render);
  refresh?.addEventListener('click', loadPermits);
  loadPermits();
  window.setInterval(() => { if (!document.hidden) loadPermits(); }, 30000);
})();
