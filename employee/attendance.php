<?php
// employee/attendance.php — Employee Personal Attendance History & Calendar
require_once __DIR__ . '/layout.php';

$empId = (int)($_SESSION['employee_id'] ?? 0);
if ($empId <= 0) {
    abort_forbidden("No verified employee profile linked to your account.");
}
\App\Security\SecurityHeaders::applyNoCache();

$filterMonth = intval($_GET['month'] ?? date('m'));
$filterYear  = intval($_GET['year'] ?? date('Y'));

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $filterMonth, $filterYear);
$monthStart  = "$filterYear-" . str_pad($filterMonth, 2, '0', STR_PAD_LEFT) . "-01";
$monthEnd    = "$filterYear-" . str_pad($filterMonth, 2, '0', STR_PAD_LEFT) . "-$daysInMonth";

// Fetch attendance for this month (Latest -> Oldest)
$attStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date DESC");
$attStmt->execute([$empId, $monthStart, $monthEnd]);
$records = $attStmt->fetchAll();

$attByDate = [];
foreach ($records as $r) {
    $attByDate[$r['attendance_date']] = $r;
}

// Fetch active holidays for the filtered year
$holidaysMap = get_active_holidays_map($pdo, (int)$filterYear);

$present = $late = $absent = $halfDay = $leave = $holiday = 0;
$totalLateMins = $totalHours = 0;

$calendarDays = [];
$todayStr = date('Y-m-d');
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = "$filterYear-" . str_pad($filterMonth, 2, '0', STR_PAD_LEFT) . "-" . str_pad($d, 2, '0', STR_PAD_LEFT);
    $row = $attByDate[$dateStr] ?? null;
    $res = resolve_daily_attendance_status($pdo, $empId, $dateStr, $row, $holidaysMap);
    $calendarDays[$d] = [
        'dateStr' => $dateStr,
        'dayName' => date('D', strtotime($dateStr)),
        'row'     => $row,
        'res'     => $res,
    ];

    if ($row && !in_array($row['status'] ?? '', ['absent', 'on_leave']) && !in_array($res['status_raw'] ?? '', ['absent', 'on_leave'])) {
        $totalLateMins += (int)($row['late_minutes'] ?? 0);
        $totalHours    += (float)($row['total_hours'] ?? 0);
    }

    $isInScope = ($filterYear < (int)date('Y')) || 
                 ($filterYear == (int)date('Y') && $filterMonth < (int)date('m')) || 
                 ($dateStr <= $todayStr);

    if ($isInScope) {
        $sRaw = $res['status_raw'];
        if ($sRaw === 'present') $present++;
        elseif ($sRaw === 'late') { $late++; $present++; }
        elseif ($sRaw === 'absent') $absent++;
        elseif ($sRaw === 'half_day') $halfDay++;
        elseif ($sRaw === 'on_leave') $leave++;
        elseif ($sRaw === 'holiday') $holiday++;
    }
}

$statusColors = [
    'present'        => ['bg'=>'#d1fae5','text'=>'#065f46','label'=>'P',  'name'=>'Present'],
    'late'           => ['bg'=>'#fef3c7','text'=>'#92400e','label'=>'L',  'name'=>'Late'],
    'absent'         => ['bg'=>'#fee2e2','text'=>'#991b1b','label'=>'A',  'name'=>'Absent'],
    'half_day'       => ['bg'=>'#e0f2fe','text'=>'#0369a1','label'=>'HD', 'name'=>'Half Day'],
    'on_leave'       => ['bg'=>'#ede9fe','text'=>'#5b21b6','label'=>'LV', 'name'=>'On Leave'],
    'work_from_home' => ['bg'=>'#dcfce7','text'=>'#166534','label'=>'W',  'name'=>'Work From Home'],
    'holiday'        => ['bg'=>'#fef3c7','text'=>'#b45309','label'=>'H',  'name'=>'Holiday / Sunday'],
];
?>

<div class="container-fluid px-0">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-calendar-check text-primary me-2"></i>My Attendance History</h3>
      <p class="text-muted mb-0 small">Review your punch-in timestamps, working hours, and monthly attendance</p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= BASE_URL ?>employee/dashboard.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Dashboard
      </a>
      <button class="btn btn-sm btn-outline-primary" onclick="window.print()">
        <i class="bi bi-printer me-1"></i> Print
      </button>
    </div>
  </div>

  <!-- Filter Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
    <div class="card-body py-3">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
          <label class="form-label small fw-semibold mb-1">Select Month</label>
          <select name="month" class="form-select form-select-sm">
            <?php for ($m=1; $m<=12; $m++): ?>
              <option value="<?= $m ?>" <?= $m == $filterMonth ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">Select Year</label>
          <select name="year" class="form-select form-select-sm">
            <?php for ($y=date('Y'); $y>=date('Y')-2; $y--): ?>
              <option value="<?= $y ?>" <?= $y == $filterYear ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-12 col-md-auto">
          <button class="btn btn-primary btn-sm w-100 w-md-auto"><i class="bi bi-funnel me-1"></i> View</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Monthly Stats Grid -->
  <div class="row g-2 g-md-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center h-100" style="border-radius:12px;">
        <div class="fw-bold fs-4 fs-sm-3 text-success"><?= $present ?> <span class="fs-6 text-muted fw-normal">/ <?= $daysInMonth ?></span></div>
        <div class="text-muted small">Present Days</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center h-100" style="border-radius:12px;">
        <div class="fw-bold fs-4 fs-sm-3 text-warning-emphasis"><?= $late ?></div>
        <div class="text-muted small">Late Days (<?= format_minutes_hms($totalLateMins, true) ?>)</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center h-100" style="border-radius:12px;">
        <div class="fw-bold fs-4 fs-sm-3 text-danger"><?= $absent ?></div>
        <div class="text-muted small">Absent Days</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center h-100" style="border-radius:12px;">
        <div class="fw-bold fs-4 fs-sm-3 text-primary"><?= format_hours_hms($totalHours, true) ?></div>
        <div class="text-muted small">Total Work (<?= number_format($totalHours, 1) ?> hrs)</div>
      </div>
    </div>
  </div>

  <!-- Calendar Strip -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
    <div class="card-header bg-white border-0 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h6 class="fw-bold mb-0 text-dark">
        <i class="bi bi-calendar3 me-2 text-primary"></i>
        <?= date('F Y', mktime(0,0,0,$filterMonth,1,$filterYear)) ?> Calendar
      </h6>
      <div class="d-flex gap-2 flex-wrap">
        <?php foreach ($statusColors as $k => $v): ?>
          <span style="background:<?= $v['bg'] ?>;color:<?= $v['text'] ?>;border-radius:4px;padding:0.15em 0.5em;font-size:0.68rem;font-weight:700;">
            <?= $v['label'] ?> = <?= $v['name'] ?? ucwords(str_replace('_',' ',$k)) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-body p-3">
      <div class="d-flex gap-2 overflow-x-auto pb-2 flex-nowrap align-items-stretch" style="-webkit-overflow-scrolling: touch; scrollbar-width: thin;">
        <?php foreach ($calendarDays as $d => $cData):
          $dateStr   = $cData['dateStr'];
          $dayName   = $cData['dayName'];
          $res       = $cData['res'];
          $isSun     = $res['is_sunday'];
          $isHol     = $res['is_holiday'];
          $statusRaw = $res['status_raw'] ?? 'absent';
          $c         = $statusColors[$statusRaw] ?? ['bg'=>'#f8fafc','text'=>'#94a3b8','label'=>$res['indicator'] ?? '·','name'=>$res['status']];
          $badgeCode = $res['indicator'] ?? $res['indicator_badge'] ?? ($c['label'] ?? '·');
          $tooltip   = "$dateStr: {$res['status']}" . (!empty($res['notes']) ? " ({$res['notes']})" : "");
          $isToday   = ($dateStr === $todayStr);
        ?>
        <div class="text-center flex-shrink-0 <?= $isToday ? 'position-relative' : '' ?>" style="width:42px; min-width:42px;">
          <div class="text-muted" style="font-size:0.65rem;"><?= $dayName ?></div>
          <div style="height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:700; background:<?= $c['bg'] ?>;color:<?= $c['text'] ?>;<?= (!empty($res['is_non_working'])) ? 'border:2px solid #fde68a;' : ($isToday ? 'border:2px solid #0d6efd;' : '') ?>"
               title="<?= e($tooltip) ?>">
            <?= $badgeCode ?>
          </div>
          <div style="font-size:0.68rem; color:<?= $isToday ? '#0d6efd; font-weight:bold;' : '#94a3b8;' ?>; margin-top:3px;">
            <?= $d ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="text-muted small mt-2 d-md-none" style="font-size:0.7rem;">
        <i class="bi bi-arrows-expand-vertical me-1"></i> Swipe horizontally to view full month timeline
      </div>
    </div>
  </div>

  <!-- Detailed Table -->
  <div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
      <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-list-check me-2 text-primary"></i>Daily Records</h6>
      <span class="text-muted small d-md-none" style="font-size:0.72rem;"><i class="bi bi-arrow-left-right me-1"></i>Scrollable</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem; min-width: 820px;">
          <thead class="table-light">
            <tr>
              <th class="ps-4 text-nowrap">Date</th>
              <th class="text-nowrap">Day</th>
              <th class="text-nowrap">Status</th>
              <th class="text-nowrap">Check-In</th>
              <th class="text-nowrap">Check-Out</th>
              <th class="text-nowrap">Break</th>
              <th class="text-nowrap">Work Hours</th>
              <th class="text-nowrap">Late Duration</th>
              <th class="pe-4 text-nowrap">Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($records)): ?>
              <tr><td colspan="9" class="text-center text-muted py-4">No attendance records found for this month.</td></tr>
            <?php else: ?>
              <?php foreach ($records as $r): 
                $resolved  = resolve_daily_attendance_status($pdo, $empId, $r['attendance_date'], $r, $holidaysMap);
                $statusRaw = $resolved['status_raw'] ?? 'absent';
                $sc        = $statusColors[$statusRaw] ?? ['bg'=>'#f1f5f9','text'=>'#64748b','label'=>$resolved['indicator'] ?? '·'];
              ?>
              <tr>
                <td class="ps-4 fw-semibold text-nowrap"><?= date('d M Y', strtotime($r['attendance_date'])) ?></td>
                <td class="text-muted text-nowrap"><?= date('l', strtotime($r['attendance_date'])) ?></td>
                <td class="text-nowrap">
                  <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border-radius:50rem;padding:.25em .8em;font-size:0.75rem;font-weight:600;">
                    <?= e($resolved['status']) ?>
                  </span>
                </td>
                <td class="text-nowrap"><?= $r['check_in'] ? date('h:i:s A', strtotime($r['check_in'])) : '—' ?></td>
                <td class="text-nowrap"><?= $r['check_out'] ? date('h:i:s A', strtotime($r['check_out'])) : '—' ?></td>
                <td class="text-nowrap">
                  <?php if (!empty($resolved['lunch_start'])): ?>
                    <span class="fw-semibold text-dark"><?= $resolved['lunch_duration'] ?></span>
                    <div class="text-muted" style="font-size:0.72rem;"><?= $resolved['lunch_start'] ?> - <?= $resolved['lunch_end'] ?? 'Active' ?></div>
                  <?php elseif ($resolved['lunch_seconds'] > 0): ?>
                    <span class="fw-semibold text-dark"><?= $resolved['lunch_duration'] ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap">
                  <?php if ($resolved['net_work_hours'] > 0): ?>
                    <span class="fw-semibold text-dark"><?= $resolved['net_work_duration'] ?></span>
                    <span class="text-muted small">(<?= number_format($resolved['net_work_hours'],1) ?>h)</span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap">
                  <?php if ($r['late_minutes'] > 0): ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i><?= format_minutes_hms($r['late_minutes'], true) ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="pe-4 text-muted small"><?= e($resolved['remarks'] ?: ($r['notes'] ?? '—')) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
