/* Phase 3C: keep inspection checklists visibly separate from permits to work. */
(() => {
  'use strict';

  function normalise(value) {
    return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function isInspectionTile(tile) {
    const href = tile.getAttribute('href') || '';
    if (href.includes('create-inspection-public.php')) return true;

    const name = normalise(tile.querySelector('.template-tile__name')?.textContent || '');
    return [
      'building inspection checklist', 'building inspection permit',
      'final inspection checklist', 'final inspection permit',
      'site safety inspection checklist', 'site safety inspection permit',
      'ladder / stepladder pre-use inspection',
      'mewp daily / pre-use inspection',
      'harness / lanyard inspection',
      'excavation daily inspection',
      'scaffold weekly inspection',
      'plant pre-start inspection'
    ].includes(name);
  }

  function templateIdFromHref(tile) {
    try {
      const url = new URL(tile.getAttribute('href') || '', window.location.href);
      return url.searchParams.get('template') || '';
    } catch {
      return '';
    }
  }

  function rewriteInspectionHref(tile) {
    const templateId = templateIdFromHref(tile);
    if (!templateId) return;
    let basePath = '';
    try {
      const current = new URL(tile.getAttribute('href') || '', window.location.href);
      for (const marker of ['/create-permit-public.php', '/create-inspection-public.php']) {
        if (current.pathname.endsWith(marker)) {
          basePath = current.pathname.slice(0, -marker.length);
          break;
        }
      }
    } catch {
      basePath = '';
    }
    tile.setAttribute('href', `${basePath}/create-inspection-public.php?template=${encodeURIComponent(templateId)}`);
    tile.dataset.workflow = 'inspection';
    const meta = Array.from(tile.querySelectorAll('.template-tile__meta')).find(el => /tap to start/i.test(el.textContent || ''));
    if (meta) meta.textContent = 'Tap to inspect';
  }

  function addStyles() {
    if (document.getElementById('phase3c-picker-styles')) return;
    const style = document.createElement('style');
    style.id = 'phase3c-picker-styles';
    style.textContent = `
      #inspections .template-tile { border-color: rgba(56,189,248,.24); }
      #inspections .template-tile:hover,#inspections .template-tile:focus-visible { border-color: rgba(56,189,248,.58); }
      .inspection-callout { display:inline-flex;align-items:center;gap:8px;width:fit-content;padding:7px 11px;border-radius:999px;background:rgba(14,165,233,.12);border:1px solid rgba(56,189,248,.28);color:#bae6fd;font-size:13px;font-weight:700; }
      .phase3c-inspection-modal-group { display:grid;gap:12px;padding-top:18px;margin-top:8px;border-top:1px solid rgba(56,189,248,.22); }
      .phase3c-inspection-modal-group__header { display:grid;gap:4px; }
      .phase3c-inspection-modal-group__title { margin:0;color:#e0f2fe;font-size:17px; }
      .phase3c-inspection-modal-group__lead { margin:0;color:rgba(186,230,253,.76);font-size:13px;line-height:1.45; }
      .phase3c-inspection-modal-grid { display:grid;gap:12px; }
    `;
    document.head.appendChild(style);
  }

  function cleanEmptyPermitGroups(scope) {
    scope.querySelectorAll('.permit-template-group').forEach(group => {
      if (group.querySelectorAll('.template-tile').length === 0) group.remove();
    });
  }

  function separateHomepageTiles() {
    const permitSection = document.getElementById('templates');
    if (!permitSection || document.getElementById('inspections')) return;

    const inspectionTiles = Array.from(permitSection.querySelectorAll('.template-tile')).filter(isInspectionTile);
    if (inspectionTiles.length === 0) return;
    inspectionTiles.forEach(rewriteInspectionHref);

    const section = document.createElement('section');
    section.className = 'section';
    section.id = 'inspections';
    section.innerHTML = `
      <header class="section__header">
        <span class="inspection-callout">🔎 Record findings, not a work permit</span>
        <h2 class="section__title">Inspections &amp; checklists</h2>
        <p class="section__lead">Complete equipment and site inspections without raising a permit-to-work. Significant findings can still be escalated and linked to the correct permit where needed.</p>
      </header>
      <div class="template-grid phase3c-inspection-grid" role="list"></div>`;

    const grid = section.querySelector('.phase3c-inspection-grid');
    inspectionTiles.forEach(tile => {
      tile.setAttribute('role', 'listitem');
      const icon = tile.querySelector('.template-tile__icon');
      if (icon) { icon.textContent = '🔎'; icon.setAttribute('aria-hidden', 'true'); }
      grid.appendChild(tile);
    });

    permitSection.insertAdjacentElement('afterend', section);
    cleanEmptyPermitGroups(permitSection);
    const permitHeading = permitSection.querySelector('.section__title');
    const permitLead = permitSection.querySelector('.section__lead');
    if (permitHeading) permitHeading.textContent = 'Create a new permit';
    if (permitLead) permitLead.textContent = 'Choose the permit-to-work needed to control and authorise the task.';

    const heroActions = document.querySelector('.hero__actions');
    if (heroActions && !heroActions.querySelector('[data-phase3c-inspection-link]')) {
      const link = document.createElement('a');
      link.className = 'btn btn-ghost';
      link.href = '#inspections';
      link.dataset.phase3cInspectionLink = 'true';
      link.textContent = 'Start an Inspection';
      heroActions.appendChild(link);
    }
  }

  function separateModalTiles() {
    const modal = document.getElementById('templateModal');
    const body = modal?.querySelector('.template-modal__body');
    if (!modal || !body || body.querySelector('.phase3c-inspection-modal-group')) return;

    const inspectionTiles = Array.from(body.querySelectorAll('.template-tile')).filter(isInspectionTile);
    if (inspectionTiles.length === 0) return;
    inspectionTiles.forEach(rewriteInspectionHref);
    cleanEmptyPermitGroups(body);

    const wrapper = document.createElement('section');
    wrapper.className = 'phase3c-inspection-modal-group';
    wrapper.innerHTML = `
      <header class="phase3c-inspection-modal-group__header">
        <h3 class="phase3c-inspection-modal-group__title">🔎 Inspections &amp; checklists</h3>
        <p class="phase3c-inspection-modal-group__lead">Record findings and actions without creating a permit-to-work.</p>
      </header>
      <div class="phase3c-inspection-modal-grid"></div>`;
    const grid = wrapper.querySelector('.phase3c-inspection-modal-grid');
    inspectionTiles.forEach(tile => {
      const icon = tile.querySelector('.template-tile__icon');
      if (icon) icon.textContent = '🔎';
      grid.appendChild(tile);
    });
    body.appendChild(wrapper);

    const title = document.getElementById('templateModalTitle');
    if (title) title.textContent = 'Choose a permit or inspection';
    const lead = title?.parentElement?.querySelector('.section__lead');
    if (lead) lead.textContent = 'Permits authorise controlled work; inspections record findings, actions and follow-up.';
  }

  function enhance() {
    if (!document.getElementById('templates')) return;
    addStyles();
    separateHomepageTiles();
    separateModalTiles();
  }

  const schedule = () => window.setTimeout(enhance, 0);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', schedule, { once: true });
  else schedule();
})();
