document.addEventListener('DOMContentLoaded', () => {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.body.classList.add('ecotwin-loading');

  const loader = document.createElement('div');
  loader.className = 'ecotwin-loader';
  loader.setAttribute('role', 'status');
  loader.setAttribute('aria-live', 'polite');
  loader.innerHTML = `
    <div class="loader-mark" aria-hidden="true">
      <span></span><span></span><span></span>
    </div>
    <div>
      <strong>Starting EcoTwin</strong>
      <small>Preparing greenhouse data and controls</small>
    </div>
  `;
  document.body.prepend(loader);

  const finishLoading = () => {
    document.body.classList.remove('ecotwin-loading');
    loader.classList.add('is-hidden');
    window.setTimeout(() => loader.remove(), reduceMotion ? 0 : 420);
  };

  window.addEventListener('load', finishLoading, { once: true });
  window.setTimeout(finishLoading, 1200);

  document.querySelectorAll('.navbar').forEach((navbar) => {
    const container = navbar.querySelector('.navbar-container');
    const menu = navbar.querySelector('.navbar-menu');
    if (!container || !menu || container.querySelector('.navbar-toggle')) return;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'navbar-toggle';
    button.setAttribute('aria-label', 'Open navigation menu');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', menu.id || 'navbarMenu');
    button.innerHTML = '<span></span><span></span><span></span>';

    container.insertBefore(button, menu);

    button.addEventListener('click', () => {
      const isOpen = navbar.classList.toggle('nav-open');
      button.setAttribute('aria-expanded', String(isOpen));
      button.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
    });

    menu.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        navbar.classList.remove('nav-open');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', 'Open navigation menu');
      });
    });
  });

  const revealTargets = document.querySelectorAll(
    '.card, .summary-card, .visual-guide-card, .greenhouse-status-card, .status-card, .hardware-card, .analytics-stat, .page-header'
  );

  revealTargets.forEach((el, index) => {
    el.classList.add('reveal-on-scroll');
    el.style.setProperty('--reveal-delay', `${Math.min(index, 10) * 45}ms`);
  });

  if ('IntersectionObserver' in window) {
    const revealObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    revealTargets.forEach((el) => revealObserver.observe(el));
  } else {
    revealTargets.forEach((el) => el.classList.add('is-visible'));
  }

  const filterGroups = document.querySelectorAll('[data-filter-group]');
  filterGroups.forEach((group) => {
    const search = group.querySelector('[data-filter-search]');
    const chips = group.querySelectorAll('[data-filter-value]');
    const items = group.querySelectorAll('[data-filter-item]');
    let active = 'all';

    const applyFilter = () => {
      const term = (search?.value || '').trim().toLowerCase();
      items.forEach((item) => {
        const category = (item.getAttribute('data-filter-category') || '').toLowerCase();
        const text = item.textContent.toLowerCase();
        const categoryMatch = active === 'all' || category.split(/\s+/).includes(active);
        const textMatch = term === '' || text.includes(term);
        item.hidden = !(categoryMatch && textMatch);
      });
    };

    chips.forEach((chip) => {
      chip.addEventListener('click', () => {
        active = (chip.getAttribute('data-filter-value') || 'all').toLowerCase();
        chips.forEach((btn) => btn.classList.toggle('active', btn === chip));
        applyFilter();
      });
    });

    search?.addEventListener('input', applyFilter);
    applyFilter();
  });

  if (!reduceMotion) {
    const parallaxEls = document.querySelectorAll('[data-parallax]');
    const updateParallax = () => {
      const y = window.scrollY || 0;
      parallaxEls.forEach((el) => {
        const speed = parseFloat(el.getAttribute('data-parallax') || '0.08');
        el.style.setProperty('--parallax-y', `${Math.round(y * speed)}px`);
      });
    };

    updateParallax();
    window.addEventListener('scroll', () => requestAnimationFrame(updateParallax), { passive: true });
  }
});
