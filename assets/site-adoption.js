(() => {
  // Public landing page: keep the existing primary actions and add a clear,
  // low-friction showcase link for first-time operatives and customers.
  const heroActions = document.querySelector('.hero__actions');
  if (heroActions && !heroActions.querySelector('[data-how-it-works-link]')) {
    const link = document.createElement('a');
    link.className = 'btn btn-secondary';
    link.href = '/how-it-works.php';
    link.dataset.howItWorksLink = 'true';
    link.textContent = 'How It Works';
    heroActions.appendChild(link);
  }

  // Admin dashboard: expose the new permanent notice-board QR manager without
  // requiring administrators to know or bookmark a hidden URL.
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

    // Place it before the older live-permit QR tools when possible.
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
