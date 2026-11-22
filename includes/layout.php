<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ledger_helper.php';
require_once __DIR__ . '/audit_helper.php';
require_admin_panel();

// Current script path for active menu highlighting
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VibeSoft CMS — ERP & Management System</title>
  <meta name="robots" content="noindex, nofollow, noarchive">
  
  <!-- Google Fonts: Inter & Material Symbols -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
  
  <!-- Bootstrap 5 CSS & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  
  <style>
    .material-symbols-outlined {
      font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
      vertical-align: middle;
      display: inline-block;
      line-height: 1;
    }
    :root {
      --sidebar-width: 260px;
      --sidebar-collapsed-width: 72px;
      --topbar-height: 64px;
      --primary-color: #0d6efd;
      --body-bg: #f4f6f9;
      --card-radius: 12px;
    }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--body-bg);
      color: #2b3674;
    }
    
    #sidebar {
      width: var(--sidebar-width);
      height: 100vh;
      background: #ffffff;
      border-right: 1px solid #e9ecef;
      position: fixed;
      top: 0; left: 0;
      overflow-y: auto;
      overflow-x: hidden;
      z-index: 1040;
      transition: width 0.22s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.22s ease, transform 0.3s ease-in-out;
      scroll-behavior: smooth;
    }
    #sidebar::-webkit-scrollbar {
      width: 5px;
    }
    #sidebar::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 4px;
    }
    
    .sidebar-header {
      padding: 1.15rem 1.25rem;
      border-bottom: 1px solid #f1f3f5;
      min-height: 64px;
      transition: padding 0.2s ease;
    }
    
    .sidebar-heading {
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8f9bba;
      padding: 0.8rem 1.5rem 0.3rem;
      white-space: nowrap;
      transition: opacity 0.2s ease, height 0.2s ease, padding 0.2s ease;
    }
    
    .sidebar-nav {
      list-style: none;
      padding: 0 0.75rem;
      margin: 0;
      transition: padding 0.2s ease;
    }
    
    .sidebar-nav .nav-link {
      display: flex;
      align-items: center;
      padding: 0.6rem 1rem;
      color: #5d6778;
      font-size: 0.875rem;
      font-weight: 500;
      border-radius: 8px;
      margin-bottom: 2px;
      text-decoration: none;
      white-space: nowrap;
      overflow: hidden;
      transition: background 0.15s ease, color 0.15s ease, padding 0.2s ease, font-size 0.2s ease;
    }
    
    .sidebar-nav .nav-link i,
    .sidebar-nav .nav-link .material-symbols-outlined {
      font-size: 1.1rem;
      margin-right: 0.75rem;
      min-width: 22px;
      width: 22px;
      text-align: center;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: font-size 0.2s ease, margin 0.2s ease;
    }
    
    .sidebar-nav .nav-link:hover {
      background: #f0f5ff;
      color: var(--primary-color);
    }
    
    .sidebar-nav .nav-link.active {
      background: #0d6efd;
      color: #ffffff !important;
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(13, 110, 253, 0.25);
    }
    
    .sidebar-nav .nav-link.active i {
      color: #ffffff !important;
    }

    #main {
      margin-left: var(--sidebar-width);
      min-height: 100vh;
      transition: margin-left 0.22s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Desktop Collapsed by Default & Hover Expand */
    @media (min-width: 992px) {
      #sidebar {
        width: var(--sidebar-collapsed-width);
      }
      #main {
        margin-left: var(--sidebar-collapsed-width);
      }

      /* When Hovered OR Pinned */
      #sidebar:hover,
      #sidebar.is-hovered,
      body.sidebar-pinned #sidebar {
        width: var(--sidebar-width);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.16);
        z-index: 1050;
      }
      body.sidebar-pinned #main {
        margin-left: var(--sidebar-width);
      }

      /* Collapsed View Adjustments */
      body:not(.sidebar-pinned) #sidebar:not(:hover):not(.is-hovered) .sidebar-header {
        padding: 1.1rem 0.5rem;
        justify-content: center !important;
      }
      body:not(.sidebar-pinned) #sidebar:not(:hover):not(.is-hovered) .sidebar-logo-link img {
        max-width: 36px !important;
        object-fit: cover;
        object-position: left;
      }
      body:not(.sidebar-pinned) #sidebar:not(:hover):not(.is-hovered) #sidebarCollapseArrow {
        display: none !important;
      }
      body:not(.sidebar-pinned) #sidebar:not(:hover):not(.is-hovered) .sidebar-heading {
        opacity: 0;
        height: 8px;
        padding: 0;
        margin: 6px 12px;
        border-bottom: 1px solid #e2e8f0;
        overflow: hidden;
      }
      body:not(.sidebar-pinned) #sidebar:not(:hover):not(.is-hovered) .sidebar-nav {
        padding: 0 0.4rem;
      }
      body:not(.sidebar-pinned) #sidebar:not(:hover):not(.is-hovered) .sidebar-nav .nav-link {
        font-size: 0 !important;
        padding: 0.65rem 0;
        justify-content: center;
      }
      body:not(.sidebar-pinned) #sidebar:not(:hover):not(.is-hovered) .sidebar-nav .nav-link i,
      body:not(.sidebar-pinned) #sidebar:not(:hover):not(.is-hovered) .sidebar-nav .nav-link .material-symbols-outlined {
        font-size: 1.25rem !important;
        margin-right: 0 !important;
        min-width: 24px;
      }
    }
    
    .topbar {
      height: var(--topbar-height);
      background: #ffffff;
      border-bottom: 1px solid #e9ecef;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 1.5rem;
      position: sticky;
      top: 0;
      z-index: 1020;
      box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }
    
    .content-area {
      padding: 1.75rem;
    }
    
    .stat-card {
      border: none;
      border-radius: var(--card-radius);
      background: #ffffff;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
    }
    
    .table-custom {
      background: #ffffff;
      border-radius: var(--card-radius);
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    }
    
    .badge-status {
      padding: 0.35em 0.7em;
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 50rem;
    }
    
    @media (max-width: 991.98px) {
      #sidebar {
        transform: translateX(-100%);
      }
      #sidebar.show {
        transform: translateX(0);
      }
      #main {
        margin-left: 0;
      }
    }
  </style>
</head>
<body>

<!-- Sidebar -->
<aside id="sidebar">
  <div class="sidebar-header d-flex align-items-center justify-content-between">
    <a href="<?= BASE_URL ?>index.php" class="d-flex align-items-center text-decoration-none sidebar-logo-link overflow-hidden">
      <img src="<?= get_company_logo() ?>" alt="VibeSoft" height="36" class="sidebar-logo-img" style="max-width: 150px; object-fit: contain; transition: max-width 0.2s ease;">
    </a>
    <div class="d-flex align-items-center gap-1">
      <button class="btn btn-sm btn-light border-0 d-none d-lg-flex align-items-center justify-content-center p-1" id="sidebarCollapseArrow" type="button" title="Collapse / Expand Sidebar" style="width:30px; height:30px; border-radius:8px; color:#475569; background:#f1f5f9;">
        <span class="material-symbols-outlined fs-5" id="sidebarArrowIcon">chevron_right</span>
      </button>
      <button class="btn btn-sm btn-light d-lg-none" id="sidebarClose"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>

  <ul class="sidebar-nav py-2">
    <li>
      <a class="nav-link <?= strpos($currentScript, 'index.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>index.php">
        <i class="bi bi-grid-1x2-fill"></i> Dashboard
      </a>
    </li>

    <!-- CLIENTS -->
    <li class="sidebar-heading">Clients</li>
    <li>
      <a class="nav-link <?= (strpos($currentScript, 'customers/list.php') !== false && !isset($_GET['type'])) ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/customers/list.php">
        <i class="bi bi-people"></i> All Clients
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'customers/monthly_clients.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/customers/monthly_clients.php">
        <i class="bi bi-arrow-repeat"></i> Monthly Clients
      </a>
    </li>
    <li>
      <a class="nav-link <?= (strpos($currentScript, 'customers/list.php') !== false && ($_GET['type']??'')==='one_time') ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/customers/list.php?type=one_time">
        <i class="bi bi-person-workspace"></i> One-Time Clients
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'customers/ledger.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/customers/ledger.php">
        <i class="bi bi-journal-text"></i> Client Ledger
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'social_media/') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/social_media/list.php">
        <i class="bi bi-share"></i> Social Media
      </a>
    </li>

    <!-- FINANCE -->
    <li class="sidebar-heading">Finance</li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'invoices/list.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/invoices/list.php">
        <i class="bi bi-receipt"></i> Invoices
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'invoices/gst_registry.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/invoices/gst_registry.php">
        <i class="bi bi-file-earmark-spreadsheet text-primary"></i> CA GST Registry
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'PaymentsReceived.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/invoices/PaymentsReceived.php">
        <i class="bi bi-cash-coin"></i> Payments Received
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'recurring_invoices/monthly_billing.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/recurring_invoices/monthly_billing.php">
        <i class="bi bi-calendar-check text-success"></i> Monthly Billing Manager
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'recurring_invoices/index.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/recurring_invoices/index.php">
        <i class="bi bi-repeat"></i> Recurring Rules
      </a>
    </li>
    <li>
      <a class="nav-link <?= (strpos($currentScript, 'expenses/expenses.php') !== false || strpos($currentScript, 'expenses/edit.php') !== false) ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/expenses/expenses.php">
        <i class="bi bi-wallet2 text-primary"></i> Company Expenses
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'expenses/personal_expenses.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/expenses/personal_expenses.php">
        <i class="bi bi-wallet text-warning"></i> Personal Expenses
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'reports/outstanding.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/reports/outstanding.php">
        <i class="bi bi-exclamation-triangle"></i> Outstanding Payments
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'reports/reports.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/reports/reports.php">
        <i class="bi bi-bar-chart-line"></i> Financial Reports
      </a>
    </li>

    <!-- HR & TEAM -->
    <li class="sidebar-heading">HR & Team</li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/employees.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/employees.php">
        <i class="bi bi-people"></i> Employees
      </a>
    </li>
    <li>
      <a class="nav-link <?= (strpos($currentScript, 'team/attendance.php') !== false && strpos($currentScript, 'attendance_report') === false && strpos($currentScript, 'attendance_adjustments') === false) ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/attendance.php">
        <i class="bi bi-calendar-check"></i> Daily Attendance
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/daily_report.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/daily_report.php">
        <i class="bi bi-journal-text"></i> Daily Work Reports
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/daily_management_report.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/daily_management_report.php">
        <i class="bi bi-clipboard-check"></i> Attendance vs Reports
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/attendance_report.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/attendance_report.php">
        <i class="bi bi-calendar3-range"></i> Attendance Calendar
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/holidays.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/holidays.php">
        <i class="bi bi-calendar-event"></i> Holiday Management
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/monthly_payout.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/monthly_payout.php">
        <i class="bi bi-calculator"></i> Payout Calculator
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/leave.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/leave.php">
        <i class="bi bi-calendar2-heart"></i> Leave Requests
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/attendance_adjustments.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/attendance_adjustments.php">
        <i class="bi bi-pencil-square"></i> Manual Adjustments
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/payroll.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/payroll.php">
        <i class="bi bi-bank"></i> Payroll & Slips
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'team/salary_advances.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/team/salary_advances.php">
        <i class="bi bi-cash-stack"></i> Salary Advances
      </a>
    </li>
    <li>
      <a class="nav-link text-success fw-semibold" href="<?= BASE_URL ?>employee/dashboard.php" target="_blank">
        <i class="bi bi-person-workspace text-success"></i> Employee Portal ↗
      </a>
    </li>

    <!-- WORK -->
    <li class="sidebar-heading">Work</li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'work/projects.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/work/projects.php">
        <i class="bi bi-kanban"></i> Projects
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'items/') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/items/list.php">
        <i class="bi bi-box-seam"></i> Services / Catalog
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'work/extra_work.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/work/extra_work.php">
        <i class="bi bi-plus-square"></i> Extra Work
      </a>
    </li>

    <li class="sidebar-heading">Settings</li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'settings/company.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/settings/company.php">
        <i class="bi bi-gear"></i> Company Settings
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'settings/notifications.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/settings/notifications.php">
        <i class="bi bi-bell"></i> Notification Settings
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'settings/send_notification.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/settings/send_notification.php">
        <i class="bi bi-send-fill"></i> Custom Email & Reminder Sender
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'settings/notification_history.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/settings/notification_history.php">
        <i class="bi bi-clock-history"></i> Notification History
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'auth/users.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/auth/users.php">
        <i class="bi bi-person-gear"></i> Users & Roles
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'credentials/') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/credentials/index.php">
        <i class="bi bi-shield-lock"></i> Credential Vault
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'settings/backup.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>modules/settings/backup.php">
        <i class="bi bi-database-gear"></i> Database Backup
      </a>
    </li>
  </ul>

  <div class="p-3 border-top mt-auto">
    <a href="<?= BASE_URL ?>modules/auth/logout.php" class="btn btn-outline-danger btn-sm w-100 fw-semibold">
      <i class="bi bi-box-arrow-right me-1"></i> Sign Out
    </a>
  </div>
</aside>

<script>
  // Immediately restore sidebar scroll position synchronously to prevent any flicker or jump
  (function() {
    try {
      var sb = document.getElementById('sidebar');
      var pos = sessionStorage.getItem('vibesoft_sidebar_scroll');
      if (sb && pos !== null) {
        sb.scrollTop = parseInt(pos, 10);
      }
    } catch(e) {}
  })();
</script>

<!-- Main Wrapper -->
<div id="main">
  <!-- Topbar -->
  <header class="topbar">
    <div class="d-flex align-items-center">
      <button class="btn btn-light d-lg-none me-2" id="sidebarToggle"><i class="bi bi-list fs-5"></i></button>
      <h6 class="mb-0 fw-semibold text-dark">Welcome back, <span class="text-primary"><?= e($_SESSION['user_name'] ?? 'Admin') ?></span></h6>
    </div>
    
    <div class="d-flex align-items-center gap-2">
      <!-- Quick Add Dropdown -->
      <div class="dropdown">
        <button class="btn btn-primary btn-sm fw-semibold shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
          <i class="bi bi-plus-circle me-1"></i> New
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width:180px;">
          <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/invoices/create.php"><i class="bi bi-receipt me-2 text-primary"></i>Invoice</a></li>
          <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/invoices/record_payment.php"><i class="bi bi-cash-coin me-2 text-success"></i>Payment</a></li>
          <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/expenses/expenses.php"><i class="bi bi-wallet2 me-2 text-warning"></i>Expense</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/customers/add.php"><i class="bi bi-person-plus me-2 text-secondary"></i>Client</a></li>
          <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/customers/monthly_clients.php"><i class="bi bi-arrow-repeat me-2 text-info"></i>Monthly Bills</a></li>
        </ul>
      </div>

      <!-- Global Customer Search Input with Live Dropdown -->
      <div class="position-relative d-none d-md-block" id="topbarSearchWrapper">
        <form method="get" action="<?= BASE_URL ?>modules/search.php" id="topbarSearchForm" autocomplete="off">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" id="topbarSearchInput" class="form-control bg-light border-start-0 ps-0" placeholder="Search customer name or phone..." required style="width: 250px;">
          </div>
        </form>
        <div id="topbarSearchResults" class="dropdown-menu shadow border-0 p-2 position-absolute" style="display:none; max-height:380px; overflow-y:auto; top:100%; left:0; width:340px; z-index:1060; border-radius:12px;">
        </div>
      </div>

      <!-- Centralized Notification Bell -->
      <?php render_notification_bell(); ?>

      <!-- Profile Avatar -->
      <div class="dropdown">
        <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
          <img src="https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['user_name'] ?? 'U') ?>&background=0d6efd&color=fff" 
               class="rounded-circle border" width="36" height="36" alt="Profile">
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
          <li class="dropdown-header fw-bold"><?= e($_SESSION['user_name'] ?? 'User') ?></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/settings/notifications.php"><i class="bi bi-bell me-2"></i> Notifications</a></li>
          <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/settings/company.php"><i class="bi bi-gear me-2"></i> Settings</a></li>
          <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>modules/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
        </ul>
      </div>
    </div>
  </header>

  <!-- Page Content Container -->
  <main class="content-area">
