(() => {
  const script = document.currentScript;
  const defaultLogo = script?.dataset.defaultLogo || '/favicon.svg';

  function defaultImage(className, alt = '') {
    const image = document.createElement('img');
    image.src = defaultLogo;
    if (className) image.className = className;
    image.alt = alt;
    image.dataset.defaultBrandLogo = 'true';
    return image;
  }

  // Standard application headers only render an image when a custom company
  // logo exists. Use the Permit System hard-hat/check as the visual fallback.
  document.querySelectorAll('.brand-mark').forEach(mark => {
    if (mark.querySelector('.brand-mark__logo')) return;
    mark.prepend(defaultImage('brand-mark__logo brand-mark__logo--default'));
  });

  // Public permit and inspection pages historically fell back to a coloured
  // square containing the first letter of the company name. Replace only those
  // fallback symbols; an uploaded company logo remains untouched.
  [
    ['.public-brand-symbol', 'public-brand-logo'],
    ['.customer-brand__symbol', 'customer-brand__logo'],
    ['.brand-symbol', 'brand-logo'],
  ].forEach(([selector, className]) => {
    document.querySelectorAll(selector).forEach(symbol => {
      symbol.replaceWith(defaultImage(className));
    });
  });

  // Admin -> Settings: make the effective fallback logo visible as the current
  // logo when the administrator has not uploaded a custom raster logo.
  const upload = document.querySelector('input[name="company_logo"]');
  if (upload) {
    const uploadField = upload.closest('.field');
    const brandingForm = upload.closest('form');
    const hasCustomPreview = brandingForm?.querySelector('.logo-preview');

    if (
      uploadField
      && brandingForm
      && !hasCustomPreview
      && !brandingForm.querySelector('[data-default-logo-preview]')
    ) {
      const field = document.createElement('div');
      field.className = 'field';
      field.dataset.defaultLogoPreview = 'true';

      const label = document.createElement('label');
      label.textContent = 'Current Logo';

      const preview = document.createElement('div');
      preview.className = 'logo-preview';

      const image = defaultImage('', 'Default Permit System logo');
      const copy = document.createElement('span');
      copy.style.color = '#94a3b8';
      copy.style.fontSize = '13px';
      copy.textContent = 'Default Permit System logo. Upload a company logo below to replace it on dashboards, public pages and printed outputs.';

      preview.append(image, copy);
      field.append(label, preview);
      uploadField.before(field);
    }
  }

  // Public landing page: give first-time users a simple visual explanation of
  // the end-to-end permit lifecycle without displacing the primary actions.
  const heroActions = document.querySelector('.hero__actions');
  if (heroActions && !heroActions.querySelector('[data-how-it-works-link]')) {
    const link = document.createElement('a');
    link.className = 'btn btn-secondary';
    link.href = '/how-it-works.php';
    link.dataset.howItWorksLink = 'true';
    link.textContent = 'How It Works';
    heroActions.appendChild(link);
  }

  // Admin dashboard: expose the permanent site notice-board QR manager next to
  // the existing live-permit QR tools.
  const adminGrid = document.querySelector('.admin-grid');
  if (adminGrid && !adminGrid.querySelector('[data-site-qr-admin-card]')) {
    const card = document.createElement('div');
    card.className = 'admin-card';
    card.dataset.siteQrAdminCard = 'true';

    const icon = document.createElement('div');
    icon.className = 'icon';
    icon.textContent = '📱';

    const title = document.createElement('h3');
    title.textContent = 'Site Notice-Board QR Codes';

    const copy = document.createElement('p');
    copy.textContent = 'Print permanent QR signs for each permit type and inspection checklist. Scanning a sign opens the current approved version of that form.';

    const link = document.createElement('a');
    link.className = 'btn';
    link.href = '/admin/site-qr-codes.php';
    link.textContent = 'Manage Site QR Codes';

    card.append(icon, title, copy, link);

    const existingQrCard = [...adminGrid.querySelectorAll('.admin-card')].find(item =>
      item.textContent.includes('QR Codes - All Permits')
    );
    if (existingQrCard) {
      adminGrid.insertBefore(card, existingQrCard);
    } else {
      adminGrid.appendChild(card);
    }
  }
})();
