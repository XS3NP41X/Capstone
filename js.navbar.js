document.addEventListener('DOMContentLoaded', () => {
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
});
