(() => {
  const menuButton = document.querySelector('.menu-button');
  const navigation = document.querySelector('.topnav');
  const search = document.querySelector('#guide-search');
  const clear = document.querySelector('#clear-search');
  const status = document.querySelector('#search-status');
  const noResults = document.querySelector('#no-results');
  const sections = [...document.querySelectorAll('.guide-section')];
  const searchable = [...document.querySelectorAll('[data-search]')];

  // Keep the standalone help centre on the same visual identity as the main
  // application, including browser-tab icons on pages that do not use PHP's
  // shared cache_meta_tags() helper.
  const iconLinks = [
    ['icon', 'image/svg+xml', '../favicon.svg'],
    ['icon', 'image/x-icon', '../favicon.ico'],
    ['apple-touch-icon', '', '../icon-192.png'],
  ];
  iconLinks.forEach(([rel, type, href]) => {
    if (document.head.querySelector(`link[rel="${rel}"]`)) return;
    const link = document.createElement('link');
    link.rel = rel;
    if (type) link.type = type;
    link.href = href;
    document.head.appendChild(link);
  });

  const guideMark = document.querySelector('.brand-mark');
  if (guideMark && guideMark.textContent.trim() === 'P') {
    guideMark.textContent = '';
    guideMark.style.background = 'transparent';
    guideMark.style.overflow = 'hidden';
    const image = document.createElement('img');
    image.src = '../favicon.svg';
    image.alt = '';
    image.setAttribute('aria-hidden', 'true');
    image.style.display = 'block';
    image.style.width = '100%';
    image.style.height = '100%';
    image.style.objectFit = 'contain';
    guideMark.appendChild(image);
  }

  menuButton?.addEventListener('click', () => {
    const open = menuButton.getAttribute('aria-expanded') === 'true';
    menuButton.setAttribute('aria-expanded', String(!open));
    navigation.classList.toggle('open', !open);
  });

  navigation?.addEventListener('click', event => {
    if (event.target.matches('a')) {
      navigation.classList.remove('open');
      menuButton?.setAttribute('aria-expanded', 'false');
    }
  });

  const normalise = value => value.toLowerCase().trim().replace(/\s+/g, ' ');

  function runSearch() {
    const query = normalise(search.value);
    searchable.forEach(item => item.classList.remove('search-hidden', 'search-match'));
    sections.forEach(section => section.classList.remove('search-hidden'));
    noResults.hidden = true;

    if (query.length < 2) {
      status.textContent = query ? 'Type at least two letters to search.' : '';
      return;
    }

    const terms = query.split(' ');
    let matches = 0;
    sections.forEach(section => {
      const items = [...section.querySelectorAll('[data-search]')].filter(item => item !== section);
      const sectionText = normalise(`${section.dataset.search || ''} ${section.textContent}`);
      const sectionMatches = terms.every(term => sectionText.includes(term));
      let childMatches = 0;

      items.forEach(item => {
        const text = normalise(`${item.dataset.search || ''} ${item.textContent}`);
        const matchesItem = terms.every(term => text.includes(term));
        item.classList.toggle('search-hidden', !matchesItem);
        if (matchesItem) {
          item.classList.add('search-match');
          childMatches += 1;
          matches += 1;
        }
      });

      const visible = childMatches > 0 || (items.length === 0 && sectionMatches);
      section.classList.toggle('search-hidden', !visible);
      if (visible && childMatches === 0) matches += 1;
    });

    status.textContent = matches === 1 ? '1 helpful topic found.' : `${matches} helpful topics found.`;
    noResults.hidden = matches !== 0;
  }

  search?.addEventListener('input', runSearch);
  clear?.addEventListener('click', () => {
    search.value = '';
    runSearch();
    search.focus();
  });

  document.querySelectorAll('details').forEach(details => {
    details.addEventListener('toggle', () => {
      if (details.open && matchMedia('(max-width: 650px)').matches) {
        details.scrollIntoView({ block: 'nearest' });
      }
    });
  });
})();
