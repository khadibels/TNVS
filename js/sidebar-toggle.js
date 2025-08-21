// js/sidebar-toggle.js
document.addEventListener('DOMContentLoaded', function() {
  const sidebarToggle = document.getElementById('sidebarToggle2');
  const sidebarNav = document.querySelector('.sidebar');

  sidebarToggle.addEventListener('click', function() {
    sidebarNav.classList.toggle('show');
  });

  document.addEventListener('click', function(e) {
    if (window.innerWidth <= 900 && sidebarNav.classList.contains('show')) {
      if (!sidebarNav.contains(e.target) && !sidebarToggle.contains(e.target)) {
        sidebarNav.classList.remove('show');
      }
    }
  });
});
