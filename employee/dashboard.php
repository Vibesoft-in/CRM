<?php
// employee/dashboard.php — Employee Dashboard
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/profile_handler.php';

$today = date('Y-m-d');
$empId = (int)($curEmp['id'] ?? 0);
if ($empId <= 0) {
    abort_forbidden("No employee profile linked to your account.");
}

// Handle Profile & Document Updates directly from Dashboard
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_action'] ?? '') === 'update_profile') {
    $res = handle_employee_profile_update($pdo, $empId, (int)($_SESSION['user_id'] ?? 0));
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || !empty($_POST['is_ajax']);
    if ($isAjax) {
        header('Content-Type: application/json');
        $refEmp = $pdo->prepare("SELECT resume_file, aadhaar_file, bank_acc_no FROM employees WHERE id = ?");
        $refEmp->execute([$empId]);
        $updEmp = $refEmp->fetch(PDO::FETCH_ASSOC) ?: [];
        echo json_encode([
            'success'          => !empty($res['success']),
            'message'          => $res['message'] ?? ($res['error'] ?? 'Profile updated'),
            'resume_uploaded'  => !empty($updEmp['resume_file']),
            'aadhaar_uploaded' => !empty($updEmp['aadhaar_file']),
            'bank_uploaded'    => !empty($updEmp['bank_acc_no'])
        ]);
        exit;
    }
    if ($res['success']) {
        set_flash($res['message'], "success");
    } else {
        set_flash($res['error'], "danger");
    }
    header('Location: ' . BASE_URL . 'employee/dashboard.php');
    exit;
}

// Auto-process daily attendance rules (5 PM cutoff & 8 PM checkout cutoff)
auto_process_daily_attendance($pdo, $today);

// 1. Fetch Today's Attendance
$attStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
$attStmt->execute([$empId, $today]);
$todayAtt = $attStmt->fetch();

// 1b. Check if today is a Holiday / Sunday or Scheduled Working Day
$todayDayCheck = is_date_holiday_or_sunday($today);
$isTodayNonWorking = !empty($todayDayCheck['is_non_working']);
$isTodayWorkingOverride = !empty($todayDayCheck['is_working_sunday']) || (!empty($todayDayCheck['reason']) && stripos($todayDayCheck['reason'], 'working') !== false);
$todayHolidayName = $todayDayCheck['holiday_name'] ?: ($todayDayCheck['reason'] ?: 'Holiday / Weekly Off');
$canTodayPunch = !$isTodayNonWorking;

// 2. Fetch Today's Work Report
$repStmt = $pdo->prepare("SELECT * FROM daily_work_reports WHERE employee_id = ? AND report_date = ?");
$repStmt->execute([$empId, $today]);
$todayRep = $repStmt->fetch();

// 3. Fetch Current Month Attendance Summary
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$curYear  = (int)date('Y');
$curMonth = (int)date('n');
$attSummary = get_employee_month_attendance_summary($pdo, (int)$empId, $curYear, $curMonth);

$monthSummary = [
    'present_days'     => $attSummary['present_days'],
    'late_days'        => $attSummary['late_days'],
    'absent_days'      => $attSummary['absent_days'],
    'half_days'        => $attSummary['half_days'],
    'leave_days'       => $attSummary['leave_days'],
    'total_late_min'   => $attSummary['total_late_mins'],
    'total_work_hours' => $attSummary['total_work_hours'],
    'daily_rows'       => $attSummary['daily_rows'],
];

// 4. Fetch Latest Payroll & Salary Slip
$payStmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? ORDER BY payroll_year DESC, payroll_month DESC LIMIT 1");
$payStmt->execute([$empId]);
$latestPayroll = $payStmt->fetch();

// 5. Fetch Real Leave Balance & Quotas (Centralized 4 Days allowed)
$leaveSummary = get_employee_annual_leave_summary($pdo, $empId, (int)date('Y'));
$totalAllowedLeave = $leaveSummary['allowed_leaves'];
$usedLeave = $leaveSummary['total_taken'];
$availableLeave = $leaveSummary['remaining_allowed'];
$extraLeave = $leaveSummary['extra_leaves'];

// 5b. Fetch Real Lunch Break Sessions & Working Hours Summary (7h target base)
$activeLunch = get_active_lunch_session($pdo, $empId, $today);
$isOnLunch = !empty($activeLunch);
$totalLunchSec = get_today_lunch_duration_seconds($pdo, $empId, $today);
$hoursSummary = calculate_working_hours_summary($pdo, $empId, $today);

// 6. Recent Notifications
$notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 4");
$notifs->execute([$_SESSION['user_id']]);
$recentNotifs = $notifs->fetchAll();

// 7. Attendance & Office Geofencing Settings
$setStmt = $pdo->query("SELECT * FROM attendance_settings LIMIT 1");
$attSettings = $setStmt ? $setStmt->fetch() : null;
if (!$attSettings) {
    $attSettings = [
        'expected_check_in' => '10:00:00',
        'expected_check_out' => '19:00:00',
        'grace_minutes' => 15,
        'geofence_enabled' => 1,
        'office_latitude' => 29.22006923,
        'office_longitude' => 78.96778362,
        'geofence_radius_meters' => 200,
        'office_address' => 'Vibesoft, Chamunda Vihar Phase 1, Ramnagar Road, Kashipur, Uttarakhand 244713'
    ];
}

// 8. Profile Completion & Avatar
$profileScore = calculate_profile_completion($curEmp);
$empPhotoUrl = (!empty($curEmp['profile_photo']) && file_exists(__DIR__ . '/../' . $curEmp['profile_photo'])) 
    ? BASE_URL . e($curEmp['profile_photo']) 
    : "https://ui-avatars.com/api/?name=" . urlencode($curEmp['full_name'] ?? 'User') . "&background=ffffff&color=0d6efd&size=160&bold=true";

$empFirstName = !empty($curEmp['first_name']) ? $curEmp['first_name'] : (explode(' ', trim($curEmp['full_name'] ?? ''))[0] ?? '');
$empLastName  = !empty($curEmp['last_name']) ? $curEmp['last_name'] : trim(substr(trim($curEmp['full_name'] ?? ''), strlen($empFirstName)));
?>

<div class="container-fluid px-0">

  <!-- Welcome & Profile Spotlight Hero Banner -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:16px; background:linear-gradient(135deg, #1e3a8a 0%, #0d6efd 60%, #0284c7 100%); color:#fff; overflow:hidden; position:relative;">
    <div style="position:absolute; top:-30px; right:-30px; width:220px; height:220px; background:radial-gradient(circle, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%); border-radius:50%; pointer-events:none;"></div>
    
    <div class="card-body p-3 p-md-4">
      <div class="row align-items-center g-3">
        <!-- Avatar & Basic Info -->
        <div class="col-lg-7">
          <div class="d-flex align-items-center gap-3">
            <div class="position-relative flex-shrink-0">
              <img src="<?= $empPhotoUrl ?>" 
                   alt="<?= e($curEmp['full_name']) ?>" 
                   class="rounded-circle shadow border border-3 border-white object-fit-cover" 
                   style="width:68px; height:68px; object-fit:cover; background:#fff;">
              <a href="<?= BASE_URL ?>employee/profile.php" 
                 class="btn btn-sm btn-light rounded-circle shadow-sm position-absolute bottom-0 end-0 p-0 d-flex align-items-center justify-content-center text-decoration-none" 
                 style="width:24px; height:24px;" 
                 title="Update Photo & Profile">
                <i class="bi bi-camera-fill text-primary" style="font-size:0.7rem;"></i>
              </a>
            </div>

            <div class="min-w-0 flex-grow-1 overflow-hidden">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="badge bg-white text-primary fw-bold px-2 py-1 rounded-pill" style="font-size:0.7rem; letter-spacing:0.04em;">
                  <?= e($curEmp['employee_code'] ?? 'EMP') ?>
                </span>
                <span class="badge bg-white bg-opacity-25 text-white fw-normal px-2 py-1 rounded-pill" style="font-size:0.7rem;">
                  <?= e($curEmp['department'] ?? 'Team') ?>
                </span>
              </div>
              <h3 class="fw-bold mb-0 text-white fs-4 fs-sm-3 text-truncate"><?= get_time_greeting() ?>, <?= e($curEmp['first_name'] ?? $curEmp['full_name'] ?? 'Team Member') ?>! 👋</h3>
              <p class="mb-0 text-white-50 small mt-1 text-break" style="font-size:0.78rem;">
                <i class="bi bi-briefcase me-1 text-white"></i><?= e($curEmp['designation'] ?? 'Specialist') ?> · <?= e($curEmp['email'] ?? '') ?>
              </p>
            </div>
          </div>
        </div>

        <!-- Quick Actions & Clock -->
        <div class="col-lg-5 text-start text-lg-end mt-3 mt-lg-0">
          <div class="d-flex flex-wrap flex-sm-nowrap justify-content-start justify-content-lg-end align-items-center gap-2">
            <div class="d-inline-flex align-items-center px-3 py-2 rounded-pill shadow-sm" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.22), rgba(255, 255, 255, 0.08)) !important; backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.35) !important; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.3) !important;">
              <i class="bi bi-clock-history me-2 fs-6 text-white" style="opacity: 0.95;"></i>
              <span id="liveClock" class="fw-bold text-white" style="letter-spacing: 0.02em; font-size: 0.88rem;"><?= date('h:i:s A') ?></span>
            </div>

            <?php if ($todayAtt && empty($todayAtt['check_out'])): ?>
              <div id="topBannerBreakBtnWrap">
                <?php if ($isOnLunch): ?>
                  <button type="button" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm rounded-pill d-inline-flex align-items-center gap-2" onclick="openLunchConfirmModal('end')">
                    <i class="bi bi-play-circle-fill"></i>
                    <span>End Break</span>
                  </button>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div id="topBannerBreakBtnWrap"></div>
            <?php endif; ?>
            
            <a href="<?= BASE_URL ?>employee/profile.php" class="btn btn-light text-primary fw-bold px-3 py-2 shadow-sm rounded-pill d-inline-flex align-items-center justify-content-center gap-2 text-decoration-none">
              <i class="bi bi-pencil-square"></i>
              <span>Edit Profile</span>
            </a>
          </div>

          <!-- Profile Completion Meter -->
          <div class="mt-3 bg-black bg-opacity-20 p-2 px-3 rounded-3 border border-white border-opacity-10 text-start text-sm-end">
            <div class="d-flex justify-content-between align-items-center small mb-1">
              <span class="text-white-50"><i class="bi bi-shield-check text-warning me-1"></i>Profile Completion</span>
              <span class="fw-bold text-white"><?= $profileScore['percentage'] ?>%</span>
            </div>
            <div class="progress" style="height: 6px; background: rgba(255,255,255,0.2); border-radius:10px;">
              <div class="progress-bar <?= $profileScore['percentage'] >= 100 ? 'bg-success' : ($profileScore['percentage'] >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                   role="progressbar" 
                   style="width: <?= $profileScore['percentage'] ?>%;"></div>
            </div>
            <?php if (!$profileScore['is_complete']): ?>
              <div class="text-white-50 mt-1 d-flex align-items-center justify-content-between" style="font-size:0.72rem;">
                <span>Missing: <?= e($profileScore['missing'][0]['label'] ?? 'Details') ?></span>
                <a href="<?= BASE_URL ?>employee/profile.php?tab=docs" class="text-white fw-semibold text-decoration-underline">Complete Now &rarr;</a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Flash Messages -->
  <?php $flash = get_flash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-info-circle-fill me-2"></i><?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Action Modules Row: Today's Attendance & Today's Work Report -->
  <div class="row g-3 g-md-4 mb-4">
    <!-- Today's Attendance Card -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
        <div class="card-body p-3 p-md-4 d-flex flex-column justify-content-between">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="fw-bold text-dark mb-0">
                <i class="bi bi-calendar-check text-primary me-2"></i>Today's Attendance
              </h5>
              <div id="todayAttBadgeWrap">
                <?php 
                  $isTodayAbsentOrLeave = ($todayAtt && in_array($todayAtt['status'], ['absent', 'on_leave']));
                ?>
                <?php if ($todayAtt): ?>
                  <?php if ($todayAtt['status'] === 'present'): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge">
                      <i class="bi bi-check-circle-fill me-1"></i>Present
                    </span>
                  <?php elseif ($todayAtt['status'] === 'late'): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge">
                      <i class="bi bi-exclamation-triangle-fill me-1"></i>Late Arrival
                    </span>
                  <?php elseif ($todayAtt['status'] === 'absent'): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge">
                      <i class="bi bi-x-circle-fill me-1"></i>Absent
                    </span>
                  <?php elseif ($todayAtt['status'] === 'on_leave'): ?>
                    <span class="badge bg-info-subtle text-info border border-info-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge">
                      <i class="bi bi-calendar-event me-1"></i>On Leave
                    </span>
                  <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge">
                      <?= ucfirst($todayAtt['status']) ?>
                    </span>
                  <?php endif; ?>
                <?php else: ?>
                  <?php if ($isTodayNonWorking): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge">
                      <i class="bi bi-calendar-x-fill me-1"></i>Holiday: <?= e($todayHolidayName) ?>
                    </span>
                  <?php elseif ($isTodayWorkingOverride): ?>
                    <span class="badge bg-info-subtle text-info border border-info-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge">
                      <i class="bi bi-briefcase-fill me-1"></i>Working Day (Scheduled)
                    </span>
                  <?php else: ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge">
                      <i class="bi bi-dash-circle me-1"></i>Not Marked Yet
                    </span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

            <div id="attendanceInfoWrap">
              <?php if ($isTodayAbsentOrLeave): ?>
                <div class="alert <?= ($todayAtt['status'] === 'absent') ? 'alert-danger border-danger-subtle' : 'alert-info border-info-subtle' ?> py-3 px-3 rounded-3 mb-3">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi <?= ($todayAtt['status'] === 'absent') ? 'bi-shield-slash-fill text-danger' : 'bi-umbrella-fill text-info' ?> fs-5"></i>
                    <strong class="text-dark"><?= ($todayAtt['status'] === 'absent') ? 'Marked Absent for Today' : 'On Leave for Today' ?></strong>
                  </div>
                  <p class="small text-muted mb-0">
                    <?= ($todayAtt['status'] === 'absent') ? 'You have been marked absent by Admin. Working hours recorded: <strong>0h 00m</strong>.' : 'Your leave has been recorded. Working hours for today: <strong>0h 00m</strong>.' ?>
                  </p>
                </div>
              <?php elseif (!$todayAtt && $isTodayNonWorking): ?>
                <div class="alert alert-warning py-3 px-3 rounded-3 mb-3 border-warning-subtle" id="todayHolidayAlert">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-calendar-x-fill text-warning fs-5"></i>
                    <strong class="text-dark">Office Closed Today (<?= e($todayHolidayName) ?>)</strong>
                  </div>
                  <p class="small text-muted mb-0">
                    Today is marked as an official Holiday / Weekly Off. Attendance marking is closed for today.
                  </p>
                </div>
              <?php else: ?>
                <?php if (!$todayAtt && $isTodayWorkingOverride): ?>
                  <div class="alert alert-info py-2 px-3 rounded-3 mb-3 border-info-subtle small" id="todayWorkingOverrideAlert">
                    <div class="d-flex align-items-center gap-2">
                      <i class="bi bi-briefcase-fill text-info fs-5"></i>
                      <div>
                        <strong class="text-dark">Scheduled Working Day:</strong>
                        <span class="text-muted">Admin has marked today as a normal working day (<?= e($todayDayCheck['reason'] ?: 'Working Day') ?>). You can mark your attendance now.</span>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
                <div id="attendanceNotClockedInMsg" class="mb-3" style="<?= $todayAtt ? 'display:none;' : '' ?>">
                  <p class="text-muted small mb-0">
                    Office shift starts at 10:00 AM (7-Hour Daily Target). Click below to punch in your morning attendance for today.
                  </p>
                </div>

                <div class="bg-light p-3 rounded-3 mb-3 border" id="attendanceStatsBlock" style="<?= $todayAtt ? '' : 'display:none;' ?>">
                <div class="row g-2 text-center align-items-start pb-1">
                  <div class="col-4 border-end">
                    <div class="text-muted small" style="font-size:0.72rem;">Punch In Time</div>
                    <div class="fw-bold fs-6 text-dark" id="punchInDisplay">
                      <?= ($todayAtt && $todayAtt['check_in']) ? date('h:i A', strtotime($todayAtt['check_in'])) : '—' ?>
                    </div>
                  </div>
                  <div class="col-4 border-end">
                    <div class="text-muted small" style="font-size:0.72rem;">Punch Out Time</div>
                    <div class="fw-bold fs-6 text-dark" id="punchOutDisplay">
                      <?= ($todayAtt && $todayAtt['check_out']) ? date('h:i A', strtotime($todayAtt['check_out'])) : '<span class="text-primary fs-6 fw-semibold">In Progress</span>' ?>
                    </div>
                  </div>
                  <div class="col-4">
                    <div class="text-muted small" style="font-size:0.72rem;">Break</div>
                    <div class="fw-bold fs-6 <?= $isOnLunch ? 'text-warning' : 'text-dark' ?>" id="punchLunchDisplay">
                      <?= format_minutes_hm((int)round($totalLunchSec / 60)) ?>
                    </div>
                    <div class="text-muted" style="font-size:0.68rem; line-height:1.2; word-break:break-word;" id="punchLunchSubtext">
                      <?php if (!empty($hoursSummary['lunch_start'])): ?>
                        <?= $hoursSummary['lunch_start'] ?> - <?= $hoursSummary['lunch_end'] ?? 'In Progress' ?>
                      <?php elseif ($isOnLunch && $activeLunch): ?>
                        Started <?= date('h:i A', strtotime($activeLunch['start_time'])) ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="mt-3 text-center text-primary small fw-semibold border-top pt-2" id="liveWorkTimerWrap" style="<?= ($todayAtt && $todayAtt['check_in'] && empty($todayAtt['check_out'])) ? '' : 'display:none;' ?>; clear:both;">
                  <?php if ($isOnLunch): ?>
                    <span class="badge bg-warning text-dark px-3 py-1-5 text-wrap text-break shadow-sm" style="font-size: 0.8rem; font-weight:600; line-height:1.35; white-space: normal !important; max-width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                      <i class="bi bi-cup-hot-fill me-1"></i>On Break since <?= date('h:i A', strtotime($activeLunch['start_time'])) ?> (Timer Paused)
                    </span>
                  <?php else: ?>
                    <i class="bi bi-stopwatch me-1"></i> Net Working Today: <span id="liveWorkTimer" class="fw-bold">Calculating...</span>
                  <?php endif; ?>
                </div>

                <?php if ($todayAtt && $todayAtt['late_minutes'] > 0): ?>
                  <div class="mt-2 text-center text-warning-emphasis small fw-medium" id="lateArrivalNotice" style="font-size:0.75rem;">
                    <i class="bi bi-clock-history me-1"></i> Arrived <?= format_minutes_hms($todayAtt['late_minutes']) ?> after office grace time.
                  </div>
                <?php else: ?>
                  <div id="lateArrivalNotice" style="display:none;"></div>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <div>
            <!-- Office Geofence Live Status Box -->
            <div class="p-2 px-3 mb-3 rounded-3 bg-light border d-flex align-items-center justify-content-between" id="geoStatusBox" style="transition: all 0.3s; <?= ($isTodayAbsentOrLeave || (!$todayAtt && $isTodayNonWorking)) ? 'display:none!important;' : '' ?>">
              <div class="d-flex align-items-center gap-2">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white border" style="width: 24px; height: 24px;" id="geoStatusIconWrap">
                  <i class="bi bi-geo-alt-fill text-primary" id="geoStatusIcon" style="font-size: 0.8rem;"></i>
                </span>
                <div>
                  <div class="fw-semibold small d-flex align-items-center" id="geoStatusTitle">Verifying Office Location...</div>
                  <div class="text-muted" style="font-size:0.72rem;" id="geoStatusSubtitle">Live GPS required to punch attendance</div>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 fw-semibold" id="geoRetryBtn" onclick="initGeoLocation(true)">
                <i class="bi bi-crosshair me-1"></i>Detect
              </button>
            </div>

            <!-- Location Warning Banner (Shown on Close or when Location is Off) -->
            <div class="alert alert-danger py-2 px-3 small fw-semibold mb-3" id="locationWarningBanner" style="display:none; border-radius:10px;">
              <i class="bi bi-exclamation-octagon-fill me-1"></i> Aap bina location open kare attendance nahi laga sakte.
            </div>

            <div id="attendanceActionContainer">
              <?php if ($isTodayAbsentOrLeave): ?>
                <div class="p-2 px-3 rounded-3 bg-light border text-center text-muted small">
                  <i class="bi bi-shield-lock-fill me-1"></i> Actions disabled & working hours set to 0h 00m for <strong><?= ucfirst($todayAtt['status']) ?></strong> status.
                </div>
              <?php else: ?>
                <?php 
                  $nowMin = ((int)date('G') * 60) + (int)date('i');
                  $isPast5PM = ($nowMin >= 1020);
                  $isPast8PM = ($nowMin >= 1200);
                ?>
                <?php if (!$todayAtt): ?>
                  <?php if ($isTodayNonWorking): ?>
                    <div class="p-3 mb-2 rounded-3 bg-warning-subtle border border-warning-subtle text-center text-dark small fw-semibold" id="holidayPunchNotice">
                      <i class="bi bi-calendar-x-fill text-warning me-1"></i> Today is a Holiday (<?= e($todayHolidayName) ?>). Attendance punch is closed.
                    </div>
                  <?php elseif ($isPast5PM): ?>
                    <div class="alert alert-danger py-2 mb-2 text-center small fw-semibold">
                      <i class="bi bi-x-circle me-1"></i> Check-in closed at 5:00 PM (Auto-marked Absent)
                    </div>
                  <?php else: ?>
                    <button class="btn btn-primary w-100 py-2 fw-semibold shadow-sm mb-2" id="btnPunchIn" onclick="markAttendanceInstant()">
                      <i class="bi bi-fingerprint me-2 fs-5"></i> Mark Attendance Now
                    </button>
                  <?php endif; ?>

                  <!-- Break status when not punched in -->
                  <div class="p-2 px-3 rounded-3 bg-light border d-flex align-items-center justify-content-between text-muted">
                    <div class="d-flex align-items-center gap-2">
                      <i class="bi bi-cup-hot fs-5 text-secondary"></i>
                      <div>
                        <div class="fw-semibold small text-secondary">Break</div>
                        <div style="font-size:0.72rem;">Available after morning punch-in</div>
                      </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-3 fw-semibold" disabled title="Check in first to start break">
                      <i class="bi bi-lock me-1"></i>Start Break
                    </button>
                  </div>

                <?php elseif (empty($todayAtt['check_out'])): ?>
                  <?php if ($isPast8PM): ?>
                    <div class="alert alert-warning py-2 mb-0 text-center small fw-semibold">
                      <i class="bi bi-clock-history me-1"></i> Punch out closed at 8:00 PM (Auto-marked Half Day)
                    </div>
                  <?php else: ?>
                    <?php if ($isOnLunch): ?>
                      <!-- Active Break Panel -->
                      <div class="p-3 mb-2 rounded-3 border border-warning shadow-sm" style="background: #fffbeb;" id="activeBreakPanel">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                          <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-circle bg-warning p-1"><i class="bi bi-cup-hot-fill text-dark"></i></span>
                            <span class="fw-bold text-dark small">On Break</span>
                          </div>
                          <span class="badge bg-warning text-dark fw-bold">Since <?= !empty($activeLunch['start_time']) ? date('h:i A', strtotime($activeLunch['start_time'])) : 'Now' ?></span>
                        </div>
                        <div class="text-muted small mb-2" style="font-size:0.75rem;">Working hours timer is paused. Tap below when ready to resume your shift.</div>
                        <button class="btn btn-success text-white w-100 py-2 fw-bold shadow-sm" onclick="openLunchConfirmModal('end')">
                          <i class="bi bi-play-circle-fill me-2 fs-5 align-middle"></i> Resume Work (End Break)
                        </button>
                      </div>
                      <button class="btn btn-outline-secondary w-100 py-1 small fw-semibold" onclick="checkOutInstant()">
                        <i class="bi bi-box-arrow-right me-1"></i> Punch Out for Evening
                      </button>
                    <?php else: ?>
                      <div class="d-flex gap-2" id="shiftActionButtons">
                        <button class="btn btn-outline-warning w-50 py-2 fw-semibold" onclick="openLunchConfirmModal('start')">
                          <i class="bi bi-cup-hot me-1"></i> Break
                        </button>
                        <button class="btn btn-outline-primary w-50 py-2 fw-semibold" onclick="checkOutInstant()">
                          <i class="bi bi-box-arrow-right me-1"></i> Punch Out
                        </button>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="alert alert-success py-2 mb-0 text-center small fw-semibold">
                    <i class="bi bi-check2-all me-1"></i> Attendance Complete: <?= format_hours_hms($todayAtt['total_hours']) ?> worked
                    <?php if ($totalLunchSec > 0): ?>
                      · <?= format_minutes_hm((int)round($totalLunchSec / 60)) ?> break deducted
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Today's Work Report Card -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
        <div class="card-body p-3 p-md-4 d-flex flex-column justify-content-between">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="fw-bold text-dark mb-0">
                <i class="bi bi-journal-text text-success me-2"></i>Evening Work Report
              </h5>
              <?php if ($todayRep): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-semibold">
                  <i class="bi bi-check-circle-fill me-1"></i>Submitted
                </span>
              <?php elseif ($isTodayAbsentOrLeave): ?>
                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-1 rounded-pill fw-semibold">
                  <i class="bi bi-dash-circle me-1"></i>Not Applicable
                </span>
              <?php else: ?>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill fw-semibold">
                  <i class="bi bi-clock me-1"></i>Pending
                </span>
              <?php endif; ?>
            </div>

            <?php if ($todayRep): ?>
              <div class="bg-light p-3 rounded-3 mb-3 border">
                <div class="text-muted small mb-1" style="font-size:0.72rem;">Tasks Completed:</div>
                <div class="text-dark small fw-medium mb-2"><?= nl2br(e($todayRep['tasks_completed'])) ?></div>
                <div class="text-muted small mb-1" style="font-size:0.72rem;">Projects Worked On:</div>
                <div class="text-dark small fw-medium mb-2"><?= e($todayRep['projects_worked'] ?? 'General') ?></div>
                <div class="d-flex justify-content-between text-muted small" style="font-size:0.75rem;">
                  <span>Submitted At: <?= date('h:i A', strtotime($todayRep['created_at'])) ?></span>
                  <span class="fw-bold text-success"><?= $todayRep['hours_worked'] ?> hrs worked</span>
                </div>
              </div>
            <?php elseif ($isTodayAbsentOrLeave): ?>
              <div class="alert alert-light border small text-muted py-3 px-3 rounded-3 mb-3">
                <i class="bi bi-info-circle-fill text-info me-1"></i> Evening work report is not required today because your status is marked as <strong><?= ucfirst($todayAtt['status']) ?></strong> (0h worked).
              </div>
            <?php else: ?>
              <p class="text-muted small mb-3">
                Evening work reports are mandatory before leaving. Document your completed tasks and project contributions for today.
              </p>
            <?php endif; ?>
          </div>

          <div>
            <?php if ($todayRep): ?>
              <a href="<?= BASE_URL ?>employee/work-reports.php" class="btn btn-outline-success w-100 py-2 fw-semibold">
                <i class="bi bi-eye me-1"></i> View All Reports
              </a>
            <?php elseif ($isTodayAbsentOrLeave): ?>
              <a href="<?= BASE_URL ?>employee/work-reports.php" class="btn btn-outline-secondary w-100 py-2 fw-semibold">
                <i class="bi bi-journal-text me-1"></i> View Past Work Reports
              </a>
            <?php else: ?>
              <a href="<?= BASE_URL ?>employee/work-reports.php?action=create" class="btn btn-success text-white w-100 py-2 fw-semibold shadow-sm">
                <i class="bi bi-pencil-square me-1"></i> Submit Work Report Now
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Working Hours & Shift Targets Summary (7h Daily Base) -->
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h6 class="fw-bold text-dark text-uppercase small mb-0" style="letter-spacing:0.05em;">
      <i class="bi bi-bullseye me-2 text-primary"></i> Working Hours & Shift Targets (7h / Day)
    </h6>
    <span class="badge bg-light text-muted border small">Sundays & Festivals Excluded</span>
  </div>

  <div class="row g-2 g-sm-3 mb-4">
    <!-- Daily Target: Hours only -->
    <?php
      $dTarget = $hoursSummary['daily']['target_formatted'] ?? '7h 00m';
      $dWorked = $hoursSummary['daily']['worked_formatted'] ?? '0h 00m';
      $dIsOff = !empty($hoursSummary['daily']['is_sunday_or_holiday']);
      $dDayType = $hoursSummary['daily']['day_type'] ?? ($dIsOff ? 'Weekly Off' : 'Working Day');
      $dDiff = (int)($hoursSummary['daily']['diff_minutes'] ?? $hoursSummary['daily']['difference_minutes'] ?? 0);
      $dDiffFmt = $hoursSummary['daily']['diff_formatted'] ?? $hoursSummary['daily']['difference_formatted'] ?? '0m';
    ?>
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:14px; border-left: 4px solid #0284c7 !important;">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="text-muted small fw-semibold" style="font-size:0.75rem;">Today (Daily)</span>
          <span class="badge bg-light text-dark border" id="cardTodayTarget" style="font-size:0.68rem;">Target: <?= e($dTarget) ?></span>
        </div>
        <div class="fw-bold fs-5 text-dark mt-1" id="cardTodayWorked">Worked: <?= e($dWorked) ?></div>
        <div class="d-flex align-items-center gap-1 mt-1 small" id="cardTodayDiff" style="font-size:0.75rem;">
          <?php if ($dIsOff): ?>
            <span class="text-muted text-truncate"><i class="bi bi-moon-stars me-1 text-warning"></i><?= e($dDayType) ?></span>
          <?php elseif ($dDiff >= 0): ?>
            <span class="text-success fw-semibold text-truncate"><i class="bi bi-arrow-up-circle-fill me-1"></i><?= e($dDiffFmt) ?> (Ahead)</span>
          <?php else: ?>
            <span class="text-danger fw-semibold text-truncate"><i class="bi bi-arrow-down-circle-fill me-1"></i><?= e($dDiffFmt) ?> (Shortfall)</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Weekly Target: Hours + Days -->
    <?php
      $wTarget = $hoursSummary['weekly']['target_formatted'] ?? '42h 00m';
      $wWorked = $hoursSummary['weekly']['worked_formatted'] ?? '0h 00m';
      $wDiff = (int)($hoursSummary['weekly']['diff_minutes'] ?? $hoursSummary['weekly']['difference_minutes'] ?? 0);
      $wDiffFmt = $hoursSummary['weekly']['diff_formatted'] ?? $hoursSummary['weekly']['difference_formatted'] ?? '0m';
      $wDaysFmt = $hoursSummary['weekly']['days_formatted'] ?? '0 / 6';
    ?>
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:14px; border-left: 4px solid #10b981 !important;">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="text-muted small fw-semibold" style="font-size:0.75rem;">This Week</span>
          <span class="badge bg-light text-dark border" id="cardWeekTarget" style="font-size:0.68rem;">Target: <?= e($wTarget) ?></span>
        </div>
        <div class="fw-bold fs-5 text-dark mt-1" id="cardWeekWorked">Worked: <?= e($wWorked) ?></div>
        <div class="d-flex align-items-center justify-content-between gap-1 mt-1 small" style="font-size:0.75rem;">
          <span class="badge bg-success-subtle text-success border border-success-subtle" id="cardWeekDays">Days: <?= e($wDaysFmt) ?></span>
          <span id="cardWeekDiff">
            <?php if ($wDiff >= 0): ?>
              <span class="text-success fw-semibold text-truncate"><i class="bi bi-arrow-up-circle-fill me-1"></i><?= e($wDiffFmt) ?></span>
            <?php else: ?>
              <span class="text-danger fw-semibold text-truncate"><i class="bi bi-arrow-down-circle-fill me-1"></i><?= e($wDiffFmt) ?></span>
            <?php endif; ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Monthly Target: Days only -->
    <?php
      $mTargetDays = $hoursSummary['monthly']['working_days'] ?? 25;
      $mDaysFmt = $hoursSummary['monthly']['days_formatted'] ?? ((int)($monthSummary['present_days'] ?? 0) . " / $mTargetDays");
    ?>
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:14px; border-left: 4px solid #8b5cf6 !important;">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="text-muted small fw-semibold" style="font-size:0.75rem;"><?= date('F') ?> Target</span>
          <span class="badge bg-light text-dark border" id="cardMonthTarget" style="font-size:0.68rem;">Target: <?= $mTargetDays ?> Days</span>
        </div>
        <div class="fw-bold fs-5 text-dark mt-1" id="cardMonthDays">Days: <?= e($mDaysFmt) ?></div>
        <div class="d-flex align-items-center gap-1 mt-1 small text-muted" style="font-size:0.75rem;">
          <i class="bi bi-calendar-check me-1 text-primary"></i> Monthly shift attendance
        </div>
      </div>
    </div>

    <!-- Yearly Target: Days only -->
    <?php
      $yTargetDays = $hoursSummary['yearly']['working_days'] ?? 309;
      $yDaysFmt = $hoursSummary['yearly']['days_formatted'] ?? "0 / $yTargetDays";
    ?>
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:14px; border-left: 4px solid #f59e0b !important;">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="text-muted small fw-semibold" style="font-size:0.75rem;">Year <?= date('Y') ?></span>
          <span class="badge bg-light text-dark border" id="cardYearTarget" style="font-size:0.68rem;">Target: <?= $yTargetDays ?> Days</span>
        </div>
        <div class="fw-bold fs-5 text-dark mt-1" id="cardYearDays">Year <?= date('Y') ?> Days: <?= e($yDaysFmt) ?></div>
        <div class="d-flex align-items-center gap-1 mt-1 small text-muted" style="font-size:0.75rem;">
          <i class="bi bi-calendar3 me-1 text-warning"></i> Annual shift attendance
        </div>
      </div>
    </div>
  </div>

  <!-- Monthly Stats Cards -->
  <h6 class="fw-bold text-dark text-uppercase small mb-3" style="letter-spacing:0.05em;">
    <i class="bi bi-graph-up me-2 text-primary"></i> <?= date('F Y') ?> Overview
  </h6>

  <div class="row g-2 g-sm-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-2 p-sm-3 h-100" style="border-radius:12px;">
        <div class="d-flex align-items-center gap-2 gap-sm-3">
          <div class="bg-success-subtle text-success rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:42px; height:42px;">
            <i class="bi bi-person-check fs-5"></i>
          </div>
          <div class="min-w-0 flex-grow-1 overflow-hidden">
            <div class="text-muted small text-truncate" style="font-size:0.75rem;">Present Days</div>
            <div class="fw-bold fs-5 fs-sm-4 text-dark lh-1 mt-1" id="cardPresentDays">
              <?= (int)($monthSummary['present_days'] ?? 0) ?> <span class="fs-6 text-muted fw-normal">/ <?= date('t') ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-2 p-sm-3 h-100" style="border-radius:12px;">
        <div class="d-flex align-items-center gap-2 gap-sm-3">
          <div class="bg-warning-subtle text-warning-emphasis rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:42px; height:42px;">
            <i class="bi bi-clock-history fs-5"></i>
          </div>
          <div class="min-w-0 flex-grow-1 overflow-hidden">
            <div class="text-muted small text-truncate" style="font-size:0.75rem;">Late Arrivals</div>
            <div class="fw-bold fs-5 fs-sm-4 text-dark lh-1 mt-1"><?= (int)($monthSummary['late_days'] ?? 0) ?></div>
            <div class="text-muted text-truncate" style="font-size:0.7rem;"><?= format_minutes_hms($monthSummary['total_late_min'] ?? 0, true) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-2 p-sm-3 h-100" style="border-radius:12px;">
        <div class="d-flex align-items-center gap-2 gap-sm-3">
          <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:42px; height:42px;">
            <i class="bi bi-hourglass-split fs-5"></i>
          </div>
          <div class="min-w-0 flex-grow-1 overflow-hidden">
            <div class="text-muted small text-truncate" style="font-size:0.75rem;">Work Hours</div>
            <div class="fw-bold fs-6 fs-sm-5 text-dark lh-1 mt-1 text-truncate"><?= format_hours_hms($monthSummary['total_work_hours'] ?? 0, true) ?></div>
            <div class="text-muted text-truncate" style="font-size:0.7rem;"><?= number_format((float)($monthSummary['total_work_hours'] ?? 0), 1) ?> hrs total</div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-2 p-sm-3 h-100" style="border-radius:12px;">
        <div class="d-flex align-items-center gap-2 gap-sm-3">
          <div class="bg-info-subtle text-info rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:42px; height:42px;">
            <i class="bi bi-calendar2-heart fs-5"></i>
          </div>
          <div class="min-w-0 flex-grow-1 overflow-hidden">
            <div class="text-muted small text-truncate" style="font-size:0.75rem;">Leave Balance</div>
            <div class="fw-bold fs-5 fs-sm-4 text-dark lh-1 mt-1"><?= $availableLeave ?> <span class="small fw-normal text-muted" style="font-size:0.7rem;">/ <?= $totalAllowedLeave ?> Allowed</span></div>
            <?php if ($extraLeave > 0): ?>
              <div class="text-danger text-truncate fw-semibold" style="font-size:0.68rem;">+<?= $extraLeave ?> Extra (Unpaid)</div>
            <?php else: ?>
              <div class="text-muted text-truncate" style="font-size:0.68rem;"><?= $usedLeave ?> Taken this year</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Salary & Quick Actions Row -->
  <div class="row g-3 g-md-4 mb-4">
    <!-- Salary Snapshot Card -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
        <div class="card-body p-3 p-md-4">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold text-dark mb-0">
              <i class="bi bi-currency-rupee text-success me-1"></i>Salary Information
            </h5>
            <a href="<?= BASE_URL ?>employee/salary.php" class="small text-decoration-none fw-semibold">View All</a>
          </div>

          <div class="p-3 bg-light rounded-3 border mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="text-muted small">Base Monthly Salary:</span>
              <span class="fw-bold text-dark fs-5">₹<?= number_format((float)($curEmp['base_salary'] ?? 0), 2) ?></span>
            </div>
            <?php if ($latestPayroll): ?>
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted small">Last Payout (<?= date('F Y', mktime(0,0,0, $latestPayroll['payroll_month'], 1, $latestPayroll['payroll_year'])) ?>):</span>
                <span class="fw-semibold text-success">₹<?= number_format((float)$latestPayroll['net_salary'], 2) ?></span>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted small">Status:</span>
                <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $latestPayroll['status'] ?></span>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($latestPayroll): ?>
            <a href="<?= BASE_URL ?>employee/download-salary-slip.php?id=<?= $latestPayroll['id'] ?>" class="btn btn-outline-success btn-sm w-100 fw-semibold" target="_blank">
              <i class="bi bi-download me-1"></i> Download Salary Slip (<?= date('M Y', mktime(0,0,0, $latestPayroll['payroll_month'], 1, $latestPayroll['payroll_year'])) ?>)
            </a>
          <?php else: ?>
            <div class="text-muted small text-center py-1">Payroll snapshot will be available after month end.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Quick Shortcuts Card -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
        <div class="card-body p-3 p-md-4">
          <h5 class="fw-bold text-dark mb-3">
            <i class="bi bi-lightning-charge text-warning me-2"></i>Quick Actions
          </h5>
          <div class="row g-2">
            <div class="col-6">
              <a href="<?= BASE_URL ?>employee/attendance.php" class="btn btn-light border w-100 py-3 text-start d-flex align-items-center gap-2" style="border-radius:10px;">
                <i class="bi bi-calendar3 fs-4 text-primary"></i>
                <div>
                  <div class="fw-semibold small text-dark">Attendance</div>
                  <div class="text-muted" style="font-size:0.65rem;">History & calendar</div>
                </div>
              </a>
            </div>
            <div class="col-6">
              <a href="<?= BASE_URL ?>employee/work-reports.php" class="btn btn-light border w-100 py-3 text-start d-flex align-items-center gap-2" style="border-radius:10px;">
                <i class="bi bi-journal-check fs-4 text-success"></i>
                <div>
                  <div class="fw-semibold small text-dark">Work Reports</div>
                  <div class="text-muted" style="font-size:0.65rem;">View submissions</div>
                </div>
              </a>
            </div>
            <div class="col-6">
              <a href="<?= BASE_URL ?>employee/leave.php" class="btn btn-light border w-100 py-3 text-start d-flex align-items-center gap-2" style="border-radius:10px;">
                <i class="bi bi-calendar2-plus fs-4 text-info"></i>
                <div>
                  <div class="fw-semibold small text-dark">Apply Leave</div>
                  <div class="text-muted" style="font-size:0.65rem;">Vacation or sick leave</div>
                </div>
              </a>
            </div>
            <div class="col-6">
              <a href="<?= BASE_URL ?>employee/profile.php" class="btn btn-light border w-100 py-3 text-start d-flex align-items-center gap-2" style="border-radius:10px;">
                <i class="bi bi-person-gear fs-4 text-secondary"></i>
                <div>
                  <div class="fw-semibold small text-dark">My Profile</div>
                  <div class="text-muted" style="font-size:0.65rem;">Change password</div>
                </div>
              </a>
            </div>
          </div>
        </div>
      </div>
  </div> <!-- Close row g-3 g-md-4 mb-4 -->

  <!-- Recent Attendance Records (Day-to-Day Live Records) -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
    <div class="card-body p-3 p-md-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h5 class="fw-bold text-dark mb-0"><i class="bi bi-calendar2-check text-primary me-2"></i>Recent Attendance Records</h5>
          <small class="text-muted">Live day-to-day punch timings, break duration, work hours, and status</small>
        </div>
        <a href="<?= BASE_URL ?>employee/attendance.php" class="btn btn-outline-primary btn-sm px-3 fw-semibold rounded-pill shadow-sm text-decoration-none">
          <i class="bi bi-calendar3 me-1"></i> Full Attendance & Reports
        </a>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
          <thead class="table-light text-muted small text-uppercase">
            <tr>
              <th class="ps-3">Date</th>
              <th>Day</th>
              <th>Status</th>
              <th>Check-In</th>
              <th>Check-Out</th>
              <th>Break</th>
              <th>Work Hours</th>
              <th>Late Duration</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $recentDailyRows = array_filter($monthSummary['daily_rows'] ?? [], function($r) use ($today) {
                return $r['date'] <= $today;
            });
            $recentDailyRows = array_slice(array_reverse($recentDailyRows), 0, 7);
            
            $statusColorsWeb = [
                'present'        => ['bg'=>'#d1fae5','text'=>'#065f46'],
                'late'           => ['bg'=>'#fef3c7','text'=>'#92400e'],
                'absent'         => ['bg'=>'#fee2e2','text'=>'#991b1b'],
                'half_day'       => ['bg'=>'#e0f2fe','text'=>'#0369a1'],
                'on_leave'       => ['bg'=>'#ede9fe','text'=>'#5b21b6'],
                'work_from_home' => ['bg'=>'#dcfce7','text'=>'#166534'],
                'holiday'        => ['bg'=>'#fef3c7','text'=>'#b45309'],
            ];

            if (!empty($recentDailyRows)):
              foreach ($recentDailyRows as $r):
                $sc = $statusColorsWeb[$r['status_raw']] ?? ['bg'=>'#f1f5f9','text'=>'#64748b'];
            ?>
              <tr <?= $r['date'] === $today ? 'class="table-primary bg-opacity-25"' : '' ?>>
                <td class="ps-3 fw-semibold text-dark">
                  <?= date('d M Y', strtotime($r['date'])) ?>
                  <?php if ($r['date'] === $today): ?>
                    <span class="badge bg-primary text-white ms-1" style="font-size: 0.62rem;">Today</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted"><?= $r['day'] ?></td>
                <td>
                  <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border-radius:50rem;padding:.25em .8em;font-size:.72rem;font-weight:700;">
                    <?= e($r['status']) ?>
                  </span>
                </td>
                <td><?= !empty($r['check_in']) ? date('h:i:s A', strtotime($r['check_in'])) : '—' ?></td>
                <td><?= !empty($r['check_out']) ? date('h:i:s A', strtotime($r['check_out'])) : '—' ?></td>
                <td>
                  <?php if ($r['break_seconds'] > 0): ?>
                    <span class="badge bg-light text-secondary border fw-semibold" style="font-size:0.75rem;">
                      <i class="bi bi-cup-hot text-primary me-1"></i><?= $r['break_duration'] ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['total_hours'] > 0): ?>
                    <span class="fw-semibold text-dark"><?= format_hours_hms($r['total_hours'], true) ?></span>
                    <span class="text-muted small">(<?= number_format($r['total_hours'], 1) ?>h)</span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['late_minutes'] > 0): ?>
                    <span class="badge bg-warning text-dark"><?= format_minutes_hms($r['late_minutes'], true) ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:0.75rem; max-width:260px;"><?= htmlspecialchars($r['remarks'] ?? '—') ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="9" class="text-center py-4 text-muted">No recent attendance records found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- My Documents & Verification Status Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
    <div class="card-body p-3 p-md-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h5 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-check text-primary me-2"></i>My Official Documents & Verification</h5>
          <small class="text-muted">Keep your Resume, Aadhaar Card, and Bank details updated for payroll compliance</small>
        </div>
        <a href="<?= BASE_URL ?>employee/profile.php?tab=docs" class="btn btn-primary btn-sm px-3 fw-semibold rounded-pill shadow-sm text-decoration-none">
          <i class="bi bi-cloud-arrow-up me-1"></i> Upload / Edit Documents
        </a>
      </div>

      <?php 
        $resumeMissing = empty($curEmp['resume_file']);
        $aadhaarMissing = empty($curEmp['aadhaar_file']);
        $bankMissing = empty($curEmp['bank_acc_no']);
        $allDocsVerified = (!$resumeMissing && !$aadhaarMissing && !$bankMissing);
      ?>

      <div class="row g-3" id="officialDocsContainer">
        <!-- All Verified Banner -->
        <div class="col-12 <?= $allDocsVerified ? '' : 'd-none' ?>" id="allDocsVerifiedBanner">
          <div class="p-4 bg-success-subtle border border-success-subtle rounded-3 text-center">
            <i class="bi bi-shield-check text-success fs-1 mb-2 d-block"></i>
            <h6 class="fw-bold text-success mb-1">All Official Documents Verified & Up to Date</h6>
            <p class="text-muted small mb-0">Your Resume, Aadhaar Card, and Bank Account details are all verified on file.</p>
          </div>
        </div>

        <!-- Resume Widget (Only if unuploaded) -->
        <?php if ($resumeMissing): ?>
        <div class="col-md-4" id="docCardResume">
          <div class="p-3 bg-light rounded-3 border h-100 d-flex flex-column justify-content-between">
            <div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-bold small text-dark"><i class="bi bi-file-earmark-person text-primary me-1"></i> Resume / CV</span>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill"><i class="bi bi-x-circle me-1"></i>Missing</span>
              </div>
              <p class="text-muted mb-3" style="font-size:0.75rem;">Latest Curriculum Vitae / Resume on record with HR.</p>
            </div>
            <div>
              <a href="<?= BASE_URL ?>employee/profile.php?tab=docs" class="btn btn-sm btn-outline-danger w-100 fw-semibold text-decoration-none">
                <i class="bi bi-upload me-1"></i> Upload Resume
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Govtment ID Widget (Only if unuploaded) -->
        <?php if ($aadhaarMissing): ?>
        <div class="col-md-4" id="docCardAadhaar">
          <div class="p-3 bg-light rounded-3 border h-100 d-flex flex-column justify-content-between">
            <div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-bold small text-dark"><i class="bi bi-card-heading text-primary me-1"></i> Govtment ID</span>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill"><i class="bi bi-x-circle me-1"></i>Missing</span>
              </div>
              <p class="text-muted mb-3" style="font-size:0.75rem;">
                Aadhaar / PAN Card · Ref: <?= !empty($curEmp['aadhaar_ref']) ? e($curEmp['aadhaar_ref']) : '<span class="text-muted">Not added</span>' ?>
              </p>
            </div>
            <div>
              <a href="<?= BASE_URL ?>employee/profile.php?tab=docs" class="btn btn-sm btn-outline-danger w-100 fw-semibold text-decoration-none">
                <i class="bi bi-upload me-1"></i> Upload Govtment ID
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Bank Details Summary Widget (Only if unuploaded) -->
        <?php if ($bankMissing): ?>
        <div class="col-md-4" id="docCardBank">
          <div class="p-3 bg-light rounded-3 border h-100 d-flex flex-column justify-content-between">
            <div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-bold small text-dark"><i class="bi bi-bank text-primary me-1"></i> Bank Account</span>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill"><i class="bi bi-exclamation-circle me-1"></i>Required</span>
              </div>
              <div class="small text-muted mb-2" style="font-size:0.75rem;">
                <div><strong>Bank:</strong> <?= e($curEmp['bank_name'] ?: 'Not added') ?></div>
                <div><strong>A/C:</strong> Not added</div>
                <div><strong>IFSC:</strong> <?= e($curEmp['bank_ifsc'] ?: '—') ?></div>
              </div>
            </div>
            <div>
              <a href="<?= BASE_URL ?>employee/profile.php?tab=bank" class="btn btn-sm btn-outline-secondary w-100 fw-semibold text-decoration-none">
                <i class="bi bi-pencil me-1"></i> Update Bank Info
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
});
</script>

<!-- MODAL: GPS / LOCATION PERMISSION REQUIRED -->
<div class="modal fade" id="locationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
      <div class="modal-body text-center p-4">
        <div class="bg-warning-subtle text-warning-emphasis d-inline-flex p-3 rounded-circle mb-3">
          <i class="bi bi-geo-alt-fill fs-1"></i>
        </div>
        <h5 class="fw-bold text-dark mb-2">Turn ON Device Location</h5>
        <p class="text-muted small mb-3">
          Office policy requires you to be physically present at the office to mark attendance. Please turn <strong>ON</strong> your device GPS / location and allow browser permission.
        </p>

        <!-- Help box for denied permission / tips -->
        <div id="locationHelpBox" class="mb-3" style="display:none;"></div>

        <div class="d-grid gap-2">
          <button type="button" class="btn btn-primary fw-semibold py-2" id="btnAllowLocation" onclick="initGeoLocation(true)">
            <i class="bi bi-crosshair me-2"></i> Allow / Detect Location
          </button>
          <button type="button" class="btn btn-light btn-sm text-muted" onclick="onCloseLocationModal()">
            Close
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: OUTSIDE OFFICE PREMISES ALERT -->
<div class="modal fade" id="outsideOfficeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
      <div class="modal-body text-center p-4">
        <div class="bg-danger-subtle text-danger d-inline-flex p-3 rounded-circle mb-3">
          <i class="bi bi-building-slash fs-1"></i>
        </div>
        <h5 class="fw-bold text-danger mb-2">Outside Office Boundary</h5>
        <p class="text-dark mb-2 fw-medium" id="outsideMsgTitle">
          Please go to office and mark attendance.
        </p>
        <div class="alert alert-warning py-2 small mb-4 text-start" id="outsideMsgDetails">
          You are currently outside the designated office premises. Attendance punch is locked until you reach office.
        </div>
        <button type="button" class="btn btn-secondary w-100 fw-semibold py-2" data-bs-dismiss="modal">
          Understood
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Live clock
setInterval(() => {
  const now = new Date();
  const clk = document.getElementById('liveClock');
  if (clk) clk.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
}, 1000);

const API = '<?= BASE_URL ?>modules/team/attendance_api.php';
const CSRF = '<?= csrf_token() ?>';

// Geofence Configuration from Backend
const OFFICE_LAT       = <?= (float)($attSettings['office_latitude'] ?? 29.22006923) ?>;
const OFFICE_LNG       = <?= (float)($attSettings['office_longitude'] ?? 78.96778362) ?>;
const GEOFENCE_RADIUS  = <?= (int)($attSettings['geofence_radius_meters'] ?? 200) ?>;
const GEOFENCE_ENABLED = <?= !empty($attSettings['geofence_enabled']) ? 'true' : 'false' ?>;

let isTodayNonWorking = <?= $isTodayNonWorking ? 'true' : 'false' ?>;
let todayHolidayName = <?= json_encode($todayHolidayName) ?>;
let isTodayWorkingOverride = <?= $isTodayWorkingOverride ? 'true' : 'false' ?>;

let userLat = null;
let userLng = null;
let userDistance = null;
let locationChecked = false;

// Haversine formula in JS
function calcDistanceMeters(lat1, lon1, lat2, lon2) {
  const R = 6371000;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon/2) * Math.sin(dLon/2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  return Math.round(R * c);
}

function updateGeoUI() {
  const title = document.getElementById('geoStatusTitle');
  const sub   = document.getElementById('geoStatusSubtitle');
  const icon  = document.getElementById('geoStatusIcon');
  const box   = document.getElementById('geoStatusBox');

  if (!GEOFENCE_ENABLED) {
    if (title) title.textContent = 'Office Geofence: Disabled';
    if (sub)   sub.textContent   = 'Attendance can be marked from any location.';
    return;
  }

  if (userLat === null) {
    if (title) title.innerHTML = '<span class="text-danger fw-semibold"><i class="bi bi-x-circle me-1" style="font-size:0.8rem;"></i>Location OFF / Not Permitted</span>';
    if (sub)   sub.textContent = 'Turn on GPS and click Detect to enable attendance punch.';
    if (icon)  { icon.className = 'bi bi-geo-alt text-danger'; icon.style.fontSize = '0.8rem'; }
    if (box)   { box.className = 'p-2 px-3 mb-3 rounded-3 border border-danger-subtle bg-danger-subtle d-flex align-items-center justify-content-between'; }
    return;
  }

  userDistance = calcDistanceMeters(userLat, userLng, OFFICE_LAT, OFFICE_LNG);

  if (userDistance <= GEOFENCE_RADIUS) {
    if (title) title.innerHTML = `<span class="d-inline-flex align-items-center gap-1 text-success fw-bold"><i class="bi bi-check-circle-fill text-success" style="font-size: 0.8rem;"></i> Inside Office (${userDistance}m away)</span>`;
    if (sub)   sub.textContent = `Within allowed radius (${GEOFENCE_RADIUS}m). Attendance punch is unlocked.`;
    if (icon)  { icon.className = 'bi bi-geo-alt-fill text-success'; icon.style.fontSize = '0.8rem'; }
    if (box)   { box.className = 'p-2 px-3 mb-3 rounded-3 border border-success-subtle bg-success-subtle d-flex align-items-center justify-content-between'; }
  } else {
    if (title) title.innerHTML = `<span class="d-inline-flex align-items-center gap-1 text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill text-danger" style="font-size: 0.8rem;"></i> Outside Office (${userDistance}m away)</span>`;
    if (sub)   sub.textContent = `Allowed radius: ${GEOFENCE_RADIUS}m. Please go to office to mark attendance.`;
    if (icon)  { icon.className = 'bi bi-geo-alt-fill text-danger'; icon.style.fontSize = '0.8rem'; }
    if (box)   { box.className = 'p-2 px-3 mb-3 rounded-3 border border-danger-subtle bg-danger-subtle d-flex align-items-center justify-content-between'; }
  }
}

function onCloseLocationModal() {
  const locModal = document.getElementById('locationModal');
  const inst = bootstrap.Modal.getInstance(locModal);
  if (inst) inst.hide();

  // Show warning banner on attendance card
  const banner = document.getElementById('locationWarningBanner');
  if (banner) banner.style.display = 'block';

  // Alert exact message requested by user
  alert("Aap bina location open kare attendance nahi laga sakte.");
}

function initGeoLocation(manualClick = false) {
  if (!navigator.geolocation) {
    alert('Geolocation is not supported by your device browser.');
    return;
  }

  const btn = document.getElementById('btnAllowLocation');
  if (btn && manualClick) {
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Requesting Location...';
    btn.disabled = true;
  }

  const title = document.getElementById('geoStatusTitle');
  if (title) title.innerHTML = '<span class="text-primary"><span class="spinner-border spinner-border-sm me-1"></span>Detecting live GPS...</span>';

  navigator.geolocation.getCurrentPosition(
    pos => {
      userLat = pos.coords.latitude;
      userLng = pos.coords.longitude;
      locationChecked = true;
      updateGeoUI();

      if (btn) {
        btn.innerHTML = '<i class="bi bi-check-circle me-2"></i> Location Connected!';
        btn.className = 'btn btn-success fw-semibold py-2';
      }

      // Hide warning banner
      const banner = document.getElementById('locationWarningBanner');
      if (banner) banner.style.display = 'none';

      // Reset error box
      const errBox = document.getElementById('locationHelpBox');
      if (errBox) errBox.style.display = 'none';

      // Close modal
      setTimeout(() => {
        const locModal = document.getElementById('locationModal');
        const inst = bootstrap.Modal.getInstance(locModal);
        if (inst) inst.hide();
        if (btn) {
          btn.innerHTML = '<i class="bi bi-crosshair me-2"></i> Allow / Detect Location';
          btn.className = 'btn btn-primary fw-semibold py-2';
          btn.disabled = false;
        }
      }, 500);
    },
    err => {
      userLat = null;
      userLng = null;
      locationChecked = true;
      updateGeoUI();

      if (btn) {
        btn.innerHTML = '<i class="bi bi-arrow-repeat me-2"></i> Retry / Allow Location';
        btn.disabled = false;
      }

      const errBox = document.getElementById('locationHelpBox');
      if (errBox) {
        errBox.style.display = 'block';
        if (err.code === 1) { // PERMISSION_DENIED
          errBox.innerHTML = `
            <div class="alert alert-danger text-start py-2 px-3 small mb-0" style="font-size:0.78rem;">
              <strong><i class="bi bi-shield-exclamation me-1"></i>Location Permission Denied:</strong><br>
              Browser ne location block kar rakhi hai. Location on karne ke liye:
              <ol class="mb-0 ps-3 mt-1">
                <li>Browser URL bar mein <strong>Lock 🔒</strong> icon par click karein.</li>
                <li><strong>Location</strong> ko <strong>Allow</strong> karein.</li>
                <li>Page refresh karein ya 'Retry' dabayein.</li>
              </ol>
            </div>
          `;
        } else {
          errBox.innerHTML = `
            <div class="alert alert-warning text-start py-2 px-3 small mb-0" style="font-size:0.8rem;">
              <i class="bi bi-geo-slash me-1"></i> <strong>GPS Signal Not Found:</strong> Phone/PC ka Location/GPS ON karein aur dobara click karein.
            </div>
          `;
        }
      }

      // Show modal
      const locModal = document.getElementById('locationModal');
      const inst = bootstrap.Modal.getOrCreateInstance(locModal);
      inst.show();
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
  );
}

// Auto-check on page load
window.addEventListener('DOMContentLoaded', () => {
  initGeoLocation(false);
});

let currentCheckInTime = '<?= ($todayAtt && $todayAtt['check_in']) ? $todayAtt['check_in'] : '' ?>';
let currentCheckOutTime = '<?= ($todayAtt && $todayAtt['check_out']) ? $todayAtt['check_out'] : '' ?>';
let currentAttendanceStatus = '<?= $todayAtt['status'] ?? '' ?>';
let isOnLunchNow = <?= $isOnLunch ? 'true' : 'false' ?>;
let totalLunchSecAlready = <?= (int)$totalLunchSec ?>;
let workTimerInterval = null;

function formatTimeToAmPm(timeStr) {
  if (!timeStr || timeStr === '00:00:00') return '—';
  if (timeStr.includes('AM') || timeStr.includes('PM')) return timeStr;
  const parts = timeStr.split(':');
  let h = parseInt(parts[0], 10);
  if (isNaN(h)) return timeStr;
  const m = parts[1] || '00';
  const ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12;
  if (h === 0) h = 12;
  return `${String(h).padStart(2,'0')}:${m} ${ampm}`;
}

function updateLiveWorkTimer() {
  const el = document.getElementById('liveWorkTimer');
  if (!el || !currentCheckInTime || (currentCheckOutTime && currentCheckOutTime !== '00:00:00')) return;

  if (isOnLunchNow) {
    el.innerHTML = '<span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-pause-circle-fill me-1"></i>Timer Paused (On Break)</span>';
    return;
  }

  const parts = currentCheckInTime.split(':');
  let hIn = parseInt(parts[0], 10) || 0;
  let mIn = parseInt(parts[1], 10) || 0;
  let sIn = parseInt(parts[2], 10) || 0;
  const inSecOfDay = (hIn * 3600) + (mIn * 60) + sIn;
  const now = new Date();
  const nowSecOfDay = (now.getHours() * 3600) + (now.getMinutes() * 60) + now.getSeconds();
  let diff = Math.max(0, nowSecOfDay - inSecOfDay - totalLunchSecAlready);
  const h = Math.floor(diff / 3600);
  const m = Math.floor((diff % 3600) / 60);
  const s = diff % 60;
  el.textContent = `${h} hrs ${m} mins ${s} secs`;
}

function startLiveTimer() {
  if (workTimerInterval) clearInterval(workTimerInterval);
  updateLiveWorkTimer();
  workTimerInterval = setInterval(updateLiveWorkTimer, 1000);
}

if (currentCheckInTime && (!currentCheckOutTime || currentCheckOutTime === '00:00:00')) {
  startLiveTimer();
}

function showDashboardToast(msg, type = 'success') {
  const container = document.getElementById('dashboardAlertWrap') || document.querySelector('.container-fluid');
  if (!container) return;
  const alertDiv = document.createElement('div');
  alertDiv.className = `alert alert-${type} alert-dismissible fade show border-0 shadow-sm mb-3`;
  alertDiv.style.borderRadius = '12px';
  alertDiv.innerHTML = `
    <i class="bi bi-check-circle-fill me-2"></i><strong>${msg}</strong>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  `;
  container.insertBefore(alertDiv, container.firstChild);
  setTimeout(() => {
    alertDiv.classList.remove('show');
    setTimeout(() => alertDiv.remove(), 300);
  }, 4000);
}

async function parseSafeJson(response) {
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    const s = text.indexOf('{');
    const end = text.lastIndexOf('}');
    if (s !== -1 && end !== -1) {
      return JSON.parse(text.substring(s, end + 1));
    }
    throw new Error('Invalid JSON response: ' + text);
  }
}

function applyAttendanceState(data) {
  if (!data) return;
  try {
    // 1. Update In/Out times & state
    if (data.check_in_raw) {
      currentCheckInTime = data.check_in_raw;
    } else if (data.check_in && typeof data.check_in === 'string' && !data.check_in.includes('M')) {
      currentCheckInTime = data.check_in;
    } else if (!currentCheckInTime && data.check_in) {
      currentCheckInTime = data.check_in;
    }

    if (data.status) {
      currentAttendanceStatus = data.status;
    }

    const punchIn = document.getElementById('punchInDisplay');
    if (punchIn && (data.check_in || currentCheckInTime)) {
      punchIn.textContent = formatTimeToAmPm(data.check_in || currentCheckInTime);
    }

    if ((data.check_out_raw && data.check_out_raw !== '00:00:00') || (data.check_out && data.check_out !== '00:00:00')) {
      currentCheckOutTime = data.check_out_raw || data.check_out;
      const punchOut = document.getElementById('punchOutDisplay');
      if (punchOut) punchOut.textContent = formatTimeToAmPm(currentCheckOutTime);
    } else {
      currentCheckOutTime = '';
      const punchOut = document.getElementById('punchOutDisplay');
      if (punchOut) {
        if (currentCheckInTime) {
          punchOut.innerHTML = '<span class="text-primary fs-6 fw-semibold">In Progress</span>';
        } else {
          punchOut.textContent = '—';
        }
      }
    }

    // 2. Break state
    if (typeof data.is_on_break !== 'undefined') {
      isOnLunchNow = !!data.is_on_break;
    } else if (typeof data.is_on_lunch !== 'undefined') {
      isOnLunchNow = !!data.is_on_lunch;
    }
    if (typeof data.total_lunch_seconds !== 'undefined') {
      totalLunchSecAlready = parseInt(data.total_lunch_seconds, 10) || 0;
    } else if (typeof data.break_seconds !== 'undefined') {
      totalLunchSecAlready = parseInt(data.break_seconds, 10) || 0;
    }

    const punchLunch = document.getElementById('punchLunchDisplay');
    if (punchLunch) {
      if (data.total_lunch_formatted) punchLunch.textContent = data.total_lunch_formatted;
      else if (data.lunch_duration_formatted) punchLunch.textContent = data.lunch_duration_formatted;
      else if (data.break_formatted) punchLunch.textContent = data.break_formatted;
      else if (data.hours_summary && data.hours_summary.lunch_formatted) punchLunch.textContent = data.hours_summary.lunch_formatted;
      
      if (isOnLunchNow) {
        punchLunch.className = 'fw-bold fs-6 text-warning';
      } else {
        punchLunch.className = 'fw-bold fs-6 text-dark';
      }
    }

    const punchLunchSub = document.getElementById('punchLunchSubtext');
    if (punchLunchSub) {
      if (isOnLunchNow) {
        const bStart = data.start_time_formatted || (data.start_time ? formatTimeToAmPm(data.start_time) : (data.break_start || 'Now'));
        punchLunchSub.textContent = `Started ${bStart}`;
      } else if (data.hours_summary && data.hours_summary.lunch_start) {
        punchLunchSub.textContent = `${data.hours_summary.lunch_start} - ${data.hours_summary.lunch_end || 'Completed'}`;
      } else if (totalLunchSecAlready > 0) {
        punchLunchSub.textContent = 'Completed';
      } else {
        punchLunchSub.textContent = '';
      }
    }

    // 3. Toggle Not Clocked In vs Stats block
    const notClockedMsg = document.getElementById('attendanceNotClockedInMsg');
    const statsBlock = document.getElementById('attendanceStatsBlock');
    if (notClockedMsg) notClockedMsg.style.display = currentCheckInTime ? 'none' : 'block';
    if (statsBlock) statsBlock.style.display = currentCheckInTime ? 'block' : 'none';

    // 4. Update Header Status Badge & Late Notice
    const badge = document.getElementById('todayAttBadge');
    if (badge) {
      if (currentCheckOutTime && currentCheckOutTime !== '00:00:00') {
        badge.className = 'badge bg-success text-white px-3 py-1 rounded-pill fw-semibold';
        badge.innerHTML = '<i class="bi bi-check2-all me-1"></i>Completed';
      } else if (currentAttendanceStatus === 'late' || data.status === 'late') {
        badge.className = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill fw-semibold';
        badge.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Late Arrival';
      } else if (currentCheckInTime) {
        badge.className = 'badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-semibold';
        badge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Present';
      } else {
        badge.className = 'badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 rounded-pill fw-semibold';
        badge.innerHTML = '<i class="bi bi-dash-circle me-1"></i>Not Marked Yet';
      }
    }

    const lateNotice = document.getElementById('lateArrivalNotice');
    if (lateNotice) {
      const lateMins = parseInt(data.late_minutes, 10) || 0;
      if (lateMins > 0 || currentAttendanceStatus === 'late' || data.status === 'late') {
        lateNotice.style.display = 'block';
        const lateFmt = data.late_hms || `${lateMins} mins`;
        lateNotice.innerHTML = `<i class="bi bi-clock-history me-1"></i> Arrived ${lateFmt} after office grace time.`;
      } else {
        lateNotice.style.display = 'none';
      }
    }

    // 5. Timer toggle
    const timerWrap = document.getElementById('liveWorkTimerWrap');
    if (timerWrap) {
      if (currentCheckInTime && (!currentCheckOutTime || currentCheckOutTime === '00:00:00')) {
        timerWrap.style.display = 'block';
        if (isOnLunchNow) {
          const liveTimer = document.getElementById('liveWorkTimer');
          if (liveTimer) liveTimer.innerHTML = '<span class="badge bg-warning text-dark px-3 py-1-5 text-wrap text-break shadow-sm" style="font-size:0.8rem; font-weight:600; line-height:1.35; white-space:normal !important; max-width:100%; display:inline-flex; align-items:center; justify-content:center; gap:4px;"><i class="bi bi-cup-hot-fill me-1"></i>On Break (Timer Paused)</span>';
        }
        startLiveTimer();
      } else {
        timerWrap.style.display = 'none';
        if (workTimerInterval) clearInterval(workTimerInterval);
      }
    }

    // 6. Update Action Buttons in Today's Attendance card
    const actContainer = document.getElementById('attendanceActionContainer');
    if (actContainer) {
      if (currentCheckOutTime && currentCheckOutTime !== '00:00:00') {
        const workedText = data.total_hms || data.total_formatted || (data.hours_summary && data.hours_summary.daily ? data.hours_summary.daily.worked_formatted : 'Shift completed');
        actContainer.innerHTML = `
          <div class="alert alert-success py-2 mb-0 text-center small fw-semibold">
            <i class="bi bi-check2-all me-1"></i> Attendance Complete: ${workedText} worked
          </div>
        `;
      } else if (currentCheckInTime) {
        if (isOnLunchNow) {
          const bStartText = data.start_time_formatted || (data.start_time ? formatTimeToAmPm(data.start_time) : (data.break_start || 'Now'));
          actContainer.innerHTML = `
            <div class="p-3 mb-2 rounded-3 border border-warning shadow-sm" style="background: #fffbeb;" id="activeBreakPanel">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="d-flex align-items-center gap-2">
                  <span class="badge rounded-circle bg-warning p-1"><i class="bi bi-cup-hot-fill text-dark"></i></span>
                  <span class="fw-bold text-dark small">On Break</span>
                </div>
                <span class="badge bg-warning text-dark fw-bold">Since ${bStartText}</span>
              </div>
              <div class="text-muted small mb-2" style="font-size:0.75rem;">Working hours timer is paused. Tap below when ready to resume your shift.</div>
              <button class="btn btn-success text-white w-100 py-2 fw-bold shadow-sm" onclick="openLunchConfirmModal('end')">
                <i class="bi bi-play-circle-fill me-2 fs-5 align-middle"></i> Resume Work (End Break)
              </button>
            </div>
            <button class="btn btn-outline-secondary w-100 py-1 small fw-semibold" onclick="checkOutInstant()">
              <i class="bi bi-box-arrow-right me-1"></i> Punch Out for Evening
            </button>
          `;
        } else {
          actContainer.innerHTML = `
            <div class="d-flex gap-2" id="shiftActionButtons">
              <button class="btn btn-outline-warning w-50 py-2 fw-semibold" onclick="openLunchConfirmModal('start')">
                <i class="bi bi-cup-hot me-1"></i> Break
              </button>
              <button class="btn btn-outline-primary w-50 py-2 fw-semibold" onclick="checkOutInstant()">
                <i class="bi bi-box-arrow-right me-1"></i> Punch Out
              </button>
            </div>
          `;
        }
      } else {
        if (isTodayNonWorking) {
          actContainer.innerHTML = `
            <div class="p-3 mb-2 rounded-3 bg-warning-subtle border border-warning-subtle text-center text-dark small fw-semibold" id="holidayPunchNotice">
              <i class="bi bi-calendar-x-fill text-warning me-1"></i> Today is a Holiday (${todayHolidayName}). Attendance punch is closed.
            </div>
          `;
          const geoBox = document.getElementById('geoStatusBox');
          if (geoBox) geoBox.style.setProperty('display', 'none', 'important');
        } else {
          actContainer.innerHTML = `
            <button class="btn btn-primary w-100 py-2 fw-semibold shadow-sm mb-2" id="btnPunchIn" onclick="markAttendanceInstant()">
              <i class="bi bi-fingerprint me-2 fs-5"></i> Mark Attendance Now
            </button>
            <div class="p-2 px-3 rounded-3 bg-light border d-flex align-items-center justify-content-between text-muted">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-cup-hot fs-5 text-secondary"></i>
                <div>
                  <div class="fw-semibold small text-secondary">Break</div>
                  <div style="font-size:0.72rem;">Available after morning punch-in</div>
                </div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-3 fw-semibold" disabled title="Check in first to start break">
                <i class="bi bi-lock me-1"></i>Start Break
              </button>
            </div>
          `;
        }
      }
    }

    // 6b. Update Badge if Not Clocked In
    if (typeof data.is_non_working !== 'undefined') {
      isTodayNonWorking = Boolean(data.is_non_working);
    }
    if (data.holiday_name) {
      todayHolidayName = data.holiday_name;
    }
    if (typeof data.is_working_override !== 'undefined') {
      isTodayWorkingOverride = Boolean(data.is_working_override);
    }

    const badgeWrap = document.getElementById('todayAttBadgeWrap');
    if (badgeWrap && !currentCheckInTime) {
      if (isTodayNonWorking) {
        badgeWrap.innerHTML = `<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge"><i class="bi bi-calendar-x-fill me-1"></i>Holiday: ${todayHolidayName}</span>`;
      } else if (isTodayWorkingOverride) {
        badgeWrap.innerHTML = `<span class="badge bg-info-subtle text-info border border-info-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge"><i class="bi bi-briefcase-fill me-1"></i>Working Day (Scheduled)</span>`;
      } else {
        badgeWrap.innerHTML = `<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 rounded-pill fw-semibold" id="todayAttBadge"><i class="bi bi-dash-circle me-1"></i>Not Marked Yet</span>`;
      }
    }

    // 7. Update Top Banner Break Button
    const topBreakWrap = document.getElementById('topBannerBreakBtnWrap');
    if (topBreakWrap) {
      if (isOnLunchNow && currentCheckInTime && (!currentCheckOutTime || currentCheckOutTime === '00:00:00')) {
        topBreakWrap.innerHTML = `
          <button type="button" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm rounded-pill d-inline-flex align-items-center gap-2" onclick="openLunchConfirmModal('end')">
            <i class="bi bi-play-circle-fill"></i>
            <span>End Break</span>
          </button>
        `;
      } else {
        topBreakWrap.innerHTML = '';
      }
    }

    // 8. Update Working Hours & Target Cards
    if (data.hours_summary) {
      const hs = data.hours_summary;
      if (hs.daily) {
        const el = document.getElementById('cardTodayWorked');
        if (el) el.textContent = `Worked: ${hs.daily.worked_formatted || '0h 00m'}`;
        const diffEl = document.getElementById('cardTodayDiff');
        if (diffEl) {
          const diff = hs.daily.diff_minutes || 0;
          const fmt = hs.daily.diff_formatted || '0m';
          if (diff >= 0) diffEl.innerHTML = `<span class="text-success fw-semibold text-truncate"><i class="bi bi-arrow-up-circle-fill me-1"></i>${fmt} (Ahead)</span>`;
          else diffEl.innerHTML = `<span class="text-danger fw-semibold text-truncate"><i class="bi bi-arrow-down-circle-fill me-1"></i>${fmt} (Shortfall)</span>`;
        }
      }
      if (hs.weekly) {
        const el = document.getElementById('cardWeekWorked');
        if (el) el.textContent = `Worked: ${hs.weekly.worked_formatted || '0h 00m'}`;
        const daysEl = document.getElementById('cardWeekDays');
        if (daysEl && hs.weekly.days_formatted) daysEl.textContent = `Days: ${hs.weekly.days_formatted}`;
      }
      if (hs.monthly) {
        const el = document.getElementById('cardMonthDays');
        if (el && hs.monthly.days_formatted) el.textContent = `Days: ${hs.monthly.days_formatted}`;
      }
      if (hs.yearly) {
        const el = document.getElementById('cardYearDays');
        if (el && hs.yearly.days_formatted) el.textContent = `Year <?= date('Y') ?> Days: ${hs.yearly.days_formatted}`;
      }
    }

    // 9. Update Present Days Card
    if (data.present_days_formatted) {
      const el = document.getElementById('cardPresentDays');
      if (el) el.innerHTML = `${data.present_days} <span class="fs-6 text-muted fw-normal">/ ${data.total_month_days}</span>`;
    }
  } catch (domErr) {
    console.error('Error applying attendance state to DOM:', domErr);
  }
}

// Background sync function to fetch complete state from server
function fetchAttendanceLiveState() {
  fetch(`${API}?action=get_dashboard_attendance_state&employee_id=<?= $empId ?>&date=<?= $today ?>`)
    .then(parseSafeJson)
    .then(res => {
      if (res && res.success) {
        applyAttendanceState(res.data || res);
      }
    })
    .catch(() => {});
}

function markAttendanceInstant() {
  if (isTodayNonWorking) {
    showDashboardToast(`Today is an official holiday (${todayHolidayName}). Attendance cannot be marked.`, 'warning');
    return;
  }

  if (GEOFENCE_ENABLED) {
    if (userLat === null) {
      const locModal = document.getElementById('locationModal');
      bootstrap.Modal.getOrCreateInstance(locModal).show();
      return;
    }

    if (userDistance > GEOFENCE_RADIUS) {
      document.getElementById('outsideMsgTitle').textContent = `Please go to office and mark attendance.`;
      document.getElementById('outsideMsgDetails').innerHTML = `
        <strong>Current Distance:</strong> ${userDistance} meters away from office.<br>
        <strong>Allowed Boundary:</strong> within ${GEOFENCE_RADIUS} meters.<br>
        Please reach the office premises and tap "Detect" to punch.
      `;
      const outModal = document.getElementById('outsideOfficeModal');
      bootstrap.Modal.getOrCreateInstance(outModal).show();
      return;
    }
  }

  const btn = document.getElementById('btnPunchIn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Marking Attendance...';
  }

  const now = new Date();
  const timeStr = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0') + ':' + String(now.getSeconds()).padStart(2,'0');
  
  const data = new FormData();
  data.append('action', 'mark_attendance');
  data.append('csrf', CSRF);
  data.append('employee_id', '<?= $empId ?>');
  data.append('date', '<?= $today ?>');
  data.append('status', 'present');
  data.append('check_in', timeStr);
  data.append('is_self', '1');
  if (userLat !== null) {
    data.append('latitude', userLat);
    data.append('longitude', userLng);
  }
  data.append('notes', 'Marked via Employee Dashboard (GPS Verified)');

  fetch(API, { method: 'POST', body: data })
    .then(parseSafeJson)
    .then(res => {
      if (res && res.success) {
        const payload = res.data || res;
        applyAttendanceState(payload);
        showDashboardToast(payload.msg || 'Attendance marked successfully!', 'success');
        fetchAttendanceLiveState();
      } else {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-fingerprint me-2 fs-5"></i> Mark Attendance Now';
        }
        if (res && res.code === 'HOLIDAY_BLOCKED') {
          showDashboardToast(res.msg || `Today is an official holiday (${todayHolidayName}). Attendance cannot be marked.`, 'warning');
          isTodayNonWorking = true;
          fetchAttendanceLiveState();
        } else if (res && res.code === 'LOCATION_REQUIRED') {
          const locModal = document.getElementById('locationModal');
          bootstrap.Modal.getOrCreateInstance(locModal).show();
        } else if (res && res.code === 'OUTSIDE_OFFICE') {
          document.getElementById('outsideMsgTitle').textContent = res.msg;
          const outModal = document.getElementById('outsideOfficeModal');
          bootstrap.Modal.getOrCreateInstance(outModal).show();
        } else {
          showDashboardToast(res ? (res.msg || 'Error marking attendance') : 'Error marking attendance', 'danger');
        }
      }
    })
    .catch(err => {
      console.error("Attendance Punch In Error:", err);
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-fingerprint me-2 fs-5"></i> Mark Attendance Now';
      }
      showDashboardToast('Network error while marking attendance. Please try again.', 'danger');
    });
}

function checkOutInstant() {
  const modal = document.getElementById('punchOutConfirmModal');
  bootstrap.Modal.getOrCreateInstance(modal).show();
}

function executeCheckOut() {
  const modal = document.getElementById('punchOutConfirmModal');
  bootstrap.Modal.getOrCreateInstance(modal).hide();

  if (GEOFENCE_ENABLED) {
    if (userLat === null) {
      const locModal = document.getElementById('locationModal');
      bootstrap.Modal.getOrCreateInstance(locModal).show();
      return;
    }

    if (userDistance > GEOFENCE_RADIUS) {
      document.getElementById('outsideMsgTitle').textContent = `Please go to office to punch out.`;
      document.getElementById('outsideMsgDetails').innerHTML = `
        <strong>Current Distance:</strong> ${userDistance} meters away from office.<br>
        <strong>Allowed Boundary:</strong> within ${GEOFENCE_RADIUS} meters.
      `;
      const outModal = document.getElementById('outsideOfficeModal');
      bootstrap.Modal.getOrCreateInstance(outModal).show();
      return;
    }
  }

  const btnConfirm = document.getElementById('btnConfirmPunchOut');
  if (btnConfirm) {
    btnConfirm.disabled = true;
    btnConfirm.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';
  }

  const now = new Date();
  const timeStr = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0') + ':' + String(now.getSeconds()).padStart(2,'0');
  
  const data = new FormData();
  data.append('action', 'mark_attendance');
  data.append('csrf', CSRF);
  data.append('employee_id', '<?= $empId ?>');
  data.append('date', '<?= $today ?>');
  data.append('status', currentAttendanceStatus || '<?= $todayAtt['status'] ?? 'present' ?>');
  data.append('check_in', currentCheckInTime || '10:00:00');
  data.append('check_out', timeStr);
  data.append('is_self', '1');
  if (userLat !== null) {
    data.append('latitude', userLat);
    data.append('longitude', userLng);
  }
  data.append('notes', 'Checked out via Employee Dashboard (GPS Verified)');

  fetch(API, { method: 'POST', body: data })
    .then(parseSafeJson)
    .then(res => {
      if (btnConfirm) {
        btnConfirm.disabled = false;
        btnConfirm.textContent = 'Punch Out';
      }
      if (res && res.success) {
        const payload = res.data || res;
        applyAttendanceState(payload);
        showDashboardToast(payload.msg || 'Checked out successfully!', 'success');
        fetchAttendanceLiveState();
      } else {
        if (res && res.code === 'LOCATION_REQUIRED') {
          const locModal = document.getElementById('locationModal');
          bootstrap.Modal.getOrCreateInstance(locModal).show();
        } else if (res && res.code === 'OUTSIDE_OFFICE') {
          document.getElementById('outsideMsgTitle').textContent = res.msg;
          const outModal = document.getElementById('outsideOfficeModal');
          bootstrap.Modal.getOrCreateInstance(outModal).show();
        } else {
          showDashboardToast(res ? (res.msg || 'Error updating punch out') : 'Error updating punch out', 'danger');
        }
      }
    })
    .catch(err => {
      if (btnConfirm) {
        btnConfirm.disabled = false;
        btnConfirm.textContent = 'Punch Out';
      }
      console.error("Attendance Punch Out Error:", err);
      showDashboardToast('Network error updating punch out', 'danger');
    });
}

let pendingLunchAction = '';

function openLunchConfirmModal(type) {
  pendingLunchAction = type;
  const title = document.getElementById('lunchModalTitle');
  const msg = document.getElementById('lunchModalMsg');
  const icon = document.getElementById('lunchModalIcon');
  const btn = document.getElementById('btnConfirmLunch');

  if (type === 'start') {
    if (title) title.textContent = 'Start Break?';
    if (msg) msg.textContent = 'Are you sure you want to start your break? Your working hours live timer will pause until you resume work.';
    if (icon) icon.innerHTML = '<i class="bi bi-cup-hot text-warning fs-1"></i>';
    if (btn) {
      btn.className = 'btn btn-warning text-dark w-50 py-2 fw-semibold';
      btn.textContent = 'Start Break';
    }
  } else {
    if (title) title.textContent = 'Resume Work?';
    if (msg) msg.textContent = 'Are you sure you want to end your break and resume your work shift?';
    if (icon) icon.innerHTML = '<i class="bi bi-play-circle text-success fs-1"></i>';
    if (btn) {
      btn.className = 'btn btn-success text-white w-50 py-2 fw-semibold';
      btn.textContent = 'Resume Work';
    }
  }

  const modal = document.getElementById('lunchConfirmModal');
  bootstrap.Modal.getOrCreateInstance(modal).show();
}

function executeLunchAction() {
  if (GEOFENCE_ENABLED) {
    if (userLat === null) {
      const modal = document.getElementById('lunchConfirmModal');
      bootstrap.Modal.getOrCreateInstance(modal).hide();
      const locModal = document.getElementById('locationModal');
      bootstrap.Modal.getOrCreateInstance(locModal).show();
      return;
    }

    if (userDistance > GEOFENCE_RADIUS) {
      const modal = document.getElementById('lunchConfirmModal');
      bootstrap.Modal.getOrCreateInstance(modal).hide();
      document.getElementById('outsideMsgTitle').textContent = `Please reach office premises to toggle break.`;
      document.getElementById('outsideMsgDetails').innerHTML = `
        <strong>Current Distance:</strong> ${userDistance} meters away from office.<br>
        <strong>Allowed Boundary:</strong> within ${GEOFENCE_RADIUS} meters.
      `;
      const outModal = document.getElementById('outsideOfficeModal');
      bootstrap.Modal.getOrCreateInstance(outModal).show();
      return;
    }
  }

  const btn = document.getElementById('btnConfirmLunch');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';
  }

  const act = (pendingLunchAction === 'start') ? 'lunch_start' : 'lunch_end';
  const data = new FormData();
  data.append('action', act);
  data.append('csrf', CSRF);
  data.append('employee_id', '<?= $empId ?>');
  data.append('date', '<?= $today ?>');
  if (userLat !== null) {
    data.append('latitude', userLat);
    data.append('longitude', userLng);
  }

  fetch(API, { method: 'POST', body: data })
    .then(parseSafeJson)
    .then(res => {
      if (btn) btn.disabled = false;
      const modal = document.getElementById('lunchConfirmModal');
      bootstrap.Modal.getOrCreateInstance(modal).hide();
      if (res && res.success) {
        const payload = res.data || res;
        applyAttendanceState(payload);
        showDashboardToast(payload.msg || 'Break status updated successfully', 'success');
        fetchAttendanceLiveState();
      } else {
        showDashboardToast(res ? (res.msg || 'Failed to update break status') : 'Failed to update break status', 'danger');
      }
    })
    .catch(err => {
      console.error("Attendance Break Action Error:", err);
      if (btn) btn.disabled = false;
      showDashboardToast('Network error updating break status', 'danger');
    });
}

// AJAX handler for Profile & Documents Form Upload
document.getElementById('employeeProfileForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const form = this;
  const submitBtn = form.querySelector('button[type="submit"]');
  const oldHtml = submitBtn ? submitBtn.innerHTML : '';
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
  }

  const formData = new FormData(form);
  formData.append('is_ajax', '1');

  fetch('<?= BASE_URL ?>employee/dashboard.php', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(res => {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = oldHtml;
    }
    if (res.success) {
      // Remove uploaded document cards immediately without reload
      if (res.resume_uploaded) {
        const rc = document.getElementById('docCardResume');
        if (rc) { rc.classList.add('fade'); setTimeout(() => rc.remove(), 200); }
      }
      if (res.aadhaar_uploaded) {
        const ac = document.getElementById('docCardAadhaar');
        if (ac) { ac.classList.add('fade'); setTimeout(() => ac.remove(), 200); }
      }
      if (res.bank_uploaded) {
        const bc = document.getElementById('docCardBank');
        if (bc) { bc.classList.add('fade'); setTimeout(() => bc.remove(), 200); }
      }

      setTimeout(() => {
        const remaining = document.querySelectorAll('#officialDocsContainer [id^="docCard"]');
        if (!remaining || remaining.length === 0) {
          const banner = document.getElementById('allDocsVerifiedBanner');
          if (banner) banner.classList.remove('d-none');
        }
      }, 250);

      const modalEl = document.getElementById('editProfileModal');
      const modalInst = bootstrap.Modal.getInstance(modalEl);
      if (modalInst) modalInst.hide();

      showDashboardToast(res.message || 'Profile & Documents updated successfully!', 'success');
    } else {
      showDashboardToast(res.message || 'Failed to update profile', 'danger');
    }
  })
  .catch(err => {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = oldHtml;
    }
    showDashboardToast('Network error saving profile', 'danger');
  });
});
</script>

<!-- Modal: Break Confirmation -->
<div class="modal fade" id="lunchConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow" style="border-radius:16px;">
      <div class="modal-body p-4 text-center">
        <div class="mb-3" id="lunchModalIcon"><i class="bi bi-cup-hot text-warning fs-1"></i></div>
        <h5 class="fw-bold mb-2" id="lunchModalTitle">Start Break?</h5>
        <p class="text-muted small mb-4" id="lunchModalMsg">Your live working timer will pause until you resume work.</p>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-light w-50 py-2" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-warning text-dark w-50 py-2 fw-semibold" id="btnConfirmLunch" onclick="executeLunchAction()">Confirm</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Punch Out Confirmation -->
<div class="modal fade" id="punchOutConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow" style="border-radius:16px;">
      <div class="modal-body p-4 text-center">
        <div class="mb-3">
          <div class="rounded-circle bg-danger bg-opacity-10 text-danger d-inline-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
            <i class="bi bi-box-arrow-right fs-2"></i>
          </div>
        </div>
        <h5 class="fw-bold mb-2 text-dark">Confirm Punch Out?</h5>
        <p class="text-muted small mb-4">Are you sure you want to end your shift and punch out for today?</p>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-light w-50 py-2 fw-semibold" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger w-50 py-2 fw-semibold" id="btnConfirmPunchOut" onclick="executeCheckOut()">Punch Out</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
