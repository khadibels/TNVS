document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-profile-menu]').forEach((menu) => {
    const trigger = menu.querySelector('[data-profile-trigger]');
    const dropdown = menu.querySelector('[data-profile-dropdown]');
    if (!trigger || !dropdown) return;

    trigger.addEventListener('click', (event) => {
      event.stopPropagation();
      const isOpen = dropdown.classList.toggle('show');
      trigger.setAttribute('aria-expanded', String(isOpen));
    });

    document.addEventListener('click', (event) => {
      if (!menu.contains(event.target)) {
        dropdown.classList.remove('show');
        trigger.setAttribute('aria-expanded', 'false');
      }
    });
  });
});
