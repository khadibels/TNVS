// js/sidebar-toggle.js
document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.querySelector('.sidebar');
  const toggles = [
    document.getElementById('sidebarToggle2'),
    document.getElementById('sidebarToggle') // (if you ever use this id)
  ].filter(Boolean);

  const BACKDROP_ID = 'sidebarBackdrop';

  function ensureBackdrop() {
    let b = document.getElementById(BACKDROP_ID);
    if (!b) {
      b = document.createElement('div');
      b.id = BACKDROP_ID;
      document.body.appendChild(b);
      b.addEventListener('click', closeSidebar);
    }
  }

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('show');
    document.body.classList.add('sidebar-open');
    ensureBackdrop();
  }

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('show');
    document.body.classList.remove('sidebar-open');
    const b = document.getElementById(BACKDROP_ID);
    if (b) b.remove();
  }

  function toggleSidebar(e) {
    e?.preventDefault?.();
    if (!sidebar) return;
    sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
  }

  // Toggle buttons
  toggles.forEach(btn => btn.addEventListener('click', toggleSidebar));

  // Close with ESC
  window.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeSidebar();
  });

  // Close after clicking any link (except the Settings parent which just expands)
  document.querySelectorAll('.sidebar a').forEach(a => {
    a.addEventListener('click', () => {
      if (window.innerWidth <= 1000 && !a.classList.contains('settings-parent')) {
        closeSidebar();
      }
    });
  });

  // Safety: clicking outside closes (when no backdrop for some reason)
  document.addEventListener('click', e => {
    if (window.innerWidth <= 1000 && sidebar?.classList.contains('show')) {
      const toggledBy = toggles.some(t => t.contains(e.target));
      if (!sidebar.contains(e.target) && !toggledBy) closeSidebar();
    }
  });

  // On resize to desktop, clear mobile state
  window.addEventListener('resize', () => {
    if (window.innerWidth > 1000) closeSidebar();
  });

  // --- Scroll Persistence ---
  const savedScroll = localStorage.getItem('sidebarScroll');
  if (savedScroll && sidebar) {
    sidebar.scrollTop = parseInt(savedScroll, 10);
  }

  // Save on unload
  window.addEventListener('beforeunload', () => {
    if (sidebar) {
      localStorage.setItem('sidebarScroll', sidebar.scrollTop);
    }
  });

});
