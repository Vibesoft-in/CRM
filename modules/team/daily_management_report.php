<?php
// modules/team/daily_management_report.php — Attendance vs Work Reports Management, Cross-Check & Review Portal
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/layout.php';
require_login();

// ----------------------------------------------------
// 1. FILTER PARAMETERS & MODES
// ----------------------------------------------------
$filterMode   = $_GET['filter_mode'] ?? 'day'; // 'day', 'month', 'range'
$viewDate     = $_GET['date'] ?? date('Y-m-d');
$selectedMonth = intval($_GET['month'] ?? date('n'));
$selectedYear  = intval($_GET['year'] ?? date('Y'));
$fromDate     = $_GET['from_date'] ?? date('Y-m-01');
$toDate       = $_GET['to_date'] ?? date('Y-m-d');
$empFilterId  = intval($_GET['employee_id'] ?? 0);
$deptFilter   = trim($_GET['department'] ?? '');
$statusFilter = trim($_GET['status_filter'] ?? 'all');
$csrf         = csrf_token();

// Ensure month is 1-12 and year is valid
if ($selectedMonth < 1 || $selectedMonth > 12) $selectedMonth = intval(date('n'));
if ($selectedYear < 2020 || $selectedYear > 2035) $selectedYear = intval(date('Y'));

// Auto-process daily attendance rules if day mode
if ($filterMode === 'day') {
    auto_process_daily_attendance($pdo, $viewDate);
}

// ----------------------------------------------------
// 2. HANDLE REVIEW ACTION (Approve / Reject / Revision)
// ----------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'review_report') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $repId       = intval($_POST['report_id'] ?? 0);
        $empIdPost   = intval($_POST['employee_id'] ?? 0);
        $repDatePost = trim($_POST['report_date'] ?? '');
        $status      = $_POST['review_status'] ?? 'approved';
        $comment     = trim($_POST['review_comment'] ?? '');
        $rating      = intval($_POST['rating'] ?? 0);

        if ($status === 'approved') {
            if ($rating <= 0) $rating = 5;
        } elseif ($status === 'revision_requested') {
            $rating = 0;
            if (empty($comment)) {
                $comment = 'Check your report again & submit again. Please ensure all tasks, hours, and blocker details are filled accurately.';
            }
        } elseif ($status === 'rejected') {
            if ($rating <= 0) $rating = 1;
            if (empty($comment)) {
                $comment = 'Work report rejected by management.';
            }
        }

        // 1. Update daily_work_reports
        if ($repId > 0) {
            $stmtDwr = $pdo->prepare("
                UPDATE daily_work_reports 
                SET status = ?, rating = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() 
                WHERE id = ?
            ");
            $stmtDwr->execute([$status, $rating, $comment, $_SESSION['user_id'], $repId]);
        } elseif ($empIdPost > 0 && !empty($repDatePost)) {
            $stmtDwr = $pdo->prepare("
                UPDATE daily_work_reports 
                SET status = ?, rating = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() 
                WHERE employee_id = ? AND report_date = ?
            ");
            $stmtDwr->execute([$status, $rating, $comment, $_SESSION['user_id'], $empIdPost, $repDatePost]);
        }

        // 2. Sync to work_reports table
        if ($empIdPost > 0 && !empty($repDatePost)) {
            $stmtWr = $pdo->prepare("
                UPDATE work_reports 
                SET status = ?, review_comment = ?, reviewed_by = ?, reviewed_at = NOW() 
                WHERE employee_id = ? AND report_date = ?
            ");
            $stmtWr->execute([$status, $comment, $_SESSION['user_id'], $empIdPost, $repDatePost]);
        } elseif ($repId > 0) {
            // Find employee_id & report_date from daily_work_reports if not supplied
            $dwrInfo = $pdo->prepare("SELECT employee_id, report_date FROM daily_work_reports WHERE id = ?");
            $dwrInfo->execute([$repId]);
            $dwrRow = $dwrInfo->fetch();
            if ($dwrRow) {
                $empIdPost   = $dwrRow['employee_id'];
                $repDatePost = $dwrRow['report_date'];
                $stmtWr = $pdo->prepare("
                    UPDATE work_reports 
                    SET status = ?, review_comment = ?, reviewed_by = ?, reviewed_at = NOW() 
                    WHERE employee_id = ? AND report_date = ?
                ");
                $stmtWr->execute([$status, $comment, $_SESSION['user_id'], $empIdPost, $repDatePost]);
            }
        }

        // 3. Send Notification to Employee
        if ($empIdPost > 0) {
            $empUserId = get_user_id_by_employee_id($pdo, $empIdPost);
            if ($empUserId) {
                $displayDateStr = !empty($repDatePost) ? date('d M Y', strtotime($repDatePost)) : 'Recent';
                if ($status === 'revision_requested') {
                    create_action_notification(
                        $pdo, 
                        (int)$empUserId, 
                        'Report Revision Requested', 
                        "Admin has requested a revision on your work report for {$displayDateStr}: \"{$comment}\". Please check your report again & submit again.", 
                        '/employee/work-reports.php?action=edit&date=' . urlencode($repDatePost), 
                        'report_revision'
                    );
                } elseif ($status === 'approved') {
                    create_action_notification(
                        $pdo, 
                        (int)$empUserId, 
                        'Work Report Approved', 
                        "Your work report for {$displayDateStr} has been approved by management.", 
                        '/employee/work-reports.php', 
                        'report_approved'
                    );
                } elseif ($status === 'rejected') {
                    create_action_notification(
                        $pdo, 
                        (int)$empUserId, 
                        'Work Report Rejected', 
                        "Your work report for {$displayDateStr} was rejected: \"{$comment}\".", 
                        '/employee/work-reports.php', 
                        'report_rejected'
                    );
                }
            }
        }

        $label = match($status) {
            'approved' => 'Approved',
            'revision_requested' => 'Revision Requested (Employee can now edit & resubmit)',
            'rejected' => 'Rejected',
            default => 'Reviewed'
        };
        set_flash("Work report review saved! Status set to {$label}.", "success");

        // Redirect preserving current query parameters
        $redirectQuery = $_GET;
        header("Location: daily_management_report.php?" . http_build_query($redirectQuery));
        exit;
    }
}

// ----------------------------------------------------
// 3. FETCH DEPARTMENTS & EMPLOYEES LIST FOR FILTERS
// ----------------------------------------------------
$departments = $pdo->query("
    SELECT DISTINCT department 
    FROM employees 
    WHERE department IS NOT NULL AND department != '' 
    ORDER BY department ASC
")->fetchAll(PDO::FETCH_COLUMN);

$allEmployees = $pdo->query("
    SELECT id, employee_code, full_name, department, designation 
    FROM employees 
    WHERE status = 'active' 
    ORDER BY full_name ASC
")->fetchAll();

// ----------------------------------------------------
// 4. DETERMINE DATE SCOPE BASED ON FILTER MODE
// ----------------------------------------------------
$scopeDates = [];
$isSingleDay = ($filterMode === 'day');

if ($filterMode === 'month') {
    $startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime($startDate));
    // If future month, don't exceed today
    if (strtotime($endDate) > strtotime(date('Y-m-d'))) {
        $endDate = date('Y-m-d');
    }
} elseif ($filterMode === 'range') {
    $startDate = $fromDate;
    $endDate   = $toDate;
    if (strtotime($startDate) > strtotime($endDate)) {
        $tmp = $startDate; $startDate = $endDate; $endDate = $tmp;
    }
    if (strtotime($endDate) > strtotime(date('Y-m-d'))) {
        $endDate = date('Y-m-d');
    }
} else {
    // Day mode
    $startDate = $viewDate;
    $endDate   = $viewDate;
}

// ----------------------------------------------------
// 5. QUERY DATA ACCORDING TO SCOPE & FILTERS
// ----------------------------------------------------

// Filter employees list if specified
$targetEmployees = $allEmployees;
if ($empFilterId > 0) {
    $targetEmployees = array_filter($targetEmployees, fn($e) => intval($e['id']) === $empFilterId);
}
if (!empty($deptFilter)) {
    $targetEmployees = array_filter($targetEmployees, fn($e) => ($e['department'] ?? '') === $deptFilter);
}

// Map employees by ID for fast lookup
$empMap = [];
foreach ($allEmployees as $e) {
    $empMap[$e['id']] = $e;
}

// Fetch Attendance records in range
$attParams = [$startDate, $endDate];
$attSql = "SELECT * FROM attendance WHERE attendance_date BETWEEN ? AND ?";
if ($empFilterId > 0) {
    $attSql .= " AND employee_id = ?";
    $attParams[] = $empFilterId;
}
$attStmt = $pdo->prepare($attSql);
$attStmt->execute($attParams);
$attRecords = $attStmt->fetchAll();

// Key attendance by "empId_date"
$attMap = [];
foreach ($attRecords as $a) {
    $key = $a['employee_id'] . '_' . $a['attendance_date'];
    $attMap[$key] = $a;
}

// Fetch Work Reports in range (with joined reviewer and work_reports data)
$repParams = [$startDate, $endDate];
$repSql = "
    SELECT 
        dwr.*,
        wr.status as wr_status,
        wr.review_comment as wr_review_comment,
        wr.reviewed_by as wr_reviewed_by,
        wr.reviewed_at as wr_reviewed_at,
        u.name as reviewer_name
    FROM daily_work_reports dwr
    LEFT JOIN work_reports wr ON wr.employee_id = dwr.employee_id AND wr.report_date = dwr.report_date
    LEFT JOIN users u ON u.id = COALESCE(dwr.reviewed_by, wr.reviewed_by)
    WHERE dwr.report_date BETWEEN ? AND ?
";
if ($empFilterId > 0) {
    $repSql .= " AND dwr.employee_id = ?";
    $repParams[] = $empFilterId;
}
$repSql .= " ORDER BY dwr.report_date DESC, dwr.id DESC";
$repStmt = $pdo->prepare($repSql);
$repStmt->execute($repParams);
$repRecords = $repStmt->fetchAll();

// Key reports by "empId_date"
$repMap = [];
foreach ($repRecords as $r) {
    $key = $r['employee_id'] . '_' . $r['report_date'];
    $repMap[$key] = $r;
}

// ----------------------------------------------------
// 6. BUILD CROSS-CHECK ROWS & COMPUTE METRICS
// ----------------------------------------------------
$crossRows = [];
$metrics = [
    'total_records'       => 0,
    'present_count'       => 0,
    'late_count'          => 0,
    'absent_count'        => 0,
    'leave_count'         => 0,
    'reports_submitted'   => 0,
    'reports_approved'    => 0,
    'reports_revision'    => 0,
    'reports_rejected'    => 0,
    'reports_missing'     => 0,
];
$missingReportsList = [];

if ($isSingleDay) {
    // Single Day Mode: Show all filtered employees for this date
    $curDate = $viewDate;
    foreach ($targetEmployees as $emp) {
        $key = $emp['id'] . '_' . $curDate;
        $att = $attMap[$key] ?? null;
        $rep = $repMap[$key] ?? null;

        $attStatus = $att['status'] ?? 'unmarked';
        if (in_array($attStatus, ['present', 'late'])) $metrics['present_count']++;
        if ($attStatus === 'late') $metrics['late_count']++;
        if ($attStatus === 'absent') $metrics['absent_count']++;
        if ($attStatus === 'on_leave') $metrics['leave_count']++;

        $repStatus = 'missing';
        if ($rep) {
            $metrics['reports_submitted']++;
            $rawSt = strtolower($rep['status'] ?? ($rep['wr_status'] ?? ''));
            $revNote = strtolower($rep['review_note'] ?? ($rep['wr_review_comment'] ?? ''));

            if ($rawSt === 'revision_requested' || str_contains($revNote, 'revision')) {
                $repStatus = 'revision_requested';
                $metrics['reports_revision']++;
            } elseif ($rawSt === 'rejected') {
                $repStatus = 'rejected';
                $metrics['reports_rejected']++;
            } elseif ($rawSt === 'approved' || (!empty($rep['rating']) && (int)$rep['rating'] >= 4)) {
                $repStatus = 'approved';
                $metrics['reports_approved']++;
            } else {
                $repStatus = 'submitted';
            }
        } else {
            if (in_array($attStatus, ['present', 'late'])) {
                $metrics['reports_missing']++;
                $missingReportsList[] = $emp['full_name'];
            }
        }

        // Apply Status Filter if not 'all'
        if ($statusFilter !== 'all') {
            if ($statusFilter === 'missing' && $repStatus !== 'missing') continue;
            if ($statusFilter === 'submitted' && $repStatus !== 'submitted') continue;
            if ($statusFilter === 'approved' && $repStatus !== 'approved') continue;
            if ($statusFilter === 'revision_requested' && $repStatus !== 'revision_requested') continue;
            if ($statusFilter === 'rejected' && $repStatus !== 'rejected') continue;
            if ($statusFilter === 'present' && !in_array($attStatus, ['present', 'late'])) continue;
            if ($statusFilter === 'late' && $attStatus !== 'late') continue;
            if ($statusFilter === 'absent' && $attStatus !== 'absent') continue;
        }

        $crossRows[] = [
            'date'      => $curDate,
            'emp'       => $emp,
            'att'       => $att,
            'attStatus' => $attStatus,
            'rep'       => $rep,
            'repStatus' => $repStatus
        ];
    }
} else {
    // Multi-Day / Month / Range Mode:
    // Gather all distinct employee-date combinations from attendance and work reports
    $combinedKeys = [];
    foreach ($attRecords as $a) {
        $combinedKeys[$a['employee_id'] . '_' . $a['attendance_date']] = [
            'emp_id' => $a['employee_id'],
            'date'   => $a['attendance_date']
        ];
    }
    foreach ($repRecords as $r) {
        $combinedKeys[$r['employee_id'] . '_' . $r['report_date']] = [
            'emp_id' => $r['employee_id'],
            'date'   => $r['report_date']
        ];
    }

    // Sort by date DESC, then emp_id
    uasort($combinedKeys, function($a, $b) {
        if ($a['date'] === $b['date']) return $a['emp_id'] <=> $b['emp_id'];
        return strcmp($b['date'], $a['date']);
    });

    foreach ($combinedKeys as $item) {
        $empId = $item['emp_id'];
        $d     = $item['date'];
        $emp   = $empMap[$empId] ?? null;
        if (!$emp) continue;

        // Apply employee & department filters
        if ($empFilterId > 0 && intval($emp['id']) !== $empFilterId) continue;
        if (!empty($deptFilter) && ($emp['department'] ?? '') !== $deptFilter) continue;

        $key = $empId . '_' . $d;
        $att = $attMap[$key] ?? null;
        $rep = $repMap[$key] ?? null;

        $attStatus = $att['status'] ?? 'unmarked';
        if (in_array($attStatus, ['present', 'late'])) $metrics['present_count']++;
        if ($attStatus === 'late') $metrics['late_count']++;
        if ($attStatus === 'absent') $metrics['absent_count']++;
        if ($attStatus === 'on_leave') $metrics['leave_count']++;

        $repStatus = 'missing';
        if ($rep) {
            $metrics['reports_submitted']++;
            $rawSt = strtolower($rep['status'] ?? ($rep['wr_status'] ?? ''));
            $revNote = strtolower($rep['review_note'] ?? ($rep['wr_review_comment'] ?? ''));

            if ($rawSt === 'revision_requested' || str_contains($revNote, 'revision')) {
                $repStatus = 'revision_requested';
                $metrics['reports_revision']++;
            } elseif ($rawSt === 'rejected') {
                $repStatus = 'rejected';
                $metrics['reports_rejected']++;
            } elseif ($rawSt === 'approved' || (!empty($rep['rating']) && (int)$rep['rating'] >= 4)) {
                $repStatus = 'approved';
                $metrics['reports_approved']++;
            } else {
                $repStatus = 'submitted';
            }
        } else {
            if (in_array($attStatus, ['present', 'late'])) {
                $metrics['reports_missing']++;
            }
        }

        // Apply Status Filter
        if ($statusFilter !== 'all') {
            if ($statusFilter === 'missing' && $repStatus !== 'missing') continue;
            if ($statusFilter === 'submitted' && $repStatus !== 'submitted') continue;
            if ($statusFilter === 'approved' && $repStatus !== 'approved') continue;
            if ($statusFilter === 'revision_requested' && $repStatus !== 'revision_requested') continue;
            if ($statusFilter === 'rejected' && $repStatus !== 'rejected') continue;
            if ($statusFilter === 'present' && !in_array($attStatus, ['present', 'late'])) continue;
            if ($statusFilter === 'late' && $attStatus !== 'late') continue;
            if ($statusFilter === 'absent' && $attStatus !== 'absent') continue;
        }

        $crossRows[] = [
            'date'      => $d,
            'emp'       => $emp,
            'att'       => $att,
            'attStatus' => $attStatus,
            'rep'       => $rep,
            'repStatus' => $repStatus
        ];
    }
}

$metrics['total_records'] = count($crossRows);
?>

<div class="container-fluid py-4">

  <!-- PAGE HEADER -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0 text-dark">
        <i class="bi bi-clipboard-check text-primary me-2"></i>Attendance vs Reports
      </h3>
      <p class="text-muted mb-0 small">Cross-verify Employee Attendance vs Daily Work Reports · Review & Revision Workflow</p>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <button class="btn btn-sm btn-outline-primary shadow-sm" onclick="window.print()">
        <i class="bi bi-printer me-1"></i> Print / Export
      </button>
      <a href="daily_management_report.php?filter_mode=day&date=<?= date('Y-m-d') ?>" class="btn btn-sm btn-primary shadow-sm">
        <i class="bi bi-arrow-clockwise me-1"></i> Reset to Today
      </a>
    </div>
  </div>

  <?php $flash = get_flash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-check-circle-fill me-2"></i><?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- COMPREHENSIVE FILTER TOOLBAR CARD -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
    <div class="card-body p-3 p-md-4">
      <!-- Filter Mode Selector Tabs -->
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 pb-3 mb-3 border-bottom">
        <div class="nav nav-pills gap-1" id="filterModePills" role="tablist">
          <a href="?filter_mode=day&date=<?= urlencode($viewDate) ?>&employee_id=<?= $empFilterId ?>&department=<?= urlencode($deptFilter) ?>" 
             class="btn btn-sm <?= $filterMode === 'day' ? 'btn-primary active' : 'btn-outline-secondary' ?> fw-semibold">
            <i class="bi bi-calendar-day me-1"></i> Day Wise
          </a>
          <a href="?filter_mode=month&month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&employee_id=<?= $empFilterId ?>&department=<?= urlencode($deptFilter) ?>" 
             class="btn btn-sm <?= $filterMode === 'month' ? 'btn-primary active' : 'btn-outline-secondary' ?> fw-semibold">
            <i class="bi bi-calendar-month me-1"></i> Month Wise
          </a>
          <a href="?filter_mode=range&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&employee_id=<?= $empFilterId ?>&department=<?= urlencode($deptFilter) ?>" 
             class="btn btn-sm <?= $filterMode === 'range' ? 'btn-primary active' : 'btn-outline-secondary' ?> fw-semibold">
            <i class="bi bi-calendar-range me-1"></i> Date Range
          </a>
        </div>

        <?php if ($filterMode === 'day'): ?>
          <!-- Quick Day Shortcuts -->
          <div class="d-flex align-items-center gap-2">
            <a href="?filter_mode=day&date=<?= date('Y-m-d', strtotime($viewDate . ' -1 day')) ?>&employee_id=<?= $empFilterId ?>" class="btn btn-sm btn-light border" title="Previous Day">
              <i class="bi bi-chevron-left"></i>
            </a>
            <span class="fw-bold text-dark px-2"><?= date('D, d M Y', strtotime($viewDate)) ?></span>
            <a href="?filter_mode=day&date=<?= date('Y-m-d', strtotime($viewDate . ' +1 day')) ?>&employee_id=<?= $empFilterId ?>" class="btn btn-sm btn-light border" title="Next Day">
              <i class="bi bi-chevron-right"></i>
            </a>
            <a href="?filter_mode=day&date=<?= date('Y-m-d') ?>&employee_id=<?= $empFilterId ?>" class="btn btn-sm btn-outline-secondary">Today</a>
            <a href="?filter_mode=day&date=<?= date('Y-m-d', strtotime('-1 day')) ?>&employee_id=<?= $empFilterId ?>" class="btn btn-sm btn-outline-secondary">Yesterday</a>
          </div>
        <?php endif; ?>
      </div>

      <!-- Filter Controls Form -->
      <form method="get" class="row g-3 align-items-end" id="filterForm">
        <input type="hidden" name="filter_mode" value="<?= e($filterMode) ?>">

        <!-- 1. Date Controls Based on Mode -->
        <?php if ($filterMode === 'day'): ?>
          <div class="col-md-3">
            <label class="form-label small fw-semibold text-muted mb-1">Select Date</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-white"><i class="bi bi-calendar-event text-primary"></i></span>
              <input type="date" name="date" value="<?= e($viewDate) ?>" class="form-control" onchange="this.form.submit()">
            </div>
          </div>
        <?php elseif ($filterMode === 'month'): ?>
          <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">Month</label>
            <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>>
                  <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">Year</label>
            <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
              <?php for ($y = date('Y') + 1; $y >= 2022; $y--): ?>
                <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
        <?php elseif ($filterMode === 'range'): ?>
          <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">From Date</label>
            <input type="date" name="from_date" value="<?= e($fromDate) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">To Date</label>
            <input type="date" name="to_date" value="<?= e($toDate) ?>" class="form-control form-control-sm">
          </div>
        <?php endif; ?>

        <!-- 2. User / Employee Filter -->
        <div class="col-md-3">
          <label class="form-label small fw-semibold text-muted mb-1">Employee / User</label>
          <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="0">All Employees (<?= count($allEmployees) ?>)</option>
            <?php foreach ($allEmployees as $empOpt): ?>
              <option value="<?= $empOpt['id'] ?>" <?= $empFilterId === intval($empOpt['id']) ? 'selected' : '' ?>>
                <?= e($empOpt['full_name']) ?> (<?= e($empOpt['employee_code']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- 3. Status Filter -->
        <div class="col-md-2">
          <label class="form-label small fw-semibold text-muted mb-1">Report Status</label>
          <select name="status_filter" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
            <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>Submitted (Pending)</option>
            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="revision_requested" <?= $statusFilter === 'revision_requested' ? 'selected' : '' ?>>Revision Requested</option>
            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            <option value="missing" <?= $statusFilter === 'missing' ? 'selected' : '' ?>>Report Missing</option>
            <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present (Attendance)</option>
            <option value="late" <?= $statusFilter === 'late' ? 'selected' : '' ?>>Late (Attendance)</option>
            <option value="absent" <?= $statusFilter === 'absent' ? 'selected' : '' ?>>Absent (Attendance)</option>
          </select>
        </div>

        <!-- 4. Department Filter -->
        <div class="col-md-2">
          <label class="form-label small fw-semibold text-muted mb-1">Department</label>
          <select name="department" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
              <option value="<?= e($dept) ?>" <?= $deptFilter === $dept ? 'selected' : '' ?>><?= e($dept) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Filter Submit Button -->
        <div class="col-md-1">
          <button type="submit" class="btn btn-sm btn-primary w-100 fw-semibold">
            <i class="bi bi-funnel-fill"></i> Filter
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Missing Reports Alert Banner if any in Day Mode -->
  <?php if ($isSingleDay && !empty($missingReportsList)): ?>
  <div class="alert alert-warning border-0 shadow-sm mb-4 d-flex align-items-center justify-content-between p-3" style="border-radius:12px; border-left: 5px solid #f59e0b !important;">
    <div class="d-flex align-items-center gap-3">
      <i class="bi bi-exclamation-triangle-fill fs-3 text-warning-emphasis"></i>
      <div>
        <div class="fw-bold text-warning-emphasis">Missing Work Reports Detected (<?= count($missingReportsList) ?> Employees)</div>
        <div class="small text-dark mt-1">Present on <?= date('d M Y', strtotime($viewDate)) ?> but have not submitted evening work report: <strong><?= implode(', ', $missingReportsList) ?></strong></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- SUMMARY METRICS CARDS -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:12px;">
        <div class="text-muted small">Total Records</div>
        <div class="fw-bold fs-4 text-dark"><?= $metrics['total_records'] ?></div>
        <div class="small text-muted"><?= $filterMode === 'day' ? 'Employees' : 'Day Logs' ?></div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:12px;">
        <div class="text-muted small">Present in Attendance</div>
        <div class="fw-bold fs-4 text-success"><?= $metrics['present_count'] ?></div>
        <div class="small text-warning-emphasis"><?= $metrics['late_count'] ?> Late</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:12px;">
        <div class="text-muted small">Reports Submitted</div>
        <div class="fw-bold fs-4 text-primary"><?= $metrics['reports_submitted'] ?></div>
        <div class="small text-muted">Ready to Review</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:12px;">
        <div class="text-muted small">Reports Approved</div>
        <div class="fw-bold fs-4 text-success"><?= $metrics['reports_approved'] ?></div>
        <div class="small text-success"><i class="bi bi-check2-all me-1"></i>Verified</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:12px;">
        <div class="text-muted small">Revision Requested</div>
        <div class="fw-bold fs-4 text-warning-emphasis"><?= $metrics['reports_revision'] ?></div>
        <div class="small text-warning-emphasis">Awaiting User Edit</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:12px;">
        <div class="text-muted small">Reports Missing</div>
        <div class="fw-bold fs-4 <?= $metrics['reports_missing'] > 0 ? 'text-danger' : 'text-success' ?>">
          <?= $metrics['reports_missing'] ?>
        </div>
        <div class="small text-muted">Action Required</div>
      </div>
    </div>
  </div>

  <!-- CROSS-CHECK MATRIX TABLE -->
  <div class="card border-0 shadow-sm" style="border-radius:16px;">
    <div class="card-header bg-white border-0 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <h5 class="fw-bold text-dark mb-0">
          <i class="bi bi-layout-three-columns me-2 text-primary"></i>Attendance vs Work Report Cross-Check
        </h5>
        <small class="text-muted">
          Showing <?= count($crossRows) ?> records 
          · <?= $filterMode === 'day' ? date('d F Y', strtotime($viewDate)) : (date('d M Y', strtotime($startDate)) . ' to ' . date('d M Y', strtotime($endDate))) ?>
        </small>
      </div>
      <div class="d-flex align-items-center gap-2">
        <input type="text" id="tableSearchBox" class="form-control form-control-sm" placeholder="Search employee or task..." style="width:220px;" onkeyup="filterTableSearch()">
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="matrixTable" style="font-size:0.85rem;">
          <thead class="table-light">
            <tr>
              <?php if (!$isSingleDay): ?>
                <th class="ps-3 text-nowrap">Date</th>
              <?php endif; ?>
              <th class="<?= $isSingleDay ? 'ps-4' : '' ?> text-nowrap">Employee</th>
              <th class="text-nowrap">Department</th>
              <th class="text-nowrap">Attendance Status</th>
              <th class="text-nowrap">Punch In / Out</th>
              <th class="text-nowrap">Work Report Status</th>
              <th class="text-nowrap">Hours</th>
              <th class="text-nowrap" style="min-width:260px;">Evening Tasks / Plan</th>
              <th class="pe-4 text-end text-nowrap">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($crossRows)): ?>
              <tr>
                <td colspan="<?= $isSingleDay ? 8 : 9 ?>" class="text-center py-5 text-muted">
                  <i class="bi bi-clipboard-x fs-1 d-block mb-2 text-muted"></i>
                  No attendance or work report records found matching your filters.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($crossRows as $row): 
                $emp     = $row['emp'];
                $att     = $row['att'];
                $rep     = $row['rep'];
                $rowDate = $row['date'];
                $aSt     = $row['attStatus'];
                $rSt     = $row['repStatus'];

                // Attendance Badges
                $attBadge = match($aSt) {
                  'present' => 'bg-success-subtle text-success border border-success-subtle',
                  'late' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                  'absent' => 'bg-danger-subtle text-danger border border-danger-subtle',
                  'on_leave' => 'bg-info-subtle text-info border border-info-subtle',
                  'holiday' => 'bg-secondary-subtle text-secondary border',
                  default => 'bg-light text-muted border'
                };

                // Report Badges
                $repBadge = match($rSt) {
                  'approved' => 'bg-success-subtle text-success border border-success-subtle',
                  'submitted' => 'bg-info-subtle text-info border border-info-subtle',
                  'revision_requested' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-semibold',
                  'rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
                  'missing' => 'bg-danger-subtle text-danger border border-danger-subtle fw-bold',
                  default => 'bg-light text-muted border'
                };

                // Prepare JSON payload for the modal viewer
                $detailPayload = [
                  'employee_id'     => $emp['id'],
                  'employee_name'   => $emp['full_name'],
                  'employee_code'   => $emp['employee_code'],
                  'department'      => $emp['department'] ?? 'Operations',
                  'designation'     => $emp['designation'] ?? 'Team Member',
                  'report_date'     => $rowDate,
                  'report_date_fmt' => date('l, d F Y', strtotime($rowDate)),
                  // Attendance Details
                  'att_status'      => $aSt,
                  'check_in'        => ($att && $att['check_in']) ? date('h:i:s A', strtotime($att['check_in'])) : '—',
                  'check_out'       => ($att && $att['check_out']) ? date('h:i:s A', strtotime($att['check_out'])) : '—',
                  'att_hours'       => ($att && $att['total_hours'] > 0) ? format_hours_hms($att['total_hours'], true) . ' (' . number_format($att['total_hours'], 2) . 'h)' : '0h',
                  'lunch_minutes'   => ($att && isset($att['lunch_duration_minutes'])) ? $att['lunch_duration_minutes'] : 0,
                  // Work Report Details
                  'has_report'      => !empty($rep),
                  'report_id'       => $rep['id'] ?? 0,
                  'report_status'   => $rSt,
                  'hours_worked'    => $rep['hours_worked'] ?? 0,
                  'morning_tasks'   => $rep['morning_tasks'] ?? '',
                  'evening_tasks'   => $rep['evening_tasks'] ?? ($rep['tasks_completed'] ?? ''),
                  'projects_worked' => $rep['projects_worked'] ?? '',
                  'clients_worked'  => $rep['clients_worked'] ?? '',
                  'blockers'        => $rep['blockers'] ?? ($rep['challenges'] ?? ''),
                  'plan_for_tomorrow'=> $rep['plan_for_tomorrow'] ?? ($rep['tomorrow_plan'] ?? ''),
                  'rating'          => $rep['rating'] ?? 0,
                  'review_note'     => $rep['review_note'] ?? ($rep['wr_review_comment'] ?? ''),
                  'reviewer_name'   => $rep['reviewer_name'] ?? '',
                  'reviewed_at'     => !empty($rep['reviewed_at']) ? date('d M Y, h:i A', strtotime($rep['reviewed_at'])) : '',
                  'submitted_at'    => !empty($rep['submitted_at']) ? date('d M Y, h:i A', strtotime($rep['submitted_at'])) : '',
                  'updated_at'      => !empty($rep['updated_at']) ? date('d M Y, h:i A', strtotime($rep['updated_at'])) : ''
                ];
                $jsonPayload = htmlspecialchars(json_encode($detailPayload, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
              ?>
              <tr class="searchable-row">
                <?php if (!$isSingleDay): ?>
                  <td class="ps-3 fw-semibold text-nowrap">
                    <div><?= date('d M Y', strtotime($rowDate)) ?></div>
                    <small class="text-muted"><?= date('D', strtotime($rowDate)) ?></small>
                  </td>
                <?php endif; ?>
                <td class="<?= $isSingleDay ? 'ps-4' : '' ?>">
                  <div class="fw-bold text-dark emp-name-cell"><?= e($emp['full_name']) ?></div>
                  <div class="text-muted small" style="font-size:0.72rem;"><?= e($emp['employee_code']) ?></div>
                </td>
                <td><span class="badge bg-light text-dark border"><?= e($emp['department'] ?? 'Operations') ?></span></td>
                <td>
                  <span class="badge <?= $attBadge ?> px-2 py-1 rounded-pill text-nowrap">
                    <?= ucwords(str_replace('_',' ', $aSt)) ?>
                  </span>
                </td>
                <td>
                  <?php if ($att && $att['check_in']): ?>
                    <div class="text-nowrap"><i class="bi bi-box-arrow-in-right text-success me-1"></i><?= date('h:i A', strtotime($att['check_in'])) ?></div>
                    <?php if ($att['check_out']): ?>
                      <div class="text-muted small text-nowrap"><i class="bi bi-box-arrow-right text-danger me-1"></i><?= date('h:i A', strtotime($att['check_out'])) ?></div>
                    <?php else: ?>
                      <div class="text-muted small text-nowrap"><i class="bi bi-clock me-1"></i>Active</div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?= $repBadge ?> px-2 py-1 rounded-pill text-nowrap">
                    <?= $rSt === 'missing' ? '⚠ Missing Report' : ucwords(str_replace('_',' ', $rSt)) ?>
                  </span>
                  <?php if ($rep && !empty($rep['rating']) && (int)$rep['rating'] > 0): ?>
                    <div class="text-warning small" style="font-size:0.7rem;">★ <?= $rep['rating'] ?>/5</div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($att && $att['total_hours'] > 0): ?>
                    <div class="fw-semibold text-dark text-nowrap"><?= format_hours_hms($att['total_hours'], true) ?></div>
                    <div class="text-muted small text-nowrap" style="font-size:0.7rem;">
                      Att: <?= number_format($att['total_hours'],1) ?>h<?= ($rep && $rep['hours_worked'] > 0) ? ' · Rep: ' . $rep['hours_worked'] . 'h' : '' ?>
                    </div>
                  <?php elseif ($rep && $rep['hours_worked'] > 0): ?>
                    <div class="fw-semibold text-dark"><?= $rep['hours_worked'] ?>h</div>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td style="max-width:320px;">
                  <?php if ($rep): ?>
                    <div class="text-dark fw-medium text-truncate"><?= e($rep['evening_tasks'] ?? $rep['morning_tasks']) ?></div>
                    <?php if (!empty($rep['projects_worked'])): ?>
                      <div class="text-primary small text-truncate" style="font-size:0.72rem;">
                        <i class="bi bi-kanban me-1"></i><?= e($rep['projects_worked']) ?>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted small">Not submitted</span>
                  <?php endif; ?>
                </td>
                <td class="pe-4 text-end">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i> Actions
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <button type="button" class="dropdown-item" onclick="openViewReportModal('<?= $jsonPayload ?>')">
                          <i class="bi bi-eye text-primary me-2"></i> View Full Report
                        </button>
                      </li>
                      <?php if ($rep): ?>
                        <li>
                          <button type="button" class="dropdown-item text-success fw-semibold" onclick="quickReviewReport('<?= $jsonPayload ?>', 'approved')">
                            <i class="bi bi-check2-circle text-success me-2"></i> Approve Report
                          </button>
                        </li>
                        <li>
                          <button type="button" class="dropdown-item text-warning-emphasis fw-semibold" onclick="quickReviewReport('<?= $jsonPayload ?>', 'revision_requested')">
                            <i class="bi bi-arrow-repeat text-warning me-2"></i> Request Revision
                          </button>
                        </li>
                        <li>
                          <button type="button" class="dropdown-item text-danger" onclick="quickReviewReport('<?= $jsonPayload ?>', 'rejected')">
                            <i class="bi bi-x-circle text-danger me-2"></i> Reject Report
                          </button>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                      <?php endif; ?>
                      <li>
                        <a href="attendance.php?date=<?= $rowDate ?>&employee_id=<?= $emp['id'] ?>" class="dropdown-item">
                          <i class="bi bi-calendar-check text-info me-2"></i> Attendance Details
                        </a>
                      </li>
                      <li>
                        <a href="employee_profile.php?id=<?= $emp['id'] ?>&tab=attendance_reports" class="dropdown-item">
                          <i class="bi bi-person text-secondary me-2"></i> Employee Profile
                        </a>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- ==================================================== -->
<!-- COMPREHENSIVE VIEW FULL REPORT & REVIEW MODAL        -->
<!-- ==================================================== -->
<div class="modal fade" id="viewReportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius:18px;">
      
      <!-- Modal Header -->
      <div class="modal-header border-0 pb-0">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="vrModalTitle">
            <i class="bi bi-file-text text-primary me-2"></i>Daily Work Report Details
          </h5>
          <small class="text-muted" id="vrModalSubtitle">Employee details & report</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-4">

        <!-- Employee Info Card -->
        <div class="card border bg-light mb-3" style="border-radius:12px;">
          <div class="card-body p-3">
            <div class="row g-2 align-items-center">
              <div class="col-md-7">
                <div class="d-flex align-items-center gap-3">
                  <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width:46px; height:46px;" id="vrEmpAvatar">
                    E
                  </div>
                  <div>
                    <h6 class="fw-bold mb-0 text-dark" id="vrEmpName">Employee Name</h6>
                    <small class="text-muted" id="vrEmpMeta">EMP001 · Department · Designation</small>
                  </div>
                </div>
              </div>
              <div class="col-md-5 text-md-end">
                <span class="badge bg-white text-dark border px-3 py-2 shadow-sm rounded-pill fw-semibold" id="vrReportDateBadge">
                  <i class="bi bi-calendar-event me-1 text-primary"></i> 26 Sep 2026
                </span>
                <span class="badge px-3 py-2 rounded-pill ms-1" id="vrStatusBadge">Submitted</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Attendance Cross-Check Box -->
        <div class="card border border-info-subtle bg-info-subtle bg-opacity-10 mb-3" style="border-radius:12px;">
          <div class="card-body p-3">
            <h6 class="fw-bold text-info-emphasis mb-2 small text-uppercase" style="letter-spacing:0.5px;">
              <i class="bi bi-clock-history me-1"></i> Attendance Cross-Check on this Date
            </h6>
            <div class="row g-2 text-center text-md-start">
              <div class="col-6 col-md-3">
                <small class="text-muted d-block">Punch-In</small>
                <strong class="text-dark" id="vrCheckIn">—</strong>
              </div>
              <div class="col-6 col-md-3">
                <small class="text-muted d-block">Punch-Out</small>
                <strong class="text-dark" id="vrCheckOut">—</strong>
              </div>
              <div class="col-6 col-md-3">
                <small class="text-muted d-block">Total Attendance</small>
                <strong class="text-dark" id="vrAttHours">—</strong>
              </div>
              <div class="col-6 col-md-3">
                <small class="text-muted d-block">Attendance Status</small>
                <span class="badge" id="vrAttStatusBadge">Present</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Work Report User Inputs (All fields filled by user) -->
        <div id="vrReportContentArea">

          <!-- Projects & Hours -->
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <div class="p-3 bg-white border rounded-3 h-100">
                <small class="text-muted fw-semibold d-block text-uppercase" style="font-size:0.75rem;">
                  <i class="bi bi-kanban me-1 text-primary"></i> Projects / Clients Handled
                </small>
                <div class="fw-semibold text-dark mt-1" id="vrProjectsWorked">—</div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-3 bg-white border rounded-3 h-100">
                <small class="text-muted fw-semibold d-block text-uppercase" style="font-size:0.75rem;">
                  <i class="bi bi-hourglass-split me-1 text-primary"></i> Hours Claimed in Report
                </small>
                <div class="fw-bold text-dark mt-1 fs-6" id="vrHoursClaimed">—</div>
              </div>
            </div>
          </div>

          <!-- Morning Plan -->
          <div class="p-3 bg-white border rounded-3 mb-3" id="vrMorningBox">
            <small class="text-muted fw-semibold d-block text-uppercase" style="font-size:0.75rem;">
              <i class="bi bi-sun text-warning me-1"></i> Morning Plan / Tasks Started
            </small>
            <div class="text-dark mt-1" style="white-space: pre-wrap;" id="vrMorningTasks">—</div>
          </div>

          <!-- Evening Work Completed (Mandatory Field) -->
          <div class="p-3 bg-white border rounded-3 mb-3" style="border-left: 4px solid #10b981 !important;">
            <small class="text-success fw-bold d-block text-uppercase" style="font-size:0.75rem;">
              <i class="bi bi-moon-stars text-primary me-1"></i> Evening Work Completed (Tasks Finished)
            </small>
            <div class="text-dark mt-2 fw-medium" style="white-space: pre-wrap;" id="vrEveningTasks">—</div>
          </div>

          <!-- Challenges / Blockers -->
          <div class="p-3 bg-white border rounded-3 mb-3" id="vrBlockersBox" style="border-left: 4px solid #ef4444 !important;">
            <small class="text-danger fw-bold d-block text-uppercase" style="font-size:0.75rem;">
              <i class="bi bi-exclamation-triangle-fill text-danger me-1"></i> Challenges / Technical Blockers
            </small>
            <div class="text-dark mt-1" style="white-space: pre-wrap;" id="vrBlockers">—</div>
          </div>

          <!-- Tomorrow Plan -->
          <div class="p-3 bg-white border rounded-3 mb-3" id="vrTomorrowBox">
            <small class="text-muted fw-semibold d-block text-uppercase" style="font-size:0.75rem;">
              <i class="bi bi-arrow-right-circle text-primary me-1"></i> Plan for Tomorrow / Targets
            </small>
            <div class="text-dark mt-1" style="white-space: pre-wrap;" id="vrTomorrowPlan">—</div>
          </div>

          <!-- Submission Timestamps -->
          <div class="d-flex justify-content-between flex-wrap text-muted small px-1 mb-4" style="font-size:0.75rem;">
            <span id="vrSubmittedAt"><i class="bi bi-clock me-1"></i>Submitted: —</span>
            <span id="vrUpdatedAt"><i class="bi bi-pencil-square me-1"></i>Updated: —</span>
          </div>

        </div>

        <div id="vrNoReportArea" class="alert alert-danger border-0 d-none text-center py-4" style="border-radius:12px;">
          <i class="bi bi-exclamation-octagon fs-2 d-block mb-2"></i>
          <strong>No Daily Work Report was submitted for this date.</strong>
          <div class="small mt-1 text-muted">The employee attended shift but evening report is missing.</div>
        </div>

        <!-- ==================================================== -->
        <!-- ADMIN ACTION & REVIEW DECISION FORM                  -->
        <!-- ==================================================== -->
        <div class="card border border-primary bg-primary bg-opacity-10" style="border-radius:14px;">
          <div class="card-body p-3 p-md-4">
            <h6 class="fw-bold text-primary mb-3">
              <i class="bi bi-star-fill text-warning me-1"></i> Admin / Manager Review & Actions
            </h6>

            <!-- Existing Review History Banner if reviewed -->
            <div id="vrExistingReviewBanner" class="alert alert-light border p-2 mb-3 d-none">
              <div class="d-flex justify-content-between align-items-center">
                <span class="small fw-bold text-dark" id="vrReviewerLabel">Reviewed by Admin</span>
                <span class="text-warning small" id="vrRatingStars">★★★★★</span>
              </div>
              <div class="small text-muted mt-1" id="vrExistingComment">Comment</div>
            </div>

            <form method="post" id="reviewReportForm">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="review_report">
              <input type="hidden" name="report_id" id="modalFormReportId" value="0">
              <input type="hidden" name="employee_id" id="modalFormEmpId" value="0">
              <input type="hidden" name="report_date" id="modalFormReportDate" value="">

              <!-- Decision Buttons -->
              <div class="mb-3">
                <label class="form-label small fw-semibold text-dark">Review Decision</label>
                <div class="d-flex gap-2">
                  <input type="radio" class="btn-check" name="review_status" id="stApprove" value="approved" checked onchange="handleDecisionChange('approved')">
                  <label class="btn btn-outline-success btn-sm flex-fill py-2 fw-semibold" for="stApprove">
                    <i class="bi bi-check-lg me-1"></i> Approve
                  </label>

                  <input type="radio" class="btn-check" name="review_status" id="stRevision" value="revision_requested" onchange="handleDecisionChange('revision_requested')">
                  <label class="btn btn-outline-warning text-dark btn-sm flex-fill py-2 fw-semibold" for="stRevision">
                    <i class="bi bi-arrow-repeat me-1"></i> Request Revision
                  </label>

                  <input type="radio" class="btn-check" name="review_status" id="stReject" value="rejected" onchange="handleDecisionChange('rejected')">
                  <label class="btn btn-outline-danger btn-sm flex-fill py-2 fw-semibold" for="stReject">
                    <i class="bi bi-x-lg me-1"></i> Reject
                  </label>
                </div>
              </div>

              <!-- Rating Stars Picker -->
              <div class="mb-3" id="ratingContainer">
                <label class="form-label small fw-semibold text-dark">Performance Rating</label>
                <div class="d-flex align-items-center gap-2">
                  <select name="rating" id="modalRatingSelect" class="form-select form-select-sm" style="width:140px;">
                    <option value="5" selected>★★★★★ (5/5)</option>
                    <option value="4">★★★★☆ (4/5)</option>
                    <option value="3">★★★☆☆ (3/5)</option>
                    <option value="2">★★☆☆☆ (2/5)</option>
                    <option value="1">★☆☆☆☆ (1/5)</option>
                    <option value="0">No Rating</option>
                  </select>
                  <small class="text-muted" id="ratingHint">Standard score for quality work</small>
                </div>
              </div>

              <!-- Feedback / Comment Textarea -->
              <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label small fw-semibold text-dark mb-0">Manager Feedback / Instructions</label>
                  <!-- Quick Snippets -->
                  <div class="dropdown">
                    <button class="btn btn-link btn-sm p-0 text-decoration-none dropdown-toggle small" type="button" data-bs-toggle="dropdown">
                      Quick Snippets
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li><a class="dropdown-item small" href="javascript:void(0)" onclick="setSnippet('Good work! Tasks completed as scheduled.')">✔ Good work completed</a></li>
                      <li><a class="dropdown-item small" href="javascript:void(0)" onclick="setSnippet('Check your report again & submit again. Please detail evening completed tasks.')">🔄 Detail evening tasks</a></li>
                      <li><a class="dropdown-item small" href="javascript:void(0)" onclick="setSnippet('Check your report again & submit again. Please specify projects and clients handled.')">🔄 Specify projects</a></li>
                      <li><a class="dropdown-item small" href="javascript:void(0)" onclick="setSnippet('Report incomplete. Please verify hours and resubmit.')">✖ Incomplete report</a></li>
                    </ul>
                  </div>
                </div>
                <textarea name="review_comment" id="modalReviewComment" class="form-control" rows="3" placeholder="Enter praise, guidance, or specify what needs to be revised..."></textarea>
                <small class="text-muted" id="commentHelpText">For revision requested, explain what needs correction so the employee can re-edit and submit.</small>
              </div>

              <!-- Action Submit Buttons -->
              <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm" id="btnSaveReview">
                  <i class="bi bi-save me-1"></i> Save Review & Notify
                </button>
              </div>

            </form>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<script>
// Search box live filter in table
function filterTableSearch() {
  const query = document.getElementById('tableSearchBox').value.toLowerCase();
  const rows = document.querySelectorAll('#matrixTable tbody tr.searchable-row');
  rows.forEach(row => {
    const text = row.innerText.toLowerCase();
    row.style.display = text.includes(query) ? '' : 'none';
  });
}

// Open View Full Report Modal with all details
function openViewReportModal(jsonStr) {
  const data = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;

  // Basic Header Info
  document.getElementById('vrEmpAvatar').textContent = (data.employee_name || 'E').charAt(0).toUpperCase();
  document.getElementById('vrEmpName').textContent = data.employee_name || 'Employee';
  document.getElementById('vrEmpMeta').textContent = `${data.employee_code} · ${data.department} · ${data.designation}`;
  document.getElementById('vrReportDateBadge').innerHTML = `<i class="bi bi-calendar-event me-1 text-primary"></i> ${data.report_date_fmt || data.report_date}`;

  // Attendance Info
  document.getElementById('vrCheckIn').textContent = data.check_in || '—';
  document.getElementById('vrCheckOut').textContent = data.check_out || '—';
  document.getElementById('vrAttHours').textContent = data.att_hours || '0h';
  
  const attBadge = document.getElementById('vrAttStatusBadge');
  attBadge.textContent = (data.att_status || 'unmarked').toUpperCase();
  attBadge.className = 'badge ' + (
    data.att_status === 'present' ? 'bg-success' :
    data.att_status === 'late' ? 'bg-warning text-dark' :
    data.att_status === 'absent' ? 'bg-danger' : 'bg-secondary'
  );

  // Status Badge
  const stBadge = document.getElementById('vrStatusBadge');
  stBadge.textContent = (data.report_status || 'missing').toUpperCase().replace('_', ' ');
  stBadge.className = 'badge px-3 py-2 rounded-pill ' + (
    data.report_status === 'approved' ? 'bg-success text-white' :
    data.report_status === 'revision_requested' ? 'bg-warning text-dark' :
    data.report_status === 'rejected' ? 'bg-danger text-white' :
    data.report_status === 'submitted' ? 'bg-info text-dark' : 'bg-secondary text-white'
  );

  // Form IDs
  document.getElementById('modalFormReportId').value = data.report_id || 0;
  document.getElementById('modalFormEmpId').value = data.employee_id || 0;
  document.getElementById('modalFormReportDate').value = data.report_date || '';

  // Content Visibility
  const repArea = document.getElementById('vrReportContentArea');
  const noRepArea = document.getElementById('vrNoReportArea');

  if (data.has_report) {
    repArea.classList.remove('d-none');
    noRepArea.classList.add('d-none');

    document.getElementById('vrProjectsWorked').textContent = data.projects_worked || (data.clients_worked || 'None Specified');
    document.getElementById('vrHoursClaimed').textContent = (data.hours_worked > 0 ? (data.hours_worked + ' Hours') : '—');
    document.getElementById('vrMorningTasks').textContent = data.morning_tasks || 'None logged';
    document.getElementById('vrEveningTasks').textContent = data.evening_tasks || 'No task description provided';
    document.getElementById('vrBlockers').textContent = data.blockers || 'None';
    document.getElementById('vrTomorrowPlan').textContent = data.plan_for_tomorrow || 'None';

    document.getElementById('vrSubmittedAt').innerHTML = `<i class="bi bi-clock me-1"></i> Submitted: ${data.submitted_at || '—'}`;
    document.getElementById('vrUpdatedAt').innerHTML = data.updated_at ? `<i class="bi bi-pencil-square me-1"></i> Last Edited: ${data.updated_at}` : '';
  } else {
    repArea.classList.add('d-none');
    noRepArea.classList.remove('d-none');
  }

  // Pre-fill Review Form
  const revBanner = document.getElementById('vrExistingReviewBanner');
  if (data.reviewer_name || data.review_note) {
    revBanner.classList.remove('d-none');
    document.getElementById('vrReviewerLabel').textContent = `Reviewed by ${data.reviewer_name || 'Admin'} on ${data.reviewed_at || 'Recent'}`;
    document.getElementById('vrExistingComment').textContent = data.review_note ? `"${data.review_note}"` : 'No comment provided';
    
    let starsStr = '';
    const rInt = parseInt(data.rating || 0);
    for (let i = 1; i <= 5; i++) starsStr += (i <= rInt ? '★' : '☆');
    document.getElementById('vrRatingStars').textContent = starsStr + (rInt > 0 ? ` (${rInt}/5)` : '');
  } else {
    revBanner.classList.add('d-none');
  }

  // Set default radio
  if (data.report_status === 'revision_requested') {
    document.getElementById('stRevision').checked = true;
    handleDecisionChange('revision_requested');
  } else if (data.report_status === 'rejected') {
    document.getElementById('stReject').checked = true;
    handleDecisionChange('rejected');
  } else {
    document.getElementById('stApprove').checked = true;
    handleDecisionChange('approved');
  }

  document.getElementById('modalReviewComment').value = data.review_note || '';

  new bootstrap.Modal('#viewReportModal').show();
}

// Quick Review from Row Actions dropdown
function quickReviewReport(jsonStr, targetStatus) {
  const data = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;
  openViewReportModal(data);
  if (targetStatus === 'approved') {
    document.getElementById('stApprove').checked = true;
    handleDecisionChange('approved');
  } else if (targetStatus === 'revision_requested') {
    document.getElementById('stRevision').checked = true;
    handleDecisionChange('revision_requested');
    if (!document.getElementById('modalReviewComment').value) {
      document.getElementById('modalReviewComment').value = 'Check your report again & submit again. Please ensure all tasks are detailed accurately.';
    }
  } else if (targetStatus === 'rejected') {
    document.getElementById('stReject').checked = true;
    handleDecisionChange('rejected');
  }
}

// Handle radio decision switch
function handleDecisionChange(status) {
  const ratingContainer = document.getElementById('ratingContainer');
  const commentHelp = document.getElementById('commentHelpText');
  const btnSave = document.getElementById('btnSaveReview');
  const ratingSelect = document.getElementById('modalRatingSelect');

  if (status === 'approved') {
    ratingContainer.style.display = 'block';
    if (ratingSelect.value === '0') ratingSelect.value = '5';
    commentHelp.textContent = 'Optional guidance or commendation for employee.';
    btnSave.className = 'btn btn-success px-4 fw-semibold shadow-sm';
    btnSave.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Approve Report';
  } else if (status === 'revision_requested') {
    ratingContainer.style.display = 'none';
    commentHelp.textContent = 'Explain clearly what the employee needs to fix. User will be allowed to edit and resubmit.';
    btnSave.className = 'btn btn-warning text-dark px-4 fw-semibold shadow-sm';
    btnSave.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Request Revision';
  } else if (status === 'rejected') {
    ratingContainer.style.display = 'none';
    commentHelp.textContent = 'State the reason for rejecting the work report.';
    btnSave.className = 'btn btn-danger px-4 fw-semibold shadow-sm';
    btnSave.innerHTML = '<i class="bi bi-x-circle me-1"></i> Reject Report';
  }
}

// Quick Snippet insertion
function setSnippet(text) {
  document.getElementById('modalReviewComment').value = text;
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
