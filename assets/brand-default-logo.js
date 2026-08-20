(() => {
  'use strict';

  const upload = document.querySelector('input[name="company_logo"]');
  if (!upload) return;

  const form = upload.closest('form');
  const uploadField = upload.closest('.field');
  if (!form || !uploadField || form.querySelector('.logo-preview')) return;

  const field = document.createElement('div');
  field.className = 'field default-company-logo-preview';

  const label = document.createElement('label');
  label.textContent = 'Current Logo';

  const image = document.createElement('img');
  image.src = new URL('../favicon.svg', window.location.href).toString();
  image.alt = 'Default Permit System logo';
  image.width = 112;
  image.height = 112;

  const note = document.createElement('small');
  note.textContent = 'Default logo. This hard-hat/check mark is used on dashboards, public pages and printed outputs until you upload a company logo.';

  field.append(label, image, note);
  uploadField.parentNode?.insertBefore(field, uploadField);
})();
