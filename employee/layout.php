<?php
// employee/layout.php — Employee Portal Layout
// Production Error Security: Never expose raw PHP warnings, file paths or stack traces in UI
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

require_once __DIR__ . '/../includes/functions.php';
require_login();
\App\Security\SecurityHeaders::applyNoCache();

// Verify employee association or auto-detect from database
$empId = $_SESSION['employee_id'] ?? null;
if (!$empId && !empty($_SESSION['user_id'])) {
    $find = $pdo->prepare("SELECT id FROM employees WHERE user_id = ? OR email = ? LIMIT 1");
    $find->execute([$_SESSION['user_id'], $_SESSION['user_email'] ?? '']);
    $foundId = $find->fetchColumn();
    if ($foundId) {
        $empId = $foundId;
        $_SESSION['employee_id'] = $empId;
    }
}

// If admin previewing employee portal without linked employee, use first active employee
if (!$empId && (is_admin() || is_manager())) {
    $firstEmp = $pdo->query("SELECT id FROM employees WHERE status='active' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($firstEmp) {
        $empId = $firstEmp;
    }
}

if (!$empId && !can_access_admin_panel()) {
    set_flash("No linked employee profile found for your account. Please contact administrator.", "danger");
    header('Location: ' . BASE_URL . 'modules/auth/logout.php');
    exit;
}

// Fetch current employee data
global $pdo;
$curEmp = null;
if ($empId) {
    $eStmt = $pdo->prepare("SELECT e.*, d.name as dept_title, des.name as desig_title 
                            FROM employees e 
                            LEFT JOIN departments d ON d.id = e.department_id 
                            LEFT JOIN designations des ON des.id = e.designation_id 
                            WHERE e.id = ?");
    $eStmt->execute([$empId]);
    $curEmp = $eStmt->fetch();
}

// Fallback safe array so null array offset error never occurs
if (!$curEmp) {
    $curEmp = [
        'id' => $empId ?: 1,
        'full_name' => $_SESSION['user_name'] ?? 'Team Member',
        'first_name' => $_SESSION['user_name'] ?? 'Team Member',
        'last_name' => '',
        'employee_code' => 'EMP101',
        'designation' => 'Team Member',
        'department' => 'Operations',
        'base_salary' => 0,
        'email' => $_SESSION['user_email'] ?? '',
        'phone' => '',
        'status' => 'active',
        'joining_date' => date('Y-m-d')
    ];
}

$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VibeSoft CMS — Employee Portal</title>
  <meta name="robots" content="noindex, nofollow, noarchive">
  
  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- Bootstrap 5 CSS & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  
  <style>
    :root {
      --sidebar-width: 250px;
      --topbar-height: 64px;
      --primary-color: #0d6efd;
      --body-bg: #f8fafc;
      --card-radius: 14px;
      --card-shadow: 0 2px 8px -1px rgba(0, 0, 0, 0.05), 0 1px 3px 0 rgba(0, 0, 0, 0.03);
      --card-shadow-hover: 0 4px 14px -2px rgba(0, 0, 0, 0.08), 0 2px 6px -1px rgba(0, 0, 0, 0.04);
    }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--body-bg);
      color: #2b3674;
      overflow-x: hidden;
    }
    .card {
      border-radius: var(--card-radius) !important;
      box-shadow: var(--card-shadow) !important;
      border: 1px solid rgba(226, 232, 240, 0.85) !important;
      background: #ffffff;
      transition: box-shadow 0.2s ease, transform 0.2s ease;
    }
    .card:hover {
      box-shadow: var(--card-shadow-hover) !important;
    }
    #sidebar {
      width: var(--sidebar-width);
      height: 100vh;
      background: #ffffff;
      border-right: 1px solid #e9ecef;
      position: fixed;
      top: 0; left: 0;
      overflow-y: auto;
      scroll-behavior: smooth;
      scrollbar-width: thin;
      scrollbar-color: #e2e8f0 transparent;
      z-index: 1040;
      transition: transform 0.3s ease-in-out;
    }
    #sidebar::-webkit-scrollbar {
      width: 4px;
    }
    #sidebar::-webkit-scrollbar-thumb {
      background: #e2e8f0;
      border-radius: 4px;
    }
    .sidebar-header {
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid #f1f3f5;
    }
    .sidebar-heading {
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8f9bba;
      padding: 0.8rem 1.5rem 0.3rem;
    }
    .sidebar-nav {
      list-style: none;
      padding: 0 0.75rem;
      margin: 0;
    }
    .sidebar-nav .nav-link {
      display: flex;
      align-items: center;
      padding: 0.65rem 1rem;
      color: #5d6778;
      font-size: 0.875rem;
      font-weight: 500;
      border-radius: 10px;
      margin-bottom: 0.25rem;
      transition: all 0.2s ease;
      text-decoration: none;
    }
    .sidebar-nav .nav-link i {
      font-size: 1.15rem;
      margin-right: 0.75rem;
      color: #8f9bba;
      transition: color 0.2s;
    }
    .sidebar-nav .nav-link:hover {
      color: var(--primary-color);
      background: #f4f7fe;
    }
    .sidebar-nav .nav-link:hover i {
      color: var(--primary-color);
    }
    .sidebar-nav .nav-link.active {
      color: #ffffff;
      background: var(--primary-color);
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(13, 110, 253, 0.25);
    }
    .sidebar-nav .nav-link.active i {
      color: #ffffff;
    }
    #main {
      margin-left: var(--sidebar-width);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      transition: margin-left 0.3s ease-in-out;
      min-width: 0;
    }
    .topbar {
      height: var(--topbar-height);
      background: #ffffff;
      border-bottom: 1px solid #e9ecef;
      padding: 0 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 1020;
    }
    .content-area {
      padding: 1.5rem;
      flex: 1;
      min-width: 0;
    }
    .sidebar-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(15, 23, 42, 0.55);
      backdrop-filter: blur(2px);
      -webkit-backdrop-filter: blur(2px);
      z-index: 1035;
      display: none;
      opacity: 0;
      transition: opacity 0.25s ease-in-out;
    }
    .sidebar-backdrop.show {
      display: block;
      opacity: 1;
    }
    /* Mobile Responsive Tables & Touch Scrolling */
    .table-responsive {
      -webkit-overflow-scrolling: touch;
      overflow-x: auto;
      position: relative;
      scrollbar-width: thin;
      scrollbar-color: #cbd5e1 transparent;
      width: 100%;
    }
    .table-responsive::-webkit-scrollbar {
      height: 5px;
    }
    .table-responsive::-webkit-scrollbar-track {
      background: #f1f5f9;
      border-radius: 4px;
    }
    .table-responsive::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 4px;
    }
    .table-responsive::-webkit-scrollbar-thumb:hover {
      background: #94a3b8;
    }
    .table-responsive table th,
    .table-responsive table td {
      vertical-align: middle;
    }

    @media (max-width: 991.98px) {
      #sidebar { 
        transform: translateX(-100%); 
        box-shadow: none;
      }
      #sidebar.show { 
        transform: translateX(0); 
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
      }
      #main { margin-left: 0; }
      .topbar { padding: 0 1rem; }
      .content-area { padding: 1rem 0.75rem; }
    }

    @media (max-width: 767.98px) {
      .table-responsive table {
        font-size: 0.82rem !important;
      }
      .table-responsive table th {
        padding: 0.65rem 0.55rem !important;
        font-size: 0.74rem !important;
        letter-spacing: 0.02em;
        white-space: nowrap;
      }
      .table-responsive table td {
        padding: 0.65rem 0.55rem !important;
      }
      .btn-sm {
        padding: 0.35rem 0.65rem;
        font-size: 0.78rem;
      }
    }

    @media (max-width: 575.98px) {
      .topbar { padding: 0 0.5rem; }
      .content-area { padding: 0.65rem 0.35rem; }
      .card { border-radius: 12px !important; }
      
      /* Mobile Horizontal Nav Tabs (e.g. in profile and dashboards) */
      .nav-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        -ms-overflow-style: none;
      }
      .nav-tabs::-webkit-scrollbar {
        display: none;
      }
      .nav-tabs .nav-link {
        white-space: nowrap;
        font-size: 0.8rem;
        padding: 0.55rem 0.85rem;
      }

      /* Heading scale down on extra small screens */
      h3 { font-size: 1.25rem !important; }
      h4 { font-size: 1.15rem !important; }
      h5 { font-size: 1rem !important; }
    }
  </style>
</head>
<body>

<!-- Sidebar -->
<aside id="sidebar">
  <div class="sidebar-header d-flex align-items-center justify-content-between">
    <a href="<?= BASE_URL ?>employee/dashboard.php" class="d-flex align-items-center text-decoration-none">
      <img src="<?= get_company_logo() ?>" alt="VibeSoft" height="38" style="max-width: 160px; object-fit: contain;">
    </a>
    <button class="btn btn-sm btn-light d-lg-none" id="sidebarClose"><i class="bi bi-x-lg"></i></button>
  </div>

  <ul class="sidebar-nav py-2">
    <li>
      <a class="nav-link <?= strpos($currentScript, 'dashboard.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>employee/dashboard.php">
        <i class="bi bi-grid-1x2-fill"></i> Dashboard
      </a>
    </li>

    <li class="sidebar-heading">My Work</li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'attendance.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>employee/attendance.php">
        <i class="bi bi-calendar-check"></i> My Attendance
      </a>
    </li>
    <li>
      <a class="nav-link <?= (strpos($currentScript, 'work-reports.php') !== false || strpos($currentScript, 'work-report-create.php') !== false) ? 'active' : '' ?>" href="<?= BASE_URL ?>employee/work-reports.php">
        <i class="bi bi-journal-text"></i> Daily Work Reports
      </a>
    </li>

    <li class="sidebar-heading">Finance & Leave</li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'salary.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>employee/salary.php">
        <i class="bi bi-cash-stack"></i> My Salary
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'salary-slips.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>employee/salary-slips.php">
        <i class="bi bi-receipt"></i> Salary Slips
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'leave.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>employee/leave.php">
        <i class="bi bi-calendar2-heart"></i> Apply Leave
      </a>
    </li>

    <li class="sidebar-heading">Account</li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'notifications.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>employee/notifications.php">
        <i class="bi bi-bell"></i> My Notifications
      </a>
    </li>
    <li>
      <a class="nav-link <?= strpos($currentScript, 'profile.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>employee/profile.php">
        <i class="bi bi-person-bounding-box"></i> My Profile
      </a>
    </li>

    <?php if (is_admin() || is_manager()): ?>
    <li class="sidebar-heading">Management</li>
    <li>
      <a class="nav-link text-primary fw-semibold" href="<?= BASE_URL ?>index.php">
        <i class="bi bi-speedometer2 text-primary"></i> Switch to Admin
      </a>
    </li>
    <?php endif; ?>
  </ul>

  <div class="p-3 border-top mt-auto">
    <a href="<?= BASE_URL ?>modules/auth/logout.php" class="btn btn-outline-danger btn-sm w-100 fw-semibold">
      <i class="bi bi-box-arrow-right me-1"></i> Sign Out
    </a>
  </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Main Wrapper -->
<div id="main">
  <!-- Topbar -->
  <header class="topbar">
    <div class="d-flex align-items-center flex-grow-1 overflow-hidden me-2">
      <button class="btn btn-light d-lg-none me-2 flex-shrink-0" id="sidebarToggle"><i class="bi bi-list fs-5"></i></button>
      <div class="overflow-hidden">
        <h6 class="mb-0 fw-semibold text-dark text-truncate">
          Namaste, <span class="text-primary"><?= e($curEmp['full_name'] ?? $_SESSION['user_name'] ?? 'Team Member') ?></span>
        </h6>
        <div class="text-muted text-truncate" style="font-size:0.72rem;">
          <?= e($curEmp['employee_code'] ?? 'EMP') ?> · <?= e($curEmp['designation'] ?? 'VibeSoft Team') ?>
        </div>
      </div>
    </div>
    
    <div class="d-flex align-items-center gap-2 flex-shrink-0">
      <!-- Quick Attendance status pill -->
      <span class="badge bg-light text-dark border d-none d-sm-inline-flex align-items-center gap-1 py-2 px-3" style="font-size:0.75rem;">
        <i class="bi bi-calendar-event text-primary"></i> <?= date('l, d M Y') ?>
      </span>

      <!-- Centralized Notification Bell -->
      <?php render_notification_bell(); ?>

      <!-- Profile Avatar Dropdown -->
      <div class="dropdown">
        <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
          <?php 
            $topbarAvatar = (!empty($curEmp['profile_photo']) && file_exists(__DIR__ . '/../' . $curEmp['profile_photo'])) 
                ? BASE_URL . e($curEmp['profile_photo']) 
                : "https://ui-avatars.com/api/?name=" . urlencode($curEmp['full_name'] ?? 'User') . "&background=0d6efd&color=fff";
          ?>
          <img src="<?= $topbarAvatar ?>" 
               class="rounded-circle border shadow-sm" width="38" height="38" alt="Profile" style="object-fit:cover;">
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
          <li class="dropdown-header fw-bold"><?= e($curEmp['full_name'] ?? 'User') ?></li>
          <li><span class="dropdown-item-text small text-muted"><?= e($curEmp['email'] ?? '') ?></span></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= BASE_URL ?>employee/notifications.php"><i class="bi bi-bell me-2"></i>Notifications</a></li>
          <li><a class="dropdown-item" href="<?= BASE_URL ?>employee/profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
          <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>modules/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
        </ul>
      </div>
    </div>
  </header>

  <!-- Page Content Container -->
  <main class="content-area">
