  </main> <!-- content-area -->
</div> <!-- main -->

<!-- jQuery & Bootstrap 5 Bundle JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
  $('#sidebarToggle').on('click', function() {
    $('#sidebar').toggleClass('show');
  });
  $('#sidebarClose').on('click', function() {
    $('#sidebar').removeClass('show');
  });

  // Desktop Sidebar Pin / Unpin & Hover Expand
  const arrowBtn = document.getElementById('sidebarCollapseArrow');
  const arrowIcon = document.getElementById('sidebarArrowIcon');

  function updateSidebarArrow() {
    const isPinned = document.body.classList.contains('sidebar-pinned');
    if (arrowIcon) {
      arrowIcon.textContent = isPinned ? 'chevron_left' : 'chevron_right';
      if (arrowBtn) {
        arrowBtn.setAttribute('title', isPinned ? 'Collapse Sidebar' : 'Pin Sidebar Expanded');
      }
    }
  }

  // Check saved pin state; default is unpinned (collapsed)
  const isPinnedSaved = localStorage.getItem('vibesoft_sidebar_pinned');
  if (isPinnedSaved === 'true') {
    document.body.classList.add('sidebar-pinned');
  } else {
    document.body.classList.remove('sidebar-pinned');
  }
  updateSidebarArrow();

  if (arrowBtn) {
    arrowBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      document.body.classList.toggle('sidebar-pinned');
      const nowPinned = document.body.classList.contains('sidebar-pinned');
      localStorage.setItem('vibesoft_sidebar_pinned', nowPinned ? 'true' : 'false');
      updateSidebarArrow();
    });
  }

  // Preserve and restore sidebar scroll position across navigation
  const sidebar = document.getElementById('sidebar');
  if (sidebar) {
    // 1. Restore saved scroll position
    const savedPos = sessionStorage.getItem('vibesoft_sidebar_scroll');
    if (savedPos !== null) {
      sidebar.scrollTop = parseInt(savedPos, 10);
    }

    // 2. Ensure active nav item is comfortably in view (centered if scrolled out)
    const activeLink = sidebar.querySelector('.sidebar-nav .nav-link.active');
    if (activeLink) {
      const sRect = sidebar.getBoundingClientRect();
      const aRect = activeLink.getBoundingClientRect();
      if (aRect.top < sRect.top + 30 || aRect.bottom > sRect.bottom - 30) {
        activeLink.scrollIntoView({ block: 'center', behavior: 'instant' });
      }
    }

    // 3. Save position whenever sidebar is scrolled
    let scrollTimer;
    sidebar.addEventListener('scroll', function() {
      clearTimeout(scrollTimer);
      scrollTimer = setTimeout(function() {
        sessionStorage.setItem('vibesoft_sidebar_scroll', sidebar.scrollTop);
      }, 50);
    }, { passive: true });

    // 4. Save position immediately on clicking any link inside sidebar
    $('#sidebar .nav-link').on('click', function() {
      sessionStorage.setItem('vibesoft_sidebar_scroll', sidebar.scrollTop);
    });
  }

  // Prevent browser from restoring window scroll from previous page so new page always opens at top
  if ('scrollRestoration' in history) {
    history.scrollRestoration = 'manual';
  }

  // Live Customer Search in Topbar
  const searchInput = document.getElementById('topbarSearchInput');
  const searchResults = document.getElementById('topbarSearchResults');
  let searchTimer = null;

  if (searchInput && searchResults) {
    searchInput.addEventListener('input', function() {
      const q = this.value.trim();
      clearTimeout(searchTimer);
      if (q.length < 2) {
        searchResults.style.display = 'none';
        searchResults.innerHTML = '';
        return;
      }
      searchTimer = setTimeout(function() {
        fetch('<?= BASE_URL ?>api/search_customers.php?q=' + encodeURIComponent(q))
          .then(res => res.json())
          .then(data => {
            if (!Array.isArray(data) || data.length === 0) {
              searchResults.innerHTML = '<div class="p-3 text-center text-muted small"><i class="bi bi-person-x d-block fs-5 mb-1 text-secondary"></i>No clients found matching "' + $('<div>').text(q).html() + '"</div>';
              searchResults.style.display = 'block';
              return;
            }
            let html = '<div class="px-2 py-1 small fw-bold text-muted border-bottom uppercase" style="font-size:0.7rem;">MATCHING CLIENTS (' + data.length + ')</div>';
            data.forEach(c => {
              const due = parseFloat(c.total_due || 0);
              const dueHtml = due > 0 ? '<span class="badge bg-danger-subtle text-danger border" style="font-size:0.65rem;">Due: ₹' + due.toLocaleString('en-IN') + '</span>' : '<span class="badge bg-success-subtle text-success border" style="font-size:0.65rem;">No Dues</span>';
              const initial = (c.name || 'C').charAt(0).toUpperCase();
              html += `
                <a href="<?= BASE_URL ?>modules/customers/view.php?id=${c.id}" class="d-flex align-items-center p-2 text-decoration-none border-bottom dropdown-item" style="white-space: normal;">
                  <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center me-2 text-primary fw-bold" style="width:32px; height:32px; font-size:13px; flex-shrink:0;">
                    ${initial}
                  </div>
                  <div class="flex-grow-1 overflow-hidden">
                    <div class="fw-bold text-dark text-truncate" style="font-size:0.85rem;">${$('<div>').text(c.name).html()}</div>
                    <small class="text-muted d-block" style="font-size:0.75rem;"><i class="bi bi-telephone me-1 text-primary"></i>${c.phone || 'No phone'} ${c.contact_person ? '• ' + $('<div>').text(c.contact_person).html() : ''}</small>
                  </div>
                  <div class="ms-2 text-end flex-shrink-0">
                    ${dueHtml}
                  </div>
                </a>
              `;
            });
            html += `<a href="<?= BASE_URL ?>modules/search.php?q=${encodeURIComponent(q)}" class="d-block text-center py-2 small fw-semibold text-primary text-decoration-none bg-light rounded mt-1">See all customer results →</a>`;
            searchResults.innerHTML = html;
            searchResults.style.display = 'block';
          })
          .catch(err => {
            console.error('Search error:', err);
          });
      }, 200);
    });

    document.addEventListener('click', function(e) {
      if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
        searchResults.style.display = 'none';
      }
    });
  }
});
</script>

</body>
</html>
<?php if (ob_get_level() > 0) { ob_end_flush(); } ?>
