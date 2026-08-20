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
})();
