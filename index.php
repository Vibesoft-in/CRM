<?php
require_once 'includes/functions.php';
require_admin_panel();
require_once 'includes/layout.php';
require_once 'includes/ledger_helper.php';

use App\Notifications\NotificationService;

$notifService = NotificationService::getInstance($pdo);

// Handle Quick SMTP Save from Dashboard Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dashboard_smtp'])) {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $notifService->saveSettings([
            'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
            'smtp_user'       => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass'       => trim($_POST['smtp_pass'] ?? ''),
            'smtp_secure'     => trim($_POST['smtp_secure'] ?? 'tls'),
            'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
            'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? 'VibeSoft CMS')
        ]);
        set_flash("Email (SMTP) configuration saved successfully!", "success");
        header("Location: " . BASE_URL . "index.php");
        exit;
    }
}

$smtpSettings = $notifService->getSettings();
$isSmtpConfigured = !empty($smtpSettings['smtp_host']);
$csrf = csrf_token();

// ----------------------------------------------------
// 1. Date Range Filter Parsing & Period Resolution
// ----------------------------------------------------
$range = $_GET['range'] ?? 'this_month';
$startDate = null;
$endDate = null;
$periodLabel = '';

if (!function_exists('isValidDateStr')) {
    function isValidDateStr($date) {
        if (!is_string($date) || empty($date)) return false;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}

switch ($range) {
    case 'today':
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        $periodLabel = 'Today (' . date('d M Y') . ')';
        break;
    case 'yesterday':
        $startDate = date('Y-m-d', strtotime('-1 day'));
        $endDate = date('Y-m-d', strtotime('-1 day'));
        $periodLabel = 'Yesterday (' . date('d M Y', strtotime('-1 day')) . ')';
        break;
    case 'this_week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d', strtotime('sunday this week'));
        $periodLabel = 'This Week (' . date('d M', strtotime($startDate)) . ' – ' . date('d M Y', strtotime($endDate)) . ')';
        break;
    case 'this_month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        $periodLabel = 'This Month (' . date('F Y') . ')';
        break;
    case 'last_month':
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate = date('Y-m-t', strtotime('last day of last month'));
        $periodLabel = 'Last Month (' . date('F Y', strtotime('first day of last month')) . ')';
        break;
    case 'last_3_months':
        $startDate = date('Y-m-01', strtotime('-2 months'));
        $endDate = date('Y-m-t');
        $periodLabel = 'Last 3 Months (' . date('M Y', strtotime($startDate)) . ' – ' . date('M Y', strtotime($endDate)) . ')';
        break;
    case 'this_year':
        $startDate = date('Y-01-01');
        $endDate = date('Y-12-31');
        $periodLabel = 'This Year (' . date('Y') . ')';
        break;
    case 'custom':
        $rawFrom = $_GET['from'] ?? ($_GET['start_date'] ?? '');
        $rawTo = $_GET['to'] ?? ($_GET['end_date'] ?? '');
        
        if (isValidDateStr($rawFrom) && isValidDateStr($rawTo)) {
            if ($rawFrom <= $rawTo) {
                $startDate = $rawFrom;
                $endDate = $rawTo;
            } else {
                $startDate = $rawTo;
                $endDate = $rawFrom;
            }
        } elseif (isValidDateStr($rawFrom)) {
            $startDate = $rawFrom;
            $endDate = date('Y-m-t', strtotime($rawFrom));
        } else {
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
        }
        $daysDiff = (int)ceil((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        $periodLabel = 'Custom: ' . date('d M Y', strtotime($startDate)) . ' – ' . date('d M Y', strtotime($endDate)) . ' (' . $daysDiff . ' days)';
        break;
    case 'all':
    default:
        $range = 'all';
        $startDate = null;
        $endDate = null;
        $periodLabel = 'All Time (Complete History)';
        break;
}

// Fetch Centralized Financial Metrics from Ledger Engine
$metrics = get_financial_summary($startDate, $endDate);

// ----------------------------------------------------
// 2. Alert Center ("Needs Attention") Data
// ----------------------------------------------------
// Overdue Invoices
$overdueInvoices = $pdo->query("
    SELECT i.*, c.name AS client_name
    FROM invoices i
    JOIN customers c ON c.id = i.customer_id
    WHERE i.status != 'paid' AND i.status != 'cancelled'
      AND ((i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR i.status = 'overdue')
    ORDER BY i.due_date ASC
    LIMIT 5
")->fetchAll();

// Pending Salaries
$pendingSalaries = $pdo->query("
    SELECT emp.full_name, emp.base_salary, emp.employee_code
    FROM employees emp
    LEFT JOIN payroll p ON p.employee_id = emp.id AND p.payroll_month = MONTH(CURDATE()) AND p.payroll_year = YEAR(CURDATE())
    WHERE emp.status = 'active' AND (p.id IS NULL OR p.status != 'Paid')
    LIMIT 5
")->fetchAll();

// Salary Advances Pending Deduction
$pendingAdvances = $pdo->query("
    SELECT sa.*, emp.full_name
    FROM salary_advances sa
    JOIN employees emp ON emp.id = sa.employee_id
    WHERE sa.status = 'Paid'
    LIMIT 5
")->fetchAll();

// ----------------------------------------------------
// 3. Recent Transactions Feed (Period Filtered)
// ----------------------------------------------------
$txWhereP = "p.status = 'Completed'";
$txWhereI = "i.status != 'cancelled'";
$txWhereE = "ex.status = 'Paid'";
$txWherePr = "pr.status = 'Paid' AND COALESCE(NULLIF(pr.amount_paid, 0), pr.net_salary) > 0";
$txParams = [];

if ($startDate && $endDate) {
    $txWhereP .= " AND COALESCE(p.payment_date, DATE(p.created_at)) BETWEEN ? AND ?";
    $txWhereI .= " AND COALESCE(i.invoice_date, DATE(i.created_at)) BETWEEN ? AND ?";
    $txWhereE .= " AND COALESCE(ex.expense_date, DATE(ex.created_at)) BETWEEN ? AND ?";
    $txWherePr .= " AND STR_TO_DATE(CONCAT(pr.payroll_year, '-', LPAD(pr.payroll_month, 2, '00'), '-01'), '%Y-%m-%d') <= ? AND LAST_DAY(STR_TO_DATE(CONCAT(pr.payroll_year, '-', LPAD(pr.payroll_month, 2, '00'), '-01'), '%Y-%m-%d')) >= ?";
    $txParams = [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $endDate, $startDate];
}

$txSql = "
    (SELECT 'Client Payment' AS type, COALESCE(p.payment_date, DATE(p.created_at)) AS tx_date, c.name AS party, p.payment_no AS ref, p.amount, p.payment_method, 'Received' AS tx_status, p.id, 'payment' AS tag
     FROM payments p
     JOIN customers c ON c.id = p.customer_id
     WHERE $txWhereP)
    UNION ALL
    (SELECT 'Invoice Billed' AS type, COALESCE(i.invoice_date, DATE(i.created_at)) AS tx_date, c.name AS party, i.invoice_no AS ref, i.total_amount AS amount, i.payment_mode AS payment_method, i.status AS tx_status, i.id, 'invoice' AS tag
     FROM invoices i
     JOIN customers c ON c.id = i.customer_id
     WHERE $txWhereI)
    UNION ALL
    (SELECT 'Expense' AS type, COALESCE(ex.expense_date, DATE(ex.created_at)) AS tx_date, ex.category AS party, COALESCE(ex.vendor, ex.description) AS ref, ex.amount, ex.payment_mode AS payment_method, ex.status AS tx_status, ex.id, 'expense' AS tag
     FROM expenses ex
     WHERE $txWhereE)
    UNION ALL
    (SELECT 'Team Payroll' AS type, COALESCE(pr.payment_date, STR_TO_DATE(CONCAT(pr.payroll_year, '-', LPAD(pr.payroll_month, 2, '00'), '-01'), '%Y-%m-%d')) AS tx_date, emp.full_name AS party, CONCAT('Salary ', pr.payroll_month, '/', pr.payroll_year) AS ref, COALESCE(NULLIF(pr.amount_paid, 0), pr.net_salary) AS amount, pr.payment_method, 'Paid' AS tx_status, pr.id, 'payroll' AS tag
     FROM payroll pr
     JOIN employees emp ON emp.id = pr.employee_id
     WHERE $txWherePr)
    ORDER BY tx_date DESC, id DESC
    LIMIT 10
";
$txStmt = $pdo->prepare($txSql);
$txStmt->execute($txParams);
$recentTransactions = $txStmt->fetchAll();

// ----------------------------------------------------
// 4. Client Payment Status Matrix (Period Filtered)
// ----------------------------------------------------
$clientWhereInv = "customer_id = c.id AND status != 'cancelled'";
$clientWherePay = "customer_id = c.id AND status = 'Completed'";
$clientWherePend = "customer_id = c.id AND status NOT IN ('paid', 'cancelled') AND amount_due > 0";
$csParams = [];

if ($startDate && $endDate) {
    $clientWhereInv .= " AND COALESCE(invoice_date, DATE(created_at)) BETWEEN :start1 AND :end1";
    $clientWherePay .= " AND COALESCE(payment_date, DATE(created_at)) BETWEEN :start2 AND :end2";
    $clientWherePend .= " AND COALESCE(invoice_date, DATE(created_at)) BETWEEN :start3 AND :end3";
    $csParams = [
        ':start1' => $startDate, ':end1' => $endDate,
        ':start2' => $startDate, ':end2' => $endDate,
        ':start3' => $startDate, ':end3' => $endDate
    ];
}

$clientSql = "
    SELECT c.id AS client_id, c.name AS client_name, c.client_logo, c.client_type, c.monthly_amount,
      (SELECT invoice_no FROM invoices WHERE customer_id = c.id ORDER BY id DESC LIMIT 1) AS current_invoice,
      (SELECT due_date FROM invoices WHERE customer_id = c.id ORDER BY id DESC LIMIT 1) AS due_date,
      (SELECT status FROM invoices WHERE customer_id = c.id ORDER BY id DESC LIMIT 1) AS inv_status,
      COALESCE((SELECT SUM(total_amount) FROM invoices WHERE $clientWhereInv), 0) AS total_billed,
      COALESCE((SELECT SUM(amount) FROM payments WHERE $clientWherePay), 0) AS total_received,
      COALESCE((SELECT SUM(amount_due) FROM invoices WHERE $clientWherePend), 0) AS period_pending
    FROM customers c
    WHERE c.status = 'active'
    ORDER BY (total_billed + total_received) DESC, total_billed DESC
    LIMIT 8
";
$csStmt = $pdo->prepare($clientSql);
$csStmt->execute($csParams);
$clientStatuses = $csStmt->fetchAll();

// ----------------------------------------------------
// 5. Chart Data (Dynamic Period Breakdown: Daily vs Monthly)
// ----------------------------------------------------
$chartLabels = [];
$chartRevenue = [];
$chartReceived = [];
$chartExpenses = [];
$chartTitle = "Income vs Expense Trend";

if ($startDate && $endDate) {
    $startDT = new DateTime($startDate);
    $endDT = new DateTime($endDate);
    $diffDays = (int)$endDT->diff($startDT)->days + 1;

    if ($diffDays <= 31) {
        $chartTitle = "Daily Performance Trend (" . date('d M', strtotime($startDate)) . " – " . date('d M Y', strtotime($endDate)) . ")";
        $period = new DatePeriod($startDT, new DateInterval('P1D'), (clone $endDT)->modify('+1 day'));

        $sRev = $pdo->prepare("SELECT DATE(COALESCE(invoice_date, DATE(created_at))) d, SUM(total_amount) tot FROM invoices WHERE status != 'cancelled' AND COALESCE(invoice_date, DATE(created_at)) BETWEEN ? AND ? GROUP BY d");
        $sRev->execute([$startDate, $endDate]);
        $revMap = $sRev->fetchAll(PDO::FETCH_KEY_PAIR);

        $sRec = $pdo->prepare("SELECT DATE(COALESCE(payment_date, DATE(created_at))) d, SUM(amount) tot FROM payments WHERE status = 'Completed' AND COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ? GROUP BY d");
        $sRec->execute([$startDate, $endDate]);
        $recMap = $sRec->fetchAll(PDO::FETCH_KEY_PAIR);

        $sExp = $pdo->prepare("SELECT DATE(COALESCE(expense_date, DATE(created_at))) d, SUM(amount) tot FROM expenses WHERE status = 'Paid' AND COALESCE(expense_date, DATE(created_at)) BETWEEN ? AND ? GROUP BY d");
        $sExp->execute([$startDate, $endDate]);
        $expMap = $sExp->fetchAll(PDO::FETCH_KEY_PAIR);

        $sPayr = $pdo->prepare("
            SELECT COALESCE(payment_date, STR_TO_DATE(CONCAT(payroll_year, '-', LPAD(payroll_month, 2, '00'), '-01'), '%Y-%m-%d')) d, SUM(COALESCE(NULLIF(amount_paid, 0), net_salary)) tot 
            FROM payroll 
            WHERE status = 'Paid' 
              AND STR_TO_DATE(CONCAT(payroll_year, '-', LPAD(payroll_month, 2, '00'), '-01'), '%Y-%m-%d') <= ? 
              AND LAST_DAY(STR_TO_DATE(CONCAT(payroll_year, '-', LPAD(payroll_month, 2, '00'), '-01'), '%Y-%m-%d')) >= ? 
            GROUP BY d
        ");
        $sPayr->execute([$endDate, $startDate]);
        $payrMap = $sPayr->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($period as $dt) {
            $d = $dt->format('Y-m-d');
            $chartLabels[] = $dt->format('d M');
            $chartRevenue[] = (float)($revMap[$d] ?? 0);
            $chartReceived[] = (float)($recMap[$d] ?? 0);
            $chartExpenses[] = (float)(($expMap[$d] ?? 0) + ($payrMap[$d] ?? 0));
        }
    } else {
        $chartTitle = "Monthly Performance Trend (" . date('M Y', strtotime($startDate)) . " – " . date('M Y', strtotime($endDate)) . ")";
        $cur = (clone $startDT)->modify('first day of this month');
        $last = (clone $endDT)->modify('first day of this month');

        while ($cur <= $last) {
            $mStart = $cur->format('Y-m-01');
            $mEnd = $cur->format('Y-m-t');
            $chartLabels[] = $cur->format('M Y');

            $sRev = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE status != 'cancelled' AND COALESCE(invoice_date, DATE(created_at)) BETWEEN ? AND ?");
            $sRev->execute([$mStart, $mEnd]);
            $chartRevenue[] = (float)($sRev->fetchColumn() ?: 0);

            $sRec = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE status = 'Completed' AND COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ?");
            $sRec->execute([$mStart, $mEnd]);
            $chartReceived[] = (float)($sRec->fetchColumn() ?: 0);

            $sExp = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE status = 'Paid' AND COALESCE(expense_date, DATE(created_at)) BETWEEN ? AND ?");
            $sExp->execute([$mStart, $mEnd]);
            $eTot = (float)($sExp->fetchColumn() ?: 0);

            $sPayr = $pdo->prepare("
                SELECT SUM(COALESCE(NULLIF(amount_paid, 0), net_salary)) 
                FROM payroll 
                WHERE status = 'Paid' 
                  AND STR_TO_DATE(CONCAT(payroll_year, '-', LPAD(payroll_month, 2, '00'), '-01'), '%Y-%m-%d') <= ? 
                  AND LAST_DAY(STR_TO_DATE(CONCAT(payroll_year, '-', LPAD(payroll_month, 2, '00'), '-01'), '%Y-%m-%d')) >= ?
            ");
            $sPayr->execute([$mEnd, $mStart]);
            $pTot = (float)($sPayr->fetchColumn() ?: 0);

            $chartExpenses[] = $eTot + $pTot;
            $cur->modify('+1 month');
        }
    }
} else {
    $chartTitle = "Income vs Outflow Annual Trend (" . date('Y') . ")";
    $curYear = date('Y');
    for ($m = 1; $m <= 12; $m++) {
        $mStart = sprintf('%04d-%02d-01', $curYear, $m);
        $mEnd = date('Y-m-t', strtotime($mStart));
        $chartLabels[] = date('M', mktime(0, 0, 0, $m, 1));

        $sRev = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE status != 'cancelled' AND COALESCE(invoice_date, DATE(created_at)) BETWEEN ? AND ?");
        $sRev->execute([$mStart, $mEnd]);
        $chartRevenue[] = (float)($sRev->fetchColumn() ?: 0);

        $sRec = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE status = 'Completed' AND COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ?");
        $sRec->execute([$mStart, $mEnd]);
        $chartReceived[] = (float)($sRec->fetchColumn() ?: 0);

        $sExp = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE status = 'Paid' AND COALESCE(expense_date, DATE(created_at)) BETWEEN ? AND ?");
        $sExp->execute([$mStart, $mEnd]);
        $eTot = (float)($sExp->fetchColumn() ?: 0);

        $sPayr = $pdo->prepare("
            SELECT SUM(COALESCE(NULLIF(amount_paid, 0), net_salary)) 
            FROM payroll 
            WHERE status = 'Paid' 
              AND payroll_month = ? 
              AND payroll_year = ?
        ");
        $sPayr->execute([$m, $curYear]);
        $pTot = (float)($sPayr->fetchColumn() ?: 0);

        $chartExpenses[] = $eTot + $pTot;
    }
}

// Dashboard Company Calendar Computation
$calYear = (int)date('Y');
$calMonth = (int)date('m');
$calDaysInMonth = (int)date('t');
$calHolidaysMap = get_active_holidays_map($pdo, $calYear);
$calOverridesMap = get_holiday_overrides_map($pdo, $calYear);

$calDays = [];
$calWorkingDays = 0;
$calSundays = 0;
$calHolidaysCount = 0;

for ($d = 1; $d <= $calDaysInMonth; $d++) {
    $cDateStr = "{$calYear}-" . str_pad($calMonth, 2, '0', STR_PAD_LEFT) . "-" . str_pad($d, 2, '0', STR_PAD_LEFT);
    $cCheck = is_date_holiday_or_sunday($cDateStr, $calHolidaysMap, $calOverridesMap);
    $cIsSunday = $cCheck['is_sunday'];
    $cIsNonWorking = $cCheck['is_non_working'];
    
    if ($cIsSunday) $calSundays++;
    if ($cIsNonWorking) {
        if (!$cIsSunday) $calHolidaysCount++;
    } else {
        $calWorkingDays++;
    }

    $calDays[$d] = [
        'dateStr'         => $cDateStr,
        'dayName'         => date('D', strtotime($cDateStr)),
        'dayNum'          => $d,
        'isToday'         => ($cDateStr === date('Y-m-d')),
        'isNonWorking'    => $cIsNonWorking,
        'isSunday'        => $cIsSunday,
        'isWorkingSunday' => !empty($cCheck['is_working_sunday']),
        'reason'          => $cCheck['reason'] ?: ($cIsSunday ? 'Sunday Weekly Off' : 'Working Day'),
        'holidayName'     => $cCheck['holiday_name'] ?? null
    ];
}
?>

<div class="container-fluid">
  <!-- Top Bar & Date Filter Controls -->
  <div class="d-flex flex-wrap justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
      <h3 class="fw-bold text-dark mb-1">Executive Business Control Center</h3>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <p class="text-muted small mb-0">Real-time consolidated analytics across revenue, client ledgers, expenses, and team payroll.</p>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-semibold small">
          <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($periodLabel) ?>
        </span>
      </div>
    </div>

    <!-- Date / Period Filter Controls -->
    <div class="d-flex flex-wrap align-items-center gap-2">
      <!-- Period Dropdown Selector -->
      <div class="dropdown">
        <button class="btn btn-white border shadow-sm btn-sm dropdown-toggle fw-semibold px-3 py-2 d-flex align-items-center gap-2 text-dark" type="button" id="periodFilterDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="border-radius: 8px;">
          <i class="bi bi-calendar-range text-primary"></i>
          <span><?= htmlspecialchars($range === 'custom' ? ('Custom: ' . date('d M Y', strtotime($startDate)) . ' → ' . date('d M Y', strtotime($endDate))) : (['today'=>'Today','yesterday'=>'Yesterday','this_week'=>'This Week','this_month'=>'This Month','last_month'=>'Last Month','last_3_months'=>'Last 3 Months','this_year'=>'This Year','all'=>'All Time'][$range] ?? 'Filter Period')) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" aria-labelledby="periodFilterDropdown" style="min-width: 230px; border-radius: 10px;">
          <li><h6 class="dropdown-header text-uppercase small fw-bold text-muted">Filter Presets</h6></li>
          <li><a class="dropdown-item py-2 d-flex align-items-center <?= $range==='this_month'?'active':'' ?>" href="index.php?range=this_month"><i class="bi bi-calendar-month me-2 text-muted"></i> This Month</a></li>
          <li><a class="dropdown-item py-2 d-flex align-items-center <?= $range==='today'?'active':'' ?>" href="index.php?range=today"><i class="bi bi-calendar-day me-2 text-muted"></i> Today</a></li>
          <li><a class="dropdown-item py-2 d-flex align-items-center <?= $range==='yesterday'?'active':'' ?>" href="index.php?range=yesterday"><i class="bi bi-calendar-minus me-2 text-muted"></i> Yesterday</a></li>
          <li><a class="dropdown-item py-2 d-flex align-items-center <?= $range==='this_week'?'active':'' ?>" href="index.php?range=this_week"><i class="bi bi-calendar-week me-2 text-muted"></i> This Week</a></li>
          <li><a class="dropdown-item py-2 d-flex align-items-center <?= $range==='last_month'?'active':'' ?>" href="index.php?range=last_month"><i class="bi bi-calendar-check me-2 text-muted"></i> Last Month</a></li>
          <li><a class="dropdown-item py-2 d-flex align-items-center <?= $range==='last_3_months'?'active':'' ?>" href="index.php?range=last_3_months"><i class="bi bi-calendar2-range me-2 text-muted"></i> Last 3 Months</a></li>
          <li><a class="dropdown-item py-2 d-flex align-items-center <?= $range==='this_year'?'active':'' ?>" href="index.php?range=this_year"><i class="bi bi-calendar-event me-2 text-muted"></i> This Year</a></li>
          <li><a class="dropdown-item py-2 d-flex align-items-center <?= $range==='all'?'active':'' ?>" href="index.php?range=all"><i class="bi bi-clock-history me-2 text-muted"></i> All Time</a></li>
          <li><hr class="dropdown-divider my-2"></li>
          <li>
            <a class="dropdown-item py-2 fw-semibold text-primary d-flex align-items-center <?= $range==='custom'?'active text-white':'' ?>" href="#" data-bs-toggle="modal" data-bs-target="#customDateRangeModal">
              <i class="bi bi-calendar-plus me-2"></i> Custom Date Range...
            </a>
          </li>
        </ul>
      </div>

      <!-- Send Alert Button -->
      <a href="<?= BASE_URL ?>modules/settings/send_notification.php" class="btn btn-outline-primary btn-sm fw-semibold shadow-sm px-3 py-2 d-flex align-items-center gap-2" style="border-radius: 8px;">
        <i class="bi bi-send text-primary"></i>
        <span>Send Alert</span>
      </a>

      <?php if ($range !== 'this_month'): ?>
        <a href="index.php" class="btn btn-outline-secondary btn-sm shadow-sm px-3 py-2 d-flex align-items-center gap-1" title="Reset filter to default This Month" style="border-radius: 8px;">
          <i class="bi bi-arrow-counterclockwise"></i>
          <span>Reset</span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Quick Actions & Operational Priority Bar -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; background: #ffffff;">
    <div class="card-body p-3">
      <!-- Section Header & Priority Status Badges -->
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
          <span class="badge bg-primary-subtle text-primary p-2 rounded-3">
            <i class="bi bi-lightning-charge-fill fs-6"></i>
          </span>
          <div>
            <h6 class="fw-bold text-dark mb-0" style="font-size: 0.9rem; letter-spacing: 0.02em;">QUICK ACTIONS</h6>
            <small class="text-muted" style="font-size: 0.75rem;">Fast shortcuts for daily operational workflows and system configurations</small>
          </div>
        </div>

        <!-- Priority Status Badges (Cleanly separated) -->
        <?php
          $overdueCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status NOT IN ('paid','cancelled') AND due_date < CURDATE()")->fetchColumn();
          $pendingSalCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='active' AND id NOT IN (SELECT employee_id FROM payroll WHERE payroll_month = MONTH(CURDATE()) AND payroll_year = YEAR(CURDATE()) AND status='Paid')")->fetchColumn();
          $pendingMonthlyCount = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE client_type='monthly' AND status='active' AND auto_invoice=1 AND id NOT IN (SELECT customer_id FROM billing_cycles WHERE billing_month=MONTH(CURDATE()) AND billing_year=YEAR(CURDATE()))")->fetchColumn();
        ?>
        <div class="d-flex flex-wrap gap-2">
          <?php if (!$isSmtpConfigured): ?>
            <a href="#" data-bs-toggle="modal" data-bs-target="#emailConfigModal" class="badge bg-warning-subtle text-dark border border-warning text-decoration-none py-2 px-2 d-inline-flex align-items-center gap-1 shadow-sm" title="Click to configure SMTP email settings">
              <i class="bi bi-exclamation-triangle-fill text-warning"></i><span>Setup SMTP Email</span>
            </a>
          <?php endif; ?>
          <?php if ($overdueCount > 0): ?>
            <a href="<?= BASE_URL ?>modules/reports/outstanding.php" class="badge bg-danger-subtle text-danger border border-danger-subtle text-decoration-none py-2 px-2 d-inline-flex align-items-center gap-1 shadow-sm">
              <i class="bi bi-exclamation-triangle-fill"></i><span><?= $overdueCount ?> Overdue Invoice<?= $overdueCount>1?'s':'' ?></span>
            </a>
          <?php endif; ?>
          <?php if ($pendingSalCount > 0): ?>
            <a href="<?= BASE_URL ?>modules/team/payroll.php" class="badge bg-warning-subtle text-dark border border-warning-subtle text-decoration-none py-2 px-2 d-inline-flex align-items-center gap-1 shadow-sm">
              <i class="bi bi-people-fill text-warning"></i><span><?= $pendingSalCount ?> Salary Pending</span>
            </a>
          <?php endif; ?>
          <?php if ($pendingMonthlyCount > 0): ?>
            <a href="<?= BASE_URL ?>modules/customers/monthly_clients.php" class="badge bg-info-subtle text-info border border-info-subtle text-decoration-none py-2 px-2 d-inline-flex align-items-center gap-1 shadow-sm">
              <i class="bi bi-arrow-repeat"></i><span><?= $pendingMonthlyCount ?> Invoice<?= $pendingMonthlyCount>1?'s':'' ?> to Generate</span>
            </a>
          <?php endif; ?>
          <?php if ($overdueCount === 0 && $pendingSalCount === 0 && $pendingMonthlyCount === 0 && $isSmtpConfigured): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-2 d-inline-flex align-items-center gap-1"><i class="bi bi-check-circle-fill"></i>All operational tasks up to date</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- 8 TOP FINANCIAL METRICS CARDS -->
  <div class="row g-3 mb-4">
    <!-- Total Revenue -->
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card stat-card p-3 border-0 shadow-sm" style="border-left: 4px solid #0d6efd !important;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="text-muted small fw-semibold uppercase" style="font-size: 0.75rem;">TOTAL REVENUE</span>
            <h4 class="fw-bold text-dark mb-0 mt-1">₹<?= money($metrics['total_revenue']) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Invoiced in selected period</small>
          </div>
          <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 46px; height: 46px;">
            <i class="bi bi-graph-up-arrow fs-5"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Total Received -->
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card stat-card p-3 border-0 shadow-sm" style="border-left: 4px solid #198754 !important;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="text-muted small fw-semibold uppercase" style="font-size: 0.75rem;">TOTAL RECEIVED</span>
            <h4 class="fw-bold text-success mb-0 mt-1">₹<?= money($metrics['total_received']) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Collected in selected period</small>
          </div>
          <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 46px; height: 46px;">
            <i class="bi bi-cash-stack fs-5"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Outstanding Pending -->
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card stat-card p-3 border-0 shadow-sm" style="border-left: 4px solid #dc3545 !important;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="text-muted small fw-semibold uppercase" style="font-size: 0.75rem;">CLIENT PENDING</span>
            <h4 class="fw-bold text-danger mb-0 mt-1">₹<?= money($metrics['total_pending']) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Due on selected period invoices</small>
          </div>
          <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 46px; height: 46px;">
            <i class="bi bi-exclamation-triangle fs-5"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Total Expenses -->
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card stat-card p-3 border-0 shadow-sm" style="border-left: 4px solid #fd7e14 !important;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="text-muted small fw-semibold uppercase" style="font-size: 0.75rem;">TOTAL EXPENSES</span>
            <h4 class="fw-bold text-warning mb-0 mt-1">₹<?= money($metrics['total_expenses']) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Operating expenses in period</small>
          </div>
          <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 46px; height: 46px;">
            <i class="bi bi-wallet2 fs-5"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Team Payroll -->
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card stat-card p-3 border-0 shadow-sm" style="border-left: 4px solid #6f42c1 !important;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="text-muted small fw-semibold uppercase" style="font-size: 0.75rem;">TEAM PAYROLL</span>
            <h4 class="fw-bold text-dark mb-0 mt-1">₹<?= money($metrics['team_payroll']) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Disbursed in selected period</small>
          </div>
          <div class="rounded-circle bg-purple-subtle text-purple d-flex align-items-center justify-content-center" style="width: 46px; height: 46px; background: #f3ebff; color: #6f42c1;">
            <i class="bi bi-people fs-5"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Total Outflow -->
    <?php $totalOutflow = (float)$metrics['total_expenses'] + (float)$metrics['team_payroll']; ?>
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card stat-card p-3 border-0 shadow-sm" style="border-left: 4px solid #d63384 !important;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="text-muted small fw-semibold uppercase" style="font-size: 0.75rem;">TOTAL OUTFLOW</span>
            <h4 class="fw-bold text-dark mb-0 mt-1">₹<?= money($totalOutflow) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Expenses + Team Payroll</small>
          </div>
          <div class="rounded-circle bg-pink-subtle d-flex align-items-center justify-content-center" style="width: 46px; height: 46px; background: #fdf2f8; color: #d63384;">
            <i class="bi bi-arrow-down-circle fs-5"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Net Profit -->
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card stat-card p-3 border-0 shadow-sm" style="border-left: 4px solid #20c997 !important;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="text-muted small fw-semibold uppercase" style="font-size: 0.75rem;">NET PROFIT</span>
            <h4 class="fw-bold text-dark mb-0 mt-1">₹<?= money($metrics['net_profit']) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Total Received − Total Outflow</small>
          </div>
          <div class="rounded-circle bg-info-subtle text-info d-flex align-items-center justify-content-center" style="width: 46px; height: 46px;">
            <i class="bi bi-piggy-bank fs-5"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Profit Margin -->
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card stat-card p-3 border-0 shadow-sm" style="border-left: 4px solid #0dcaf0 !important;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="text-muted small fw-semibold uppercase" style="font-size: 0.75rem;">PROFIT MARGIN</span>
            <h4 class="fw-bold text-dark mb-0 mt-1"><?= $metrics['profit_margin'] ?>%</h4>
            <small class="text-muted" style="font-size: 0.7rem;">Net Profit / Total Received</small>
          </div>
          <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 46px; height: 46px;">
            <i class="bi bi-percent fs-5 text-primary"></i>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- NEEDS ATTENTION / ALERT SECTION -->
  <?php if ($overdueInvoices || $pendingSalaries || $pendingAdvances): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; background: #fff5f5; border-left: 5px solid #dc3545 !important;">
      <div class="card-body p-4">
        <h6 class="fw-bold text-danger mb-3"><i class="bi bi-bell-fill me-2"></i>Needs Attention & Priority Alerts</h6>
        <div class="row g-3">
          <?php foreach ($overdueInvoices as $inv): 
            $due = (float)$inv['amount_due'];
          ?>
            <div class="col-md-6 col-lg-4">
              <div class="p-3 bg-white rounded-3 shadow-sm border border-danger-subtle h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="badge bg-danger">OVERDUE INVOICE</span>
                  <small class="text-muted fw-bold"><?= e($inv['invoice_no']) ?></small>
                </div>
                <h6 class="fw-bold text-dark mb-1"><?= e($inv['client_name']) ?></h6>
                <div class="d-flex justify-content-between text-muted small mt-2">
                  <span>Total: ₹<?= money($inv['total_amount']) ?></span>
                  <span class="text-danger fw-bold">Pending: ₹<?= money($due) ?></span>
                </div>
                <div class="mt-2 pt-2 border-top d-flex justify-content-between align-items-center">
                  <small class="text-muted">Due Date: <?= e($inv['due_date']) ?></small>
                  <a href="modules/invoices/record_payment.php?customer_id=<?= $inv['customer_id'] ?>&invoice_id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-danger">Record Payment</a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

          <?php foreach ($pendingSalaries as $ps): ?>
            <div class="col-md-6 col-lg-4">
              <div class="p-3 bg-white rounded-3 shadow-sm border border-warning-subtle h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="badge bg-warning text-dark">PENDING SALARY</span>
                  <small class="text-muted font-monospace"><?= e($ps['employee_code']) ?></small>
                </div>
                <h6 class="fw-bold text-dark mb-1"><?= e($ps['full_name']) ?></h6>
                <p class="text-muted small mb-2">Base Salary: <strong>₹<?= money($ps['base_salary']) ?></strong></p>
                <div class="mt-2 pt-2 border-top text-end">
                  <a href="modules/team/payroll.php" class="btn btn-sm btn-warning">Process Payroll</a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- RECENT TRANSACTIONS & CLIENT PAYMENT MATRIX -->
  <div class="row g-4 mb-4">
    <!-- Recent Transactions Table -->
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm h-100" style="border-radius: 14px;">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <div>
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Recent Transactions Feed</h6>
            <small class="text-muted" style="font-size: 0.75rem;">Showing transactions for: <strong><?= htmlspecialchars($periodLabel) ?></strong></small>
          </div>
          <a href="modules/invoices/list.php" class="btn btn-sm btn-light border">View All</a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="bg-light text-muted small uppercase">
                <tr>
                  <th class="ps-3">Date</th>
                  <th>Type</th>
                  <th>Party / Reference</th>
                  <th class="text-end pe-3">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($recentTransactions)): ?>
                  <?php foreach ($recentTransactions as $tx): ?>
                    <tr>
                      <td class="ps-3 small fw-medium"><?= e($tx['tx_date']) ?></td>
                      <td>
                        <span class="badge bg-<?= 
                          $tx['tag']=='payment'?'success':
                          ($tx['tag']=='invoice'?'primary':
                          ($tx['tag']=='payroll'?'secondary':'warning'))
                        ?>">
                          <?= e($tx['type']) ?>
                        </span>
                      </td>
                      <td>
                        <span class="fw-bold text-dark d-block"><?= e($tx['party']) ?></span>
                        <small class="text-muted"><?= e($tx['ref']) ?></small>
                      </td>
                      <td class="text-end pe-3 fw-bold text-<?= $tx['tag']=='payment'?'success':($tx['tag']=='invoice'?'primary':'dark') ?>">
                        ₹<?= money($tx['amount']) ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="4" class="text-center py-4 text-muted small">
                      <i class="bi bi-clock-history fs-4 d-block mb-1 text-muted"></i>No transactions recorded for the selected period.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Client Payment Status Matrix -->
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100" style="border-radius: 14px;">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <div>
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-people me-2 text-primary"></i>Client Payment Matrix</h6>
            <small class="text-muted" style="font-size: 0.75rem;">Period activity & balances</small>
          </div>
          <a href="modules/customers/list.php" class="btn btn-sm btn-light border">All Clients</a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="bg-light text-muted small uppercase">
                <tr>
                  <th class="ps-3">Client</th>
                  <th class="text-end">Billed</th>
                  <th class="text-end">Paid</th>
                  <th class="text-end pe-3">Pending</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($clientStatuses)): ?>
                  <?php foreach ($clientStatuses as $cs): 
                    $billed = (float)$cs['total_billed'];
                    $received = (float)$cs['total_received'];
                    $pend = (float)$cs['period_pending'];
                  ?>
                    <tr>
                      <td class="ps-3">
                        <div class="d-flex align-items-center">
                          <?= get_client_logo_html(['name' => $cs['client_name'], 'client_logo' => $cs['client_logo']], 32) ?>
                          <div>
                            <a href="modules/customers/view.php?id=<?= $cs['client_id'] ?>" class="fw-bold text-dark text-decoration-none d-block"><?= e($cs['client_name']) ?></a>
                            <small class="text-muted"><?= ucfirst(e($cs['client_type'])) ?></small>
                          </div>
                        </div>
                      </td>

                      <td class="text-end small">₹<?= money($billed) ?></td>
                      <td class="text-end small text-success fw-semibold">₹<?= money($received) ?></td>
                      <td class="text-end pe-3 fw-bold text-<?= $pend>0?'danger':'muted' ?>">
                        ₹<?= money($pend) ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="4" class="text-center py-4 text-muted small">
                      <i class="bi bi-person-x fs-4 d-block mb-1 text-muted"></i>No client billing or payments recorded for this period.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- FINANCIAL TREND CHARTS -->
  <div class="row g-4">
    <div class="col-12">
      <div class="card border-0 shadow-sm p-4" style="border-radius: 14px;">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <h6 class="fw-bold text-dark mb-0"><i class="bi bi-bar-chart-line me-2 text-primary"></i><?= htmlspecialchars($chartTitle) ?></h6>
          <span class="badge bg-light text-muted border small fw-normal">Dynamic Period Graph</span>
        </div>
        <div style="position: relative; height: 320px;">
          <canvas id="dashboardIncomeTrend"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- COMPANY CALENDAR & HOLIDAY SCHEDULE WIDGET -->
  <div class="row g-4 mt-1">
    <div class="col-12">
      <div class="card border-0 shadow-sm p-4" style="border-radius: 14px;">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
          <div>
            <h6 class="fw-bold text-dark mb-1">
              <i class="bi bi-calendar-event me-2 text-primary"></i>Company Calendar & Holiday Schedule — <?= date('F Y') ?>
            </h6>
            <small class="text-muted">Sundays are default weekly off holidays. Schedule or unschedule holidays & working Sundays for all team members.</small>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3 rounded-pill fw-semibold small">
              <i class="bi bi-briefcase me-1"></i><?= $calWorkingDays ?> Working Days
            </span>
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle py-2 px-3 rounded-pill fw-semibold small" style="background:#f3e8ff; color:#7e22ce;">
              <i class="bi bi-sun me-1"></i><?= $calSundays ?> Sundays
            </span>
            <?php if ($calHolidaysCount > 0): ?>
              <span class="badge bg-warning-subtle text-dark border border-warning-subtle py-2 px-3 rounded-pill fw-semibold small">
                <i class="bi bi-star-fill text-warning me-1"></i><?= $calHolidaysCount ?> Holidays
              </span>
            <?php endif; ?>
            <a href="modules/team/holidays.php" class="btn btn-primary btn-sm fw-semibold shadow-sm px-3 ms-md-2" style="border-radius:8px;">
              <i class="bi bi-sliders me-1"></i>Manage Holidays & Sundays
            </a>
          </div>
        </div>

        <!-- Month Day Cells Strip -->
        <div class="d-flex flex-nowrap overflow-auto gap-2 py-2 mb-2" style="scrollbar-width: thin;">
          <?php foreach ($calDays as $dayNum => $dInfo): 
            $isSun = $dInfo['isSunday'];
            $isOff = $dInfo['isNonWorking'];
            $isWkSun = $dInfo['isWorkingSunday'];
            $isToday = $dInfo['isToday'];

            if ($isOff) {
                $cellBg = $isSun ? '#fee2e2' : '#fef3c7';
                $cellText = $isSun ? '#991b1b' : '#b45309';
                $label = 'H';
            } else {
                $cellBg = '#f1f5f9';
                $cellText = '#475569';
                $label = '·';
            }
          ?>
            <div class="text-center flex-shrink-0" style="width: 44px;">
              <div class="text-muted fw-semibold" style="font-size: 0.65rem; margin-bottom: 3px;"><?= $dInfo['dayName'] ?></div>
              <div class="d-flex align-items-center justify-content-center fw-bold shadow-sm" 
                   style="height: 38px; border-radius: 8px; font-size: 0.8rem; background: <?= $cellBg ?>; color: <?= $cellText ?>; <?= $isToday ? 'border: 2px solid #0d6efd; box-shadow: 0 0 8px rgba(13,110,253,0.3) !important;' : ($isOff ? 'border: 1px solid #fecaca;' : '') ?>"
                   title="<?= htmlspecialchars($dInfo['dateStr'] . ': ' . $dInfo['reason']) ?>">
                <?= $label ?>
              </div>
              <div class="fw-semibold mt-1 <?= $isToday ? 'text-primary fw-bold' : 'text-muted' ?>" style="font-size: 0.7rem;">
                <?= $dayNum ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Calendar Legend & Quick Switch Note -->
        <div class="d-flex flex-wrap justify-content-between align-items-center pt-2 border-top gap-2">
          <div class="d-flex flex-wrap align-items-center gap-3" style="font-size: 0.78rem;">
            <span class="d-flex align-items-center gap-1">
              <span class="d-inline-block rounded-1" style="width: 14px; height: 14px; background: #fee2e2; border: 1px solid #fecaca;"></span>
              <span class="text-muted"><strong>H</strong> = Sunday Weekly Off (Holiday)</span>
            </span>
            <span class="d-flex align-items-center gap-1">
              <span class="d-inline-block rounded-1" style="width: 14px; height: 14px; background: #fef3c7; border: 1px solid #fed7aa;"></span>
              <span class="text-muted"><strong>H</strong> = Festival / National Holiday</span>
            </span>
            <span class="d-flex align-items-center gap-1">
              <span class="d-inline-block rounded-1" style="width: 14px; height: 14px; background: #f1f5f9; border: 1px solid #e2e8f0;"></span>
              <span class="text-muted"><strong>·</strong> = Normal Working Day</span>
            </span>
          </div>
          <div>
            <a href="modules/team/attendance_report.php" class="text-decoration-none small fw-semibold text-primary">
              View Detailed Attendance Calendar <i class="bi bi-arrow-right ms-1"></i>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>


<!-- Quick Email (SMTP) Configuration Modal -->
<div class="modal fade" id="emailConfigModal" tabindex="-1" aria-labelledby="emailConfigModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light py-3 border-bottom">
        <div class="d-flex align-items-center gap-2">
          <div class="p-2 bg-primary bg-opacity-10 text-primary rounded-circle">
            <i class="bi bi-envelope-at fs-5"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold text-dark mb-0" id="emailConfigModalLabel">Email (SMTP) Configuration</h5>
            <span class="text-muted small">Configure mail server credentials to enable automated attendance alerts, reminders, and custom emails.</span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      
      <form method="POST" action="">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="save_dashboard_smtp" value="1">
        
        <div class="modal-body p-4">
          <!-- Status Banner -->
          <div class="d-flex align-items-center justify-content-between p-3 mb-4 rounded-3 border <?= $isSmtpConfigured ? 'bg-success-subtle border-success-subtle text-success-emphasis' : 'bg-warning-subtle border-warning-subtle text-dark' ?>">
            <div class="d-flex align-items-center gap-2">
              <i class="bi <?= $isSmtpConfigured ? 'bi-check-circle-fill fs-5 text-success' : 'bi-exclamation-triangle-fill fs-5 text-warning' ?>"></i>
              <div>
                <strong>SMTP Status: <?= $isSmtpConfigured ? 'Configured & Active' : 'Not Configured Yet' ?></strong>
                <div class="small text-muted">
                  <?= $isSmtpConfigured ? 'Outbound emails will be delivered via ' . htmlspecialchars($smtpSettings['smtp_host']) : 'Emails will fail or fallback to local mail() until SMTP credentials are provided.' ?>
                </div>
              </div>
            </div>
            <?php if ($isSmtpConfigured): ?>
              <span class="badge bg-success">Ready</span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Action Needed</span>
            <?php endif; ?>
          </div>

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label small fw-semibold">SMTP Host / Server <span class="text-danger">*</span></label>
              <input type="text" name="smtp_host" class="form-control" placeholder="e.g. smtp.gmail.com or mail.vibesoft.in" value="<?= htmlspecialchars($smtpSettings['smtp_host'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Port <span class="text-danger">*</span></label>
              <input type="text" name="smtp_port" class="form-control" placeholder="587" value="<?= htmlspecialchars($smtpSettings['smtp_port'] ?? '587') ?>" required>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">SMTP Username / Email <span class="text-danger">*</span></label>
              <input type="text" name="smtp_user" class="form-control" placeholder="user@domain.com" value="<?= htmlspecialchars($smtpSettings['smtp_user'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">SMTP Password / App Password <span class="text-danger">*</span></label>
              <input type="password" name="smtp_pass" class="form-control" placeholder="••••••••••••" value="<?= htmlspecialchars($smtpSettings['smtp_pass'] ?? '') ?>" required>
            </div>

            <div class="col-md-4">
              <label class="form-label small fw-semibold">Encryption</label>
              <select name="smtp_secure" class="form-select">
                <option value="tls" <?= ($smtpSettings['smtp_secure'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS (Port 587 - Recommended)</option>
                <option value="ssl" <?= ($smtpSettings['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                <option value="none" <?= ($smtpSettings['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">From Email Address</label>
              <input type="email" name="smtp_from_email" class="form-control" placeholder="no-reply@domain.com" value="<?= htmlspecialchars($smtpSettings['smtp_from_email'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">From Display Name</label>
              <input type="text" name="smtp_from_name" class="form-control" value="<?= htmlspecialchars($smtpSettings['smtp_from_name'] ?? 'VibeSoft CMS') ?>">
            </div>
          </div>

          <div class="alert alert-light border mt-4 mb-0 py-2 px-3 small text-muted">
            <i class="bi bi-lightbulb text-warning me-1"></i> <strong>Tip for Gmail users:</strong> Enable 2-Step Verification in Google Account, then generate a 16-character <em>App Password</em> (under Security &gt; App Passwords) and paste it here.
          </div>
        </div>

        <div class="modal-footer bg-light border-top py-3 d-flex justify-content-between">
          <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>modules/settings/notifications.php" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-gear me-1"></i> Full Notification Settings
            </a>
            <a href="<?= BASE_URL ?>modules/settings/send_notification.php" class="btn btn-outline-success btn-sm">
              <i class="bi bi-send me-1"></i> Send Custom Email
            </a>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-3 shadow-sm">
              <i class="bi bi-check2-circle me-1"></i> Save Configuration
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Custom Date Range Modal -->
<div class="modal fade" id="customDateRangeModal" tabindex="-1" aria-labelledby="customDateRangeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius: 14px;">
      <form method="get" action="index.php" id="customDateModalForm">
        <input type="hidden" name="range" value="custom">
        <div class="modal-header border-bottom pb-3">
          <h5 class="modal-title fw-bold text-dark" id="customDateRangeModalLabel">
            <i class="bi bi-calendar-range text-primary me-2"></i>Select Custom Date Range
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <p class="text-muted small mb-3">Filter all dashboard KPIs, financial ledgers, transactions, and client statistics for a specific date window.</p>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-bold text-dark">Start Date</label>
              <div class="input-group shadow-sm">
                <span class="input-group-text bg-light"><i class="bi bi-calendar-event"></i></span>
                <input type="date" name="from" id="modalFromDate" class="form-control" value="<?= htmlspecialchars($startDate ?? date('Y-m-01')) ?>" required>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-bold text-dark">End Date</label>
              <div class="input-group shadow-sm">
                <span class="input-group-text bg-light"><i class="bi bi-calendar-event"></i></span>
                <input type="date" name="to" id="modalToDate" class="form-control" value="<?= htmlspecialchars($endDate ?? date('Y-m-t')) ?>" required>
              </div>
            </div>
          </div>
          
          <!-- Quick Preset Buttons inside Modal -->
          <div class="mt-4 pt-3 border-top">
            <label class="form-label small text-muted mb-2 d-block fw-semibold">Quick Set Presets:</label>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-sm btn-light border shadow-sm" onclick="setModalDates('<?= date('Y-m-d', strtotime('-7 days')) ?>', '<?= date('Y-m-d') ?>')">Past 7 Days</button>
              <button type="button" class="btn btn-sm btn-light border shadow-sm" onclick="setModalDates('<?= date('Y-m-d', strtotime('-30 days')) ?>', '<?= date('Y-m-d') ?>')">Past 30 Days</button>
              <button type="button" class="btn btn-sm btn-light border shadow-sm" onclick="setModalDates('<?= date('Y-m-01') ?>', '<?= date('Y-m-d') ?>')">Month to Date</button>
              <button type="button" class="btn btn-sm btn-light border shadow-sm" onclick="setModalDates('<?= date('Y-01-01') ?>', '<?= date('Y-m-d') ?>')">Year to Date</button>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top bg-light py-3 d-flex justify-content-between" style="border-bottom-left-radius: 14px; border-bottom-right-radius: 14px;">
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
            <a href="index.php" class="btn btn-outline-dark btn-sm px-3">Clear / Reset</a>
          </div>
          <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold shadow-sm">
            <i class="bi bi-funnel me-1"></i>Apply Filter
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels = <?= json_encode($chartLabels) ?>;
const revData = <?= json_encode($chartRevenue) ?>;
const recData = <?= json_encode($chartReceived) ?>;
const expData = <?= json_encode($chartExpenses) ?>;

const ctx = document.getElementById('dashboardIncomeTrend').getContext('2d');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: labels,
    datasets: [
      { label: 'Billed Revenue (₹)', data: revData, backgroundColor: '#0d6efd', borderRadius: 6 },
      { label: 'Received Payments (₹)', data: recData, backgroundColor: '#198754', borderRadius: 6 },
      { label: 'Total Outflow / Expenses (₹)', data: expData, backgroundColor: '#dc3545', borderRadius: 6 }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'top' },
      tooltip: {
        callbacks: {
          label: function(context) {
            return context.dataset.label + ': ₹' + Number(context.raw).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
          }
        }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: {
          callback: function(value) {
            return '₹' + Number(value).toLocaleString('en-IN');
          }
        }
      }
    }
  }
});

function setModalDates(from, to) {
  const f = document.getElementById('modalFromDate');
  const t = document.getElementById('modalToDate');
  if (f) f.value = from;
  if (t) t.value = to;
}
</script>

<?php include 'includes/footer.php'; ?>
