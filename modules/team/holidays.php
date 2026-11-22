<?php
// modules/team/holidays.php — Admin Holiday Management
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/audit_helper.php';
require_login();

if (!is_admin() && !is_manager()) {
    header("Location: ../../employee/dashboard.php");
    exit;
}

$csrf = csrf_token();
$selectedYear = intval($_GET['year'] ?? date('Y'));
if ($selectedYear < 2020 || $selectedYear > 2035) {
    $selectedYear = intval(date('Y'));
}

// ----------------------------------------------------
// Handle Add / Edit / Delete POST
// ----------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        set_flash("Invalid CSRF security token.", "danger");
        header("Location: holidays.php?year={$selectedYear}");
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $id          = intval($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $date        = trim($_POST['holiday_date'] ?? '');
        $type        = trim($_POST['holiday_type'] ?? 'festival');
        $description = trim($_POST['description'] ?? '');
        $status      = trim($_POST['status'] ?? 'active');
        if (!in_array($status, ['active', 'working_day'])) {
            $status = 'active';
        }

        if (empty($name) || empty($date)) {
            set_flash("Holiday title and date are required.", "danger");
            header("Location: holidays.php?year={$selectedYear}");
            exit;
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO holidays (name, holiday_type, holiday_date, description, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $type, $date, $description, $status]);
            $newId = $pdo->lastInsertId();

            if ($status === 'working_day') {
                try {
                    $pdo->prepare("DELETE FROM attendance_holidays WHERE holiday_date = ?")->execute([$date]);
                    $pdo->prepare("
                        INSERT INTO holiday_overrides (override_date, action, reason, created_by, created_at)
                        VALUES (?, 'working_day', ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE action = 'working_day', reason = VALUES(reason)
                    ")->execute([$date, $name . ' (Working Day)', $_SESSION['user_id']]);
                } catch (\Throwable $e) {}
                set_flash("Date '{$name}' configured as a NORMAL WORKING DAY. Users will see it as a regular working day.", "success");
            } else {
                try {
                    $pdo->prepare("DELETE FROM holiday_overrides WHERE override_date = ?")->execute([$date]);
                    $sync = $pdo->prepare("INSERT INTO attendance_holidays (holiday_date, holiday_name, holiday_type, created_at) VALUES (?, ?, ?, NOW())");
                    $sync->execute([$date, $name, $type]);
                } catch (\Throwable $e) {}
                set_flash("Holiday '{$name}' created successfully as an Official Holiday!", "success");
            }

            log_audit('holidays', 'create', $newId, ['name' => $name, 'date' => $date, 'type' => $type, 'status' => $status]);
        } else {
            $old = $pdo->query("SELECT * FROM holidays WHERE id = {$id}")->fetch();
            $stmt = $pdo->prepare("UPDATE holidays SET name = ?, holiday_type = ?, holiday_date = ?, description = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $type, $date, $description, $status, $id]);

            if ($old && $old['holiday_date'] !== $date) {
                try {
                    $pdo->prepare("DELETE FROM attendance_holidays WHERE holiday_date = ?")->execute([$old['holiday_date']]);
                    $pdo->prepare("DELETE FROM holiday_overrides WHERE override_date = ?")->execute([$old['holiday_date']]);
                } catch (\Throwable $e) {}
            }

            if ($status === 'working_day') {
                try {
                    $pdo->prepare("DELETE FROM attendance_holidays WHERE holiday_date = ?")->execute([$date]);
                    $pdo->prepare("
                        INSERT INTO holiday_overrides (override_date, action, reason, created_by, created_at)
                        VALUES (?, 'working_day', ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE action = 'working_day', reason = VALUES(reason)
                    ")->execute([$date, $name . ' (Working Day)', $_SESSION['user_id']]);
                } catch (\Throwable $e) {}
                set_flash("'{$name}' set as a NORMAL WORKING DAY. Users will see it as a normal working day.", "success");
            } else {
                try {
                    $pdo->prepare("DELETE FROM holiday_overrides WHERE override_date = ?")->execute([$date]);
                    $sync = $pdo->prepare("INSERT INTO attendance_holidays (holiday_date, holiday_name, holiday_type, created_at) VALUES (?, ?, ?, NOW())");
                    $sync->execute([$date, $name, $type]);
                } catch (\Throwable $e) {}
                set_flash("Holiday '{$name}' updated successfully as an Official Holiday!", "success");
            }

            log_audit('holidays', 'update', $id, ['name' => $name, 'date' => $date, 'type' => $type, 'status' => $status]);
        }

        $postYear = (int)date('Y', strtotime($date));
        header("Location: holidays.php?year={$postYear}");
        exit;
    }

    if ($action === 'quick_toggle_holiday') {
        $id = intval($_POST['id'] ?? 0);
        $targetStatus = trim($_POST['target_status'] ?? 'working_day');
        if (!in_array($targetStatus, ['active', 'working_day'])) $targetStatus = 'working_day';

        $h = $pdo->query("SELECT * FROM holidays WHERE id = {$id}")->fetch();
        if ($h) {
            $pdo->prepare("UPDATE holidays SET status = ? WHERE id = ?")->execute([$targetStatus, $id]);
            $date = $h['holiday_date'];

            if ($targetStatus === 'working_day') {
                try {
                    $pdo->prepare("DELETE FROM attendance_holidays WHERE holiday_date = ?")->execute([$date]);
                    $pdo->prepare("
                        INSERT INTO holiday_overrides (override_date, action, reason, created_by, created_at)
                        VALUES (?, 'working_day', ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE action = 'working_day', reason = VALUES(reason)
                    ")->execute([$date, $h['name'] . ' (Working Day)', $_SESSION['user_id']]);
                } catch (\Throwable $e) {}
                set_flash("'{$h['name']}' set as Normal Working Day. Users will see it as a regular working day.", "success");
            } else {
                try {
                    $pdo->prepare("DELETE FROM holiday_overrides WHERE override_date = ?")->execute([$date]);
                    $pdo->prepare("INSERT INTO attendance_holidays (holiday_date, holiday_name, holiday_type, created_at) VALUES (?, ?, ?, NOW())")->execute([$date, $h['name'], $h['holiday_type']]);
                } catch (\Throwable $e) {}
                set_flash("'{$h['name']}' restored as an Official Holiday.", "success");
            }
        }
        header("Location: holidays.php?year={$selectedYear}");
        exit;
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $old = $pdo->query("SELECT * FROM holidays WHERE id = {$id}")->fetch();
        if ($old) {
            $pdo->prepare("DELETE FROM holidays WHERE id = ?")->execute([$id]);
            try {
                $pdo->prepare("DELETE FROM attendance_holidays WHERE holiday_date = ?")->execute([$old['holiday_date']]);
                $pdo->prepare("DELETE FROM holiday_overrides WHERE override_date = ?")->execute([$old['holiday_date']]);
            } catch (\Throwable $e) {}

            log_audit('holidays', 'delete', $id, ['name' => $old['name'], 'date' => $old['holiday_date']]);
            set_flash("Holiday deleted successfully!", "success");
        }
        header("Location: holidays.php?year={$selectedYear}");
        exit;
    }

    if ($action === 'toggle_sunday' || $action === 'toggle_date_status') {
        $selectedDate = trim($_POST['date'] ?? '');
        $targetAction = trim($_POST['target_action'] ?? 'working_day');
        $reason = trim($_POST['reason'] ?? '');

        if (!empty($selectedDate)) {
            $ts = strtotime($selectedDate);
            $isSun = ((int)date('w', $ts) === 0);
            $formattedDate = date('d M Y', $ts);

            if ($targetAction === 'working_day') {
                if (empty($reason)) {
                    $reason = $isSun ? 'Scheduled Working Sunday' : 'Admin Scheduled Working Day';
                }
                $stmt = $pdo->prepare("
                    INSERT INTO holiday_overrides (override_date, action, reason, created_by, created_at)
                    VALUES (?, 'working_day', ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE action = 'working_day', reason = VALUES(reason)
                ");
                $stmt->execute([$selectedDate, $reason, $_SESSION['user_id']]);

                // Update holidays status if present
                $pdo->prepare("UPDATE holidays SET status = 'working_day' WHERE holiday_date = ?")->execute([$selectedDate]);
                try {
                    $pdo->prepare("DELETE FROM attendance_holidays WHERE holiday_date = ?")->execute([$selectedDate]);
                } catch (\Throwable $e) {}

                log_audit('holidays', 'update', null, ['date_override' => $selectedDate, 'state' => 'working_day', 'reason' => $reason]);
                set_flash("Date ({$formattedDate}) successfully scheduled as a NORMAL WORKING DAY for all users.", "success");
            } else {
                if ($isSun) {
                    $stmt = $pdo->prepare("DELETE FROM holiday_overrides WHERE override_date = ?");
                    $stmt->execute([$selectedDate]);
                } else {
                    if (empty($reason)) {
                        $reason = 'Admin Scheduled Holiday / Off';
                    }
                    $stmt = $pdo->prepare("
                        INSERT INTO holiday_overrides (override_date, action, reason, created_by, created_at)
                        VALUES (?, 'holiday', ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE action = 'holiday', reason = VALUES(reason)
                    ");
                    $stmt->execute([$selectedDate, $reason, $_SESSION['user_id']]);
                }

                // If exists in holidays table, restore active, otherwise create it
                $hCheck = $pdo->prepare("SELECT * FROM holidays WHERE holiday_date = ?");
                $hCheck->execute([$selectedDate]);
                $existingH = $hCheck->fetch(PDO::FETCH_ASSOC);
                if ($existingH) {
                    $pdo->prepare("UPDATE holidays SET status = 'active' WHERE id = ?")->execute([$existingH['id']]);
                    try {
                        $pdo->prepare("
                            INSERT INTO attendance_holidays (holiday_date, holiday_name, holiday_type, created_at)
                            VALUES (?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), holiday_type = VALUES(holiday_type)
                        ")->execute([$selectedDate, $existingH['name'], $existingH['holiday_type']]);
                    } catch (\Throwable $e) {}
                } else if (!$isSun) {
                    try {
                        $hTitle = $reason ?: 'Scheduled Holiday';
                        $pdo->prepare("INSERT INTO holidays (name, holiday_type, holiday_date, description, status, created_at) VALUES (?, 'company', ?, ?, 'active', NOW())")
                            ->execute([$hTitle, $selectedDate, $hTitle]);
                        $pdo->prepare("
                            INSERT INTO attendance_holidays (holiday_date, holiday_name, holiday_type, created_at)
                            VALUES (?, ?, 'company', NOW())
                            ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), holiday_type = VALUES(holiday_type)
                        ")->execute([$selectedDate, $hTitle]);
                    } catch (\Throwable $e) {}
                }

                log_audit('holidays', 'update', null, ['date_override' => $selectedDate, 'state' => 'holiday', 'reason' => $reason]);
                set_flash("Date ({$formattedDate}) marked as HOLIDAY / WEEKLY OFF for all users.", "success");
            }

            try {
                require_once __DIR__ . '/../../app/Realtime/RealtimeService.php';
                \App\Realtime\RealtimeService::getInstance($pdo)->emit(
                    'calendar.updated',
                    'holiday',
                    null,
                    'status_change',
                    [
                        'date'       => $selectedDate,
                        'is_working' => ($targetAction === 'working_day'),
                        'reason'     => $reason
                    ]
                );
            } catch (\Throwable $e) {}
        }
        $postYear = (int)date('Y', strtotime($selectedDate ?: date('Y-m-d')));
        header("Location: holidays.php?year={$postYear}&checked_date={$selectedDate}&show_controls=1");
        exit;
    }
}


// ----------------------------------------------------
// Fetch Holidays for Selected Year
// ----------------------------------------------------
$stmt = $pdo->prepare("
    SELECT * FROM holidays 
    WHERE YEAR(holiday_date) = ? 
    ORDER BY holiday_date ASC
");
$stmt->execute([$selectedYear]);
$holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count summary
$todayStr = date('Y-m-d');
$activeHolidaysCount = 0;
$workingDaysOverrideCount = 0;
$upcomingCount = 0;
foreach ($holidays as $h) {
    $isActive = ($h['status'] === 'active' || empty($h['status']));
    if ($isActive) {
        $activeHolidaysCount++;
        if ($h['holiday_date'] >= $todayStr) {
            $upcomingCount++;
        }
    } else {
        $workingDaysOverrideCount++;
    }
}

// Sunday Overrides Map
$sundayOverrides = get_holiday_overrides_map($pdo, $selectedYear);

// Generate all Sundays in the selected year
$allSundays = [];
$curDate = new DateTime("{$selectedYear}-01-01");
$lastDateOfYear = new DateTime("{$selectedYear}-12-31");
while ($curDate <= $lastDateOfYear) {
    if ((int)$curDate->format('w') === 0) {
        $allSundays[] = $curDate->format('Y-m-d');
    }
    $curDate->modify('+1 day');
}
$workingSundaysCount = 0;
foreach ($allSundays as $sDate) {
    if (isset($sundayOverrides[$sDate]) && $sundayOverrides[$sDate]['action'] === 'working_day') {
        $workingSundaysCount++;
    }
}

// Initial Date for the Single Control Card
$checkedDate = trim($_GET['checked_date'] ?? '');
if (empty($checkedDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkedDate)) {
    $today = date('Y-m-d');
    if (date('Y') == $selectedYear) {
        $checkedDate = $today;
    } else {
        $checkedDate = $allSundays[0] ?? "{$selectedYear}-01-01";
    }
}
$showControlsSection = !empty($_GET['show_controls']);
?>


<style>
.section-chevron {
  transition: transform 0.22s ease;
  font-size: 22px;
  display: inline-block;
  vertical-align: middle;
}
.collapsed .section-chevron,
[aria-expanded="false"] .section-chevron {
  transform: rotate(-90deg);
}
.holiday-filter-btn {
  font-size: 0.78rem;
  font-weight: 600;
  border-radius: 50rem;
  padding: 0.25rem 0.75rem;
  transition: all 0.15s ease;
}
.holiday-filter-btn.active {
  background: #0d6efd !important;
  color: #fff !important;
  border-color: #0d6efd !important;
}
.holiday-date-badge {
  min-width: 50px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  text-align: center;
  padding: 4px 6px;
  flex-shrink: 0;
}
.status-theme-working {
  background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%) !important;
  border: 1px solid #86efac !important;
  box-shadow: 0 4px 20px rgba(34, 197, 94, 0.07);
}
.status-theme-holiday {
  background: linear-gradient(135deg, #fff1f2 0%, #ffffff 100%) !important;
  border: 1px solid #fecdd3 !important;
  box-shadow: 0 4px 20px rgba(244, 63, 94, 0.07);
}
.ctrl-date-badge {
  min-width: 78px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  text-align: center;
  padding: 6px 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.04);
  flex-shrink: 0;
}
.ctrl-chip-btn {
  font-size: 0.78rem;
  font-weight: 600;
  border-radius: 50rem;
  padding: 0.32rem 0.85rem;
  transition: all 0.15s ease;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  color: #334155;
}
.ctrl-chip-btn:hover {
  background: #f1f5f9;
  border-color: #94a3b8;
  color: #0f172a;
}
</style>

<div class="container-fluid py-4">

  <!-- Top bar -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <a href="attendance.php" class="text-decoration-none text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Attendance</a>
      </div>
      <h3 class="fw-bold mb-0"><i class="bi bi-calendar-event text-primary me-2"></i>Holiday Management</h3>
      <p class="text-muted mb-0 small">Manage company, festival, and official holidays. Sundays & holidays are automatically excluded from target hours & payroll deductions.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
      <!-- Year Selector -->
      <form method="GET" class="d-flex align-items-center me-2">
        <select name="year" class="form-select form-select-sm fw-semibold shadow-sm" onchange="this.form.submit()" style="min-width:110px;">
          <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
            <option value="<?= $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </form>
      <button class="btn btn-primary btn-sm px-3 shadow-sm" onclick="openAddHolidayModal()">
        <i class="bi bi-plus-circle me-1"></i>Add Holiday
      </button>
    </div>
  </div>

  <?php $flash = get_flash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-info-circle-fill me-2"></i><?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Summary Cards -->
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px; border-left:4px solid #ef4444 !important;">
        <div class="card-body p-3">
          <div class="text-muted small fw-semibold">Official Holidays in <?= $selectedYear ?></div>
          <div class="h3 fw-bold text-danger mb-0 mt-1"><?= $activeHolidaysCount ?></div>
          <small class="text-muted"><?= $upcomingCount ?> upcoming remaining this year</small>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px; border-left:4px solid #10b981 !important;">
        <div class="card-body p-3">
          <div class="text-muted small fw-semibold">Working Days (Overridden)</div>
          <div class="h3 fw-bold text-success mb-0 mt-1"><?= $workingDaysOverrideCount ?></div>
          <small class="text-muted">Festivals converted to Normal Working Days</small>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px; border-left:4px solid #8b5cf6 !important;">
        <div class="card-body p-3">
          <div class="text-muted small fw-semibold">Sundays in <?= $selectedYear ?></div>
          <div class="h3 fw-bold text-purple mb-0 mt-1"><?= count($allSundays) ?></div>
          <small class="text-muted"><?= $workingSundaysCount ?> scheduled as working days</small>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px; border-left:4px solid #f59e0b !important;">
        <div class="card-body p-3">
          <div class="text-muted small fw-semibold">Daily Shift Target</div>
          <div class="h3 fw-bold text-warning-emphasis mb-0 mt-1">7h 00m</div>
          <small class="text-muted">Standard target per regular working day</small>
        </div>
      </div>
    </div>
  </div>

  <!-- Holidays Table Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:16px; overflow:hidden;">
    <div class="card-header bg-white border-0 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2 collapsed"
         role="button"
         data-bs-toggle="collapse"
         data-bs-target="#holidaysCalendarCollapse"
         aria-expanded="false"
         style="cursor:pointer;"
         title="Click to Collapse / Expand">
      <div class="d-flex align-items-center gap-2">
        <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
          <i class="bi bi-list-ul me-2 text-primary"></i>Holidays & Festival Calendar — <?= $selectedYear ?>
        </h5>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-light text-muted border px-2 py-1"><?= count($holidays) ?> Configured</span>
        <button class="btn btn-sm btn-light border-0 p-1 rounded-circle d-flex align-items-center justify-content-center" 
                type="button" 
                onclick="event.stopPropagation();" 
                data-bs-toggle="collapse" 
                data-bs-target="#holidaysCalendarCollapse"
                title="Collapse / Expand"
                style="width:30px; height:30px;">
          <span class="material-symbols-outlined section-chevron">expand_more</span>
        </button>
      </div>
    </div>
    
    <div class="collapse" id="holidaysCalendarCollapse">
      <!-- Filter Bar -->
      <div class="px-4 py-2 bg-light border-top border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2">
          <span class="text-muted small fw-medium">Filter:</span>
          <button type="button" class="btn btn-sm btn-outline-secondary holiday-filter-btn active shadow-none" onclick="filterHolidayTable('all', this)">All (<?= count($holidays) ?>)</button>
          <button type="button" class="btn btn-sm btn-outline-danger holiday-filter-btn shadow-none" onclick="filterHolidayTable('holiday', this)">Official Holidays (<?= $activeHolidaysCount ?>)</button>
          <button type="button" class="btn btn-sm btn-outline-success holiday-filter-btn shadow-none" onclick="filterHolidayTable('working', this)">Working Days (<?= $workingDaysOverrideCount ?>)</button>
        </div>
        <small class="text-muted d-none d-md-inline">Click "Make Working Day" to schedule normal working hours on any holiday</small>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="holidaysTable" style="font-size:0.88rem;">
            <thead class="table-light">
              <tr>
                <th class="ps-4" style="min-width:170px;">Date & Day</th>
                <th style="min-width:180px;">Holiday Name</th>
                <th>Type</th>
                <th>Description</th>
                <th style="min-width:190px;">Schedule / Status</th>
                <th class="pe-4 text-end" style="min-width:180px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($holidays)): ?>
                <tr><td colspan="6" class="text-center text-muted py-5">No holidays scheduled for <?= $selectedYear ?>. Click "Add Holiday" to create one.</td></tr>
              <?php else: ?>
                <?php foreach ($holidays as $h): 
                  $hTs = strtotime($h['holiday_date']);
                  $isPast = ($h['holiday_date'] < $todayStr);
                  $isToday = ($h['holiday_date'] === $todayStr);
                  $dayName = date('l', $hTs);
                  $isSun = (date('w', $hTs) == 0);
                  $isWorking = ($h['status'] === 'working_day');
                ?>
                  <tr class="holiday-row <?= $isToday ? 'table-warning' : ($isPast ? 'text-muted' : '') ?>" data-status-type="<?= $isWorking ? 'working' : 'holiday' ?>">
                    <td class="ps-4">
                      <div class="d-flex align-items-center gap-2">
                        <div class="holiday-date-badge">
                          <div class="text-uppercase fw-bold text-muted" style="font-size:0.6rem;"><?= date('M', $hTs) ?></div>
                          <div class="fw-bold fs-6 text-dark lh-1"><?= date('d', $hTs) ?></div>
                        </div>
                        <div>
                          <div class="fw-semibold text-dark"><?= date('d M Y', $hTs) ?></div>
                          <span class="badge <?= $isSun ? 'bg-danger-subtle text-danger' : 'bg-light text-muted border' ?>" style="font-size:0.7rem;"><?= $dayName ?></span>
                        </div>
                      </div>
                    </td>
                    <td class="fw-bold text-dark">
                      <div class="d-flex align-items-center gap-1">
                        <span><?= e($h['name']) ?></span>
                        <?php if ($isToday): ?>
                          <span class="badge bg-primary ms-1">Today</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <?php
                        $typeIcon = 'celebration';
                        $typeBadgeClass = 'bg-info-subtle text-info border-info-subtle';
                        if (($h['holiday_type'] ?? '') === 'national') {
                          $typeIcon = 'flag';
                          $typeBadgeClass = 'bg-primary-subtle text-primary border-primary-subtle';
                        } elseif (($h['holiday_type'] ?? '') === 'company') {
                          $typeIcon = 'domain';
                          $typeBadgeClass = 'bg-warning-subtle text-warning-emphasis border-warning-subtle';
                        }
                      ?>
                      <span class="badge <?= $typeBadgeClass ?> border text-capitalize d-inline-flex align-items-center gap-1">
                        <span class="material-symbols-outlined" style="font-size:14px;"><?= $typeIcon ?></span>
                        <?= e($h['holiday_type'] ?? 'festival') ?>
                      </span>
                    </td>
                    <td class="text-muted small">
                      <?= e($h['description'] ?: '—') ?>
                    </td>
                    <td>
                      <?php if ($isWorking): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle py-1 px-2 fw-semibold d-inline-flex align-items-center gap-1">
                          <span class="material-symbols-outlined" style="font-size:14px;">work</span>Normal Working Day
                        </span>
                        <div class="text-muted" style="font-size:0.72rem; margin-top:2px;">Employees see regular working day</div>
                      <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle py-1 px-2 fw-semibold d-inline-flex align-items-center gap-1">
                          <span class="material-symbols-outlined" style="font-size:14px;">event_busy</span>Official Holiday
                        </span>
                        <div class="text-muted" style="font-size:0.72rem; margin-top:2px;">Office closed / Off day</div>
                      <?php endif; ?>
                    </td>
                    <td class="pe-4 text-end">
                      <!-- Quick Toggle: Make Working Day / Make Holiday -->
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="quick_toggle_holiday">
                        <input type="hidden" name="id" value="<?= $h['id'] ?>">
                        <?php if ($isWorking): ?>
                          <input type="hidden" name="target_status" value="active">
                          <button type="submit" class="btn btn-sm btn-outline-danger me-1 py-1 px-2 fw-medium shadow-none" title="Revert to Official Holiday">
                            <i class="bi bi-calendar-x me-1"></i>Make Holiday
                          </button>
                        <?php else: ?>
                          <input type="hidden" name="target_status" value="working_day">
                          <button type="submit" class="btn btn-sm btn-outline-success me-1 py-1 px-2 fw-medium shadow-none" title="Convert to Normal Working Day (Employees see normal day)">
                            <i class="bi bi-briefcase me-1"></i>Make Working Day
                          </button>
                        <?php endif; ?>
                      </form>

                      <button class="btn btn-sm btn-outline-secondary me-1 py-1 px-2 shadow-none" 
                              type="button"
                              title="Edit Holiday"
                              data-holiday='<?= htmlspecialchars(json_encode($h), ENT_QUOTES, 'UTF-8') ?>'
                              onclick="openEditFromData(this)">
                        <i class="bi bi-pencil"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-danger py-1 px-2 shadow-none" 
                              type="button"
                              title="Delete Holiday"
                              data-id="<?= $h['id'] ?>" 
                              data-name="<?= htmlspecialchars($h['name'], ENT_QUOTES, 'UTF-8') ?>"
                              onclick="confirmDeleteFromData(this)">
                        <i class="bi bi-trash"></i>
                      </button>
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

  <!-- Sunday Management & Weekend Override Section -->
  <div class="card border-0 shadow-sm mt-4" style="border-radius:16px; overflow:hidden;">
    <div class="card-header bg-white border-0 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2 <?= $showControlsSection ? '' : 'collapsed' ?>"
         role="button"
         data-bs-toggle="collapse"
         data-bs-target="#sundaysControlsCollapse"
         aria-expanded="<?= $showControlsSection ? 'true' : 'false' ?>"
         style="cursor:pointer;"
         title="Click to Collapse / Expand">
      <div>
        <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
          <i class="bi bi-sun text-warning me-2"></i>Sunday Holiday & Working Day Controls — <?= $selectedYear ?>
        </h5>
        <small class="text-muted">Inspect any date or Sunday to check its day, festival, and holiday status, and switch between Working Day and Holiday.</small>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-purple text-white px-3 py-2 rounded-pill"><?= count($allSundays) ?> Sundays</span>
        <?php if ($workingSundaysCount > 0): ?>
          <span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><?= $workingSundaysCount ?> Scheduled Working</span>
        <?php endif; ?>
        <button class="btn btn-sm btn-light border-0 p-1 rounded-circle d-flex align-items-center justify-content-center" 
                type="button" 
                onclick="event.stopPropagation();" 
                data-bs-toggle="collapse" 
                data-bs-target="#sundaysControlsCollapse"
                title="Collapse / Expand"
                style="width:30px; height:30px;">
          <span class="material-symbols-outlined section-chevron">expand_more</span>
        </button>
      </div>
    </div>
    
    <div class="collapse <?= $showControlsSection ? 'show' : '' ?>" id="sundaysControlsCollapse">
      <div class="card-body p-3 p-md-4 pt-0">

        <!-- Top Date Selection & Quick Jump Toolbar -->
        <div class="p-3 mb-4 rounded-4 border bg-light bg-opacity-75 shadow-sm">
          <div class="row g-3 align-items-center">
            <div class="col-lg-5">
              <label class="form-label small fw-bold text-dark mb-1 d-flex align-items-center">
                <i class="bi bi-calendar3 text-primary me-2"></i>Select Any Calendar Date:
              </label>
              <div class="input-group shadow-sm">
                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-calendar-event"></i></span>
                <input type="date" id="ctrlDateInput" class="form-control fw-semibold border-start-0 shadow-none ps-0" 
                       min="<?= $selectedYear ?>-01-01" max="<?= $selectedYear ?>-12-31" 
                       value="<?= e($checkedDate) ?>" onchange="updateDateControlCard(this.value)">
                <button type="button" class="btn btn-primary px-3 shadow-none fw-medium" onclick="updateDateControlCard('<?= date('Y-m-d') ?>')">
                  Today
                </button>
              </div>
            </div>

            <div class="col-lg-5">
              <label class="form-label small fw-bold text-dark mb-1 d-flex align-items-center">
                <i class="bi bi-sun text-warning me-2"></i>Quick Select Sunday (<?= count($allSundays) ?> in <?= $selectedYear ?>):
              </label>
              <div class="input-group shadow-sm">
                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-list-check"></i></span>
                <select id="ctrlSundayQuickSelect" class="form-select fw-semibold border-start-0 shadow-none ps-0" onchange="if(this.value){ updateDateControlCard(this.value); }">
                  <option value="">-- Jump to Sunday from List --</option>
                  <?php foreach ($allSundays as $sDate): 
                    $sTs = strtotime($sDate);
                    $isWorkSun = (isset($sundayOverrides[$sDate]) && $sundayOverrides[$sDate]['action'] === 'working_day');
                  ?>
                    <option value="<?= $sDate ?>" <?= $sDate === $checkedDate ? 'selected' : '' ?>>
                      <?= date('d M Y', $sTs) ?> — <?= $isWorkSun ? '⚡ Working Day' : '🌴 Holiday Off' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="col-lg-2 d-flex flex-wrap gap-1 justify-content-lg-end pt-lg-3">
              <button type="button" class="btn btn-sm ctrl-chip-btn shadow-none" onclick="jumpNextSunday(-1)" title="Previous Sunday">
                <i class="bi bi-chevron-left me-1"></i>Prev
              </button>
              <button type="button" class="btn btn-sm ctrl-chip-btn shadow-none" onclick="jumpNextSunday(1)" title="Next Sunday">
                Next<i class="bi bi-chevron-right ms-1"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- 2-Column Balanced Dashboard Console -->
        <div class="row g-4">
          <!-- Left Console: Live Date Intelligence & Status -->
          <div class="col-lg-7">
            <div id="ctrlHeroSurface" class="p-4 rounded-4 border h-100 transition-all status-theme-working">
              
              <!-- Header Row: Big Date Badge + Day Title + Glowing Pill -->
              <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div class="d-flex align-items-center gap-3">
                  <div class="ctrl-date-badge">
                    <div class="text-uppercase fw-bold text-muted" style="font-size:0.65rem; letter-spacing:0.5px;" id="dispMonthShort">SEP</div>
                    <div class="fw-bolder fs-3 text-dark lh-1 my-1" id="dispDayNumber">20</div>
                    <div class="text-muted fw-semibold" style="font-size:0.68rem;" id="dispYearShort">2026</div>
                  </div>
                  <div>
                    <div class="d-flex align-items-center gap-2">
                      <h4 class="fw-bold text-dark mb-0" id="dispDateFormatted">20 Sep 2026</h4>
                      <span id="dispDayBadge"></span>
                    </div>
                    <div class="text-muted small mt-1" id="dispDayCategory">Weekend Weekly Off</div>
                  </div>
                </div>

                <div id="dispStatusPill">
                  <!-- Dynamic Pill injected by JS -->
                </div>
              </div>

              <hr class="my-3 opacity-25">

              <!-- Festival Event Information Block -->
              <div class="mb-3">
                <label class="form-label small fw-bold text-muted text-uppercase mb-1" style="font-size:0.7rem; letter-spacing:0.5px;">
                  <i class="bi bi-calendar-event me-1 text-primary"></i>Festival & Calendar Event
                </label>
                <div id="dispFestivalStatus" class="mt-1">
                  <!-- Injected by JS -->
                </div>
              </div>

              <!-- System Attendance & Shift Impact Block -->
              <div class="p-3 rounded-3 bg-white bg-opacity-75 border shadow-sm mt-3">
                <div class="d-flex align-items-start gap-2">
                  <i class="bi bi-info-circle-fill text-primary mt-1 fs-5"></i>
                  <div>
                    <div class="fw-bold text-dark small" id="dispImpactTitle">Attendance & Shift Schedule Impact</div>
                    <div class="small text-muted" id="dispImpactDesc">
                      Loading schedule rules...
                    </div>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Right Console: Admin Control Action Panel -->
          <div class="col-lg-5">
            <div class="p-4 rounded-4 border bg-white shadow-sm h-100 d-flex flex-column justify-content-between">
              <div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="fw-bold text-dark mb-0 d-flex align-items-center">
                    <i class="bi bi-sliders text-primary me-2"></i>Change Schedule
                  </h6>
                  <span class="badge bg-light text-muted border px-2 py-1" style="font-size:0.72rem;">Live Admin Control</span>
                </div>
                
                <p class="text-muted small mb-3">
                  Toggle whether this date is an active working day (regular 7h 00m shift) or marked as an official holiday / weekly off for all employees.
                </p>

                <form method="POST" id="dateControlActionForm">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="toggle_date_status">
                  <input type="hidden" name="date" id="formCtrlDate" value="<?= e($checkedDate) ?>">
                  <input type="hidden" name="target_action" id="formCtrlTargetAction" value="working_day">

                  <div class="mb-3">
                    <label class="form-label small fw-semibold text-dark mb-1">Schedule Reason / Audit Note:</label>
                    <div class="input-group shadow-sm">
                      <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-chat-left-text"></i></span>
                      <input type="text" name="reason" id="formCtrlReason" class="form-control form-control-sm border-start-0 shadow-none ps-0" placeholder="e.g. Scheduled Working Sunday, Festival Shift, Special Off">
                    </div>
                    <div class="form-text" style="font-size:0.72rem;">Optional. Saved in audit log and attendance records.</div>
                  </div>

                  <div id="formCtrlBtnWrapper" class="mt-4">
                    <!-- Dynamic button injected by JS -->
                  </div>
                </form>
              </div>

              <div class="pt-3 mt-3 border-top text-center text-muted" style="font-size:0.74rem;">
                <i class="bi bi-shield-check text-success me-1"></i>Instant sync across employee attendance & payroll.
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

</div>


<!-- Modal: Add / Edit Holiday -->
<div class="modal fade" id="holidayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius:16px;">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" id="holidayModalTitle"><i class="bi bi-plus-circle text-primary me-2"></i>Add Holiday</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <form method="POST" id="holidayForm">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" id="holidayFormAction" value="add">
          <input type="hidden" name="id" id="holidayId" value="">

          <div class="mb-3">
            <label class="form-label small fw-semibold">Holiday Title *</label>
            <input type="text" name="name" id="holidayName" class="form-control" required placeholder="e.g. Diwali, Gandhi Jayanti, Annual Foundation Day">
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label small fw-semibold">Date *</label>
              <input type="date" name="holiday_date" id="holidayDate" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold">Type</label>
              <select name="holiday_type" id="holidayType" class="form-select">
                <option value="festival">Festival Holiday</option>
                <option value="national">National Holiday</option>
                <option value="company">Company / Optional Holiday</option>
              </select>
            </div>
          </div>

          <!-- Schedule / Day Status Selector -->
          <div class="mb-3">
            <label class="form-label small fw-semibold d-block">Day Status / Working Schedule *</label>
            <div class="row g-2">
              <div class="col-6">
                <input type="radio" class="btn-check" name="status" id="statusHoliday" value="active" checked>
                <label class="btn btn-outline-danger w-100 py-2 d-flex flex-column align-items-center justify-content-center text-center rounded-3 shadow-none" for="statusHoliday">
                  <i class="bi bi-calendar-x fs-5 mb-1"></i>
                  <span class="fw-bold small">Official Holiday</span>
                  <span style="font-size:0.68rem; opacity:0.85;">Office Closed / Chhutti</span>
                </label>
              </div>
              <div class="col-6">
                <input type="radio" class="btn-check" name="status" id="statusWorking" value="working_day">
                <label class="btn btn-outline-success w-100 py-2 d-flex flex-column align-items-center justify-content-center text-center rounded-3 shadow-none" for="statusWorking">
                  <i class="bi bi-briefcase fs-5 mb-1"></i>
                  <span class="fw-bold small">Normal Working Day</span>
                  <span style="font-size:0.68rem; opacity:0.85;">Office Open / Regular Day</span>
                </label>
              </div>
            </div>
            <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">
              <i class="bi bi-info-circle me-1"></i><strong>Normal Working Day</strong> select karne par yeh holiday nahi rahegi aur users/employees ke dashboard & attendance par normal regular working day show hoga.
            </small>
          </div>

          <div class="mb-4">
            <label class="form-label small fw-semibold">Description / Notes</label>
            <textarea name="description" id="holidayDescription" class="form-control" rows="2" placeholder="Optional description or details..."></textarea>
          </div>

          <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" id="holidaySubmitBtn">Save Holiday</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Delete Confirmation -->
<div class="modal fade" id="deleteHolidayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow" style="border-radius:16px;">
      <div class="modal-body p-4 text-center">
        <div class="text-danger mb-3"><i class="bi bi-exclamation-triangle fs-1"></i></div>
        <h5 class="fw-bold mb-2">Delete Holiday?</h5>
        <p class="text-muted small mb-4" id="deleteHolidayMsg"></p>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" id="deleteHolidayId" value="">
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-light w-50 py-2" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger w-50 py-2 fw-semibold">Delete</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function filterHolidayTable(type, btn) {
  document.querySelectorAll('.holiday-filter-btn').forEach(function(b) {
    b.classList.remove('active');
  });
  if (btn) btn.classList.add('active');
  
  const rows = document.querySelectorAll('.holiday-row');
  rows.forEach(function(r) {
    if (type === 'all' || r.getAttribute('data-status-type') === type) {
      r.style.display = '';
    } else {
      r.style.display = 'none';
    }
  });
}

function openAddHolidayModal() {
  document.getElementById('holidayFormAction').value = 'add';
  document.getElementById('holidayId').value = '';
  document.getElementById('holidayName').value = '';
  document.getElementById('holidayDate').value = '<?= $selectedYear ?>-' + '<?= date("m-d") ?>';
  document.getElementById('holidayType').value = 'festival';
  document.getElementById('statusHoliday').checked = true;
  document.getElementById('statusWorking').checked = false;
  document.getElementById('holidayDescription').value = '';
  document.getElementById('holidayModalTitle').innerHTML = '<i class="bi bi-plus-circle text-primary me-2"></i>Add Holiday';
  document.getElementById('holidaySubmitBtn').textContent = 'Create Holiday';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('holidayModal')).show();
}

function openEditFromData(btn) {
  try {
    const raw = btn.getAttribute('data-holiday');
    const h = JSON.parse(raw);
    document.getElementById('holidayFormAction').value = 'edit';
    document.getElementById('holidayId').value = h.id;
    document.getElementById('holidayName').value = h.name || '';
    document.getElementById('holidayDate').value = h.holiday_date || '';
    document.getElementById('holidayType').value = h.holiday_type || 'festival';
    if (h.status === 'working_day') {
      document.getElementById('statusWorking').checked = true;
      document.getElementById('statusHoliday').checked = false;
    } else {
      document.getElementById('statusHoliday').checked = true;
      document.getElementById('statusWorking').checked = false;
    }
    document.getElementById('holidayDescription').value = h.description || '';
    document.getElementById('holidayModalTitle').innerHTML = '<i class="bi bi-pencil text-primary me-2"></i>Edit Holiday';
    document.getElementById('holidaySubmitBtn').textContent = 'Update Holiday';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('holidayModal')).show();
  } catch (err) {
    console.error('Failed to parse holiday data:', err);
  }
}

function confirmDeleteFromData(btn) {
  const id = btn.getAttribute('data-id');
  const name = btn.getAttribute('data-name');
  document.getElementById('deleteHolidayId').value = id;
  document.getElementById('deleteHolidayMsg').textContent = 'Are you sure you want to delete "' + name + '"?';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteHolidayModal')).show();
}

// -------------------------------------------------------------------
// Single Card Interactive Date Controller (Sunday & Date Override)
// -------------------------------------------------------------------
const calendarConfig = {
  year: <?= $selectedYear ?>,
  holidays: <?= json_encode($holidays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
  overrides: <?= json_encode($sundayOverrides, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
  allSundays: <?= json_encode($allSundays) ?>
};

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function updateDateControlCard(dateStr) {
  if (!dateStr) return;
  
  // 1. Sync input elements
  const dateInput = document.getElementById('ctrlDateInput');
  const formDate = document.getElementById('formCtrlDate');
  if (dateInput) dateInput.value = dateStr;
  if (formDate) formDate.value = dateStr;
  
  const quickSelect = document.getElementById('ctrlSundayQuickSelect');
  if (quickSelect) {
    let matched = false;
    for (let i = 0; i < quickSelect.options.length; i++) {
      if (quickSelect.options[i].value === dateStr) {
        quickSelect.selectedIndex = i;
        matched = true;
        break;
      }
    }
    if (!matched) quickSelect.selectedIndex = 0;
  }

  // 2. Parse Date safely
  const parts = dateStr.split('-');
  const y = parseInt(parts[0], 10);
  const m = parseInt(parts[1], 10) - 1;
  const d = parseInt(parts[2], 10);
  const dt = new Date(y, m, d);

  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const monthsFull = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  const dayName = days[dt.getDay()];
  const isSunday = (dt.getDay() === 0);
  const formattedDate = String(d).padStart(2, '0') + ' ' + months[m] + ' ' + y;

  // 3. Update Calendar Badge & Date Header
  const dispMonthShort = document.getElementById('dispMonthShort');
  const dispDayNumber = document.getElementById('dispDayNumber');
  const dispYearShort = document.getElementById('dispYearShort');
  if (dispMonthShort) dispMonthShort.textContent = months[m].toUpperCase();
  if (dispDayNumber) dispDayNumber.textContent = String(d).padStart(2, '0');
  if (dispYearShort) dispYearShort.textContent = y;

  const dispDateEl = document.getElementById('dispDateFormatted');
  const dispDayEl = document.getElementById('dispDayBadge');
  const dispCategoryEl = document.getElementById('dispDayCategory');
  if (dispDateEl) dispDateEl.textContent = dayName + ', ' + d + ' ' + monthsFull[m] + ' ' + y;
  if (dispDayEl) {
    if (isSunday) {
      dispDayEl.innerHTML = '<span class="badge bg-danger text-white px-2 py-1 shadow-sm"><i class="bi bi-sun me-1"></i>Sunday</span>';
    } else {
      dispDayEl.innerHTML = '<span class="badge bg-secondary text-white px-2 py-1">' + dayName + '</span>';
    }
  }
  if (dispCategoryEl) {
    dispCategoryEl.textContent = isSunday ? 'Weekly Weekend Off (Standard Sunday)' : 'Regular Weekday (Monday to Saturday)';
  }

  // 4. Festival Search
  let foundHoliday = null;
  if (calendarConfig.holidays && Array.isArray(calendarConfig.holidays)) {
    for (let h of calendarConfig.holidays) {
      if (h.holiday_date === dateStr) {
        foundHoliday = h;
        break;
      }
    }
  }

  const dispFestivalEl = document.getElementById('dispFestivalStatus');
  if (dispFestivalEl) {
    if (foundHoliday) {
      dispFestivalEl.innerHTML = `
        <div class="d-flex align-items-center gap-2">
          <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-3 py-1 fs-6">
            <i class="bi bi-stars me-1 text-warning"></i>${escapeHtml(foundHoliday.name)}
          </span>
          <span class="badge bg-light text-muted border text-capitalize">${escapeHtml(foundHoliday.holiday_type || 'Festival')}</span>
        </div>
        <div class="small text-muted mt-1">
          ${foundHoliday.description ? escapeHtml(foundHoliday.description) : 'Annual gazetted festival / official company holiday.'}
        </div>
      `;
    } else {
      dispFestivalEl.innerHTML = `
        <span class="badge bg-light text-muted border px-2 py-1">
          <i class="bi bi-calendar-x me-1"></i>No Festival Scheduled
        </span>
        <span class="small text-muted ms-2">Standard calendar day with no special festival event.</span>
      `;
    }
  }

  // 5. Current Operational Status for Employees
  const override = calendarConfig.overrides ? calendarConfig.overrides[dateStr] : null;
  let currentStatus = 'working_day';
  let statusReason = '';

  if (override && override.action === 'working_day') {
    currentStatus = 'working_day';
    statusReason = override.reason || (isSunday ? 'Scheduled Working Sunday' : 'Admin Scheduled Working Day');
  } else if (override && override.action === 'holiday') {
    currentStatus = 'holiday';
    statusReason = override.reason || 'Admin Scheduled Holiday / Off';
  } else if (foundHoliday) {
    if (foundHoliday.status === 'working_day') {
      currentStatus = 'working_day';
      statusReason = foundHoliday.name + ' (Scheduled as Normal Working Day)';
    } else {
      currentStatus = 'holiday';
      statusReason = foundHoliday.name + ' (Official Holiday)';
    }
  } else if (isSunday) {
    currentStatus = 'holiday';
    statusReason = 'Sunday Weekly Off (Office Closed)';
  } else {
    currentStatus = 'working_day';
    statusReason = 'Regular Working Day';
  }

  // Update Hero Surface Theme & Status Pill
  const heroSurface = document.getElementById('ctrlHeroSurface');
  const dispStatusPill = document.getElementById('dispStatusPill');
  const dispImpactTitle = document.getElementById('dispImpactTitle');
  const dispImpactDesc = document.getElementById('dispImpactDesc');

  if (currentStatus === 'working_day') {
    if (heroSurface) {
      heroSurface.className = "p-4 rounded-4 border h-100 transition-all status-theme-working";
    }
    if (dispStatusPill) {
      dispStatusPill.innerHTML = `
        <span class="badge bg-success text-white py-2 px-3 fs-6 shadow-sm d-inline-flex align-items-center gap-1">
          <i class="bi bi-briefcase-fill"></i> Normal Working Day
        </span>
      `;
    }
    if (dispImpactTitle) dispImpactTitle.textContent = "Active Work Shift (7h 00m Standard Target)";
    if (dispImpactDesc) dispImpactDesc.textContent = "Employees see a regular working day on their dashboard and attendance calendar. Punch-in records are tracked without holiday deduction.";
  } else {
    if (heroSurface) {
      heroSurface.className = "p-4 rounded-4 border h-100 transition-all status-theme-holiday";
    }
    if (dispStatusPill) {
      dispStatusPill.innerHTML = `
        <span class="badge bg-danger text-white py-2 px-3 fs-6 shadow-sm d-inline-flex align-items-center gap-1">
          <i class="bi bi-sun-fill"></i> Holiday / Weekly Off
        </span>
      `;
    }
    if (dispImpactTitle) dispImpactTitle.textContent = "Office Closed / Non-Working Day";
    if (dispImpactDesc) dispImpactDesc.textContent = "No shift target hours apply. Employee attendance calendar reflects Holiday (H) / Off with zero penalty.";
  }

  // 6. Admin Action Form Setup
  const formTargetAction = document.getElementById('formCtrlTargetAction');
  const formReason = document.getElementById('formCtrlReason');
  const btnWrapper = document.getElementById('formCtrlBtnWrapper');

  if (formTargetAction && formReason && btnWrapper) {
    if (currentStatus === 'working_day') {
      formTargetAction.value = 'holiday';
      formReason.value = '';
      formReason.placeholder = isSunday ? 'Revert to Sunday Weekly Off' : 'Reason for marking as Holiday';
      btnWrapper.innerHTML = `
        <button type="submit" class="btn btn-danger w-100 fw-semibold shadow py-2 d-flex align-items-center justify-content-center gap-2">
          <i class="bi bi-arrow-counterclockwise fs-5"></i>
          <span>Mark as Holiday / Revert to Off</span>
        </button>
      `;
    } else {
      formTargetAction.value = 'working_day';
      formReason.value = '';
      formReason.placeholder = isSunday ? 'Scheduled Working Sunday' : 'Reason for working day schedule';
      btnWrapper.innerHTML = `
        <button type="submit" class="btn btn-success w-100 fw-semibold shadow py-2 d-flex align-items-center justify-content-center gap-2">
          <i class="bi bi-briefcase-fill fs-5"></i>
          <span>Schedule as Working Day</span>
        </button>
      `;
    }
  }
}

// Shortcut helper to jump to prev/next Sunday
function jumpNextSunday(offset) {
  const curDate = document.getElementById('ctrlDateInput').value;
  const sundays = calendarConfig.allSundays || [];
  if (!sundays.length) return;
  
  let idx = sundays.indexOf(curDate);
  if (idx === -1) {
    for (let i = 0; i < sundays.length; i++) {
      if (sundays[i] >= curDate) {
        idx = i;
        break;
      }
    }
    if (idx === -1) idx = sundays.length - 1;
  } else {
    idx += offset;
  }
  
  if (idx < 0) idx = 0;
  if (idx >= sundays.length) idx = sundays.length - 1;
  
  updateDateControlCard(sundays[idx]);
}

// Auto-initialize on load
document.addEventListener('DOMContentLoaded', function() {
  updateDateControlCard('<?= e($checkedDate) ?>');
});
</script>

<?php require_once '../../includes/footer.php'; ?>
