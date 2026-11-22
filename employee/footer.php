  </main> <!-- content-area -->
</div> <!-- main -->

<!-- jQuery & Bootstrap 5 Bundle JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
  function openSidebar() {
    $('#sidebar').addClass('show');
    $('#sidebarBackdrop').addClass('show');
    $('body').addClass('overflow-hidden');
  }
  function closeSidebar() {
    $('#sidebar').removeClass('show');
    $('#sidebarBackdrop').removeClass('show');
    $('body').removeClass('overflow-hidden');
  }

  $('#sidebarToggle').on('click', function(e) {
    e.preventDefault();
    if ($('#sidebar').hasClass('show')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  });

  $('#sidebarClose, #sidebarBackdrop').on('click', function(e) {
    e.preventDefault();
    closeSidebar();
  });

  if ($(window).width() < 992) {
    $('.sidebar-nav a').on('click', function() {
      closeSidebar();
    });
  }

  // Automatically ensure active navigation tab is visible without manual scrolling
  function scrollActiveNavIntoView() {
    const activeNav = document.querySelector('#sidebar .nav-link.active');
    const sidebar = document.getElementById('sidebar');
    if (activeNav && sidebar) {
      const activeRect = activeNav.getBoundingClientRect();
      const sidebarRect = sidebar.getBoundingClientRect();
      if (activeRect.top < sidebarRect.top || activeRect.bottom > sidebarRect.bottom) {
        activeNav.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }
  }

  setTimeout(scrollActiveNavIntoView, 100);

  // When clicking any tab, ensure it scrolls into visible area smoothly
  $('.sidebar-nav .nav-link').on('click', function() {
    $('.sidebar-nav .nav-link').removeClass('active');
    $(this).addClass('active');
    this.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
});
</script>

</body>
</html>
