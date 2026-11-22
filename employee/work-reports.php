<?php
// employee/work-reports.php — Employee Daily Work Reports (Submit, View, Edit & Resubmit)
require_once __DIR__ . '/layout.php';

$empId = (int)($_SESSION['employee_id'] ?? 0);
if ($empId <= 0) {
    abort_forbidden("No verified employee profile linked to your account.");
}
$csrf  = csrf_token();
$action = $_GET['action'] ?? 'list';
$err   = '';

// ─────────────────────────────────────────────
// HANDLE POST: Submit / Update Report
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token.";
    } else {
        $reportDate     = $_POST['report_date'] ?? date('Y-m-d');
        $morningTasks   = trim($_POST['morning_tasks'] ?? '');
        $tasksCompleted = trim($_POST['tasks_completed'] ?? '');
        $projectsWorked = trim($_POST['projects_worked'] ?? '');
        
        // Strict requirement: automatically compute hours worked from attendance minus lunch breaks
        $durCalc        = calculate_work_report_duration($pdo, $empId, $reportDate);
        $hoursWorked    = (float)$durCalc['hours'];
        
        $challenges     = trim($_POST['challenges'] ?? '');
        $tomorrowPlan   = trim($_POST['tomorrow_plan'] ?? '');
        $status         = $_POST['save_status'] ?? 'submitted'; // draft or submitted

        // Validation
        if (strtotime($reportDate) > strtotime(date('Y-m-d'))) {
            $err = "You cannot submit a work report for a future date (" . date('d M Y', strtotime($reportDate)) . ").";
        } elseif (empty($tasksCompleted)) {
            $err = "Please enter tasks completed today.";
        } elseif (!is_admin() && empty($durCalc['is_clocked_out'])) {
            $err = "Work report submit karne se pehle Punch-Out karna zaroori hai. Kripya pehle punch-out karein, phir report submit karein.";
        } else {
            // Check if report is locked (already reviewed or finalized)
            $checkStmt = $pdo->prepare("SELECT * FROM daily_work_reports WHERE employee_id = ? AND report_date = ?");
            $checkStmt->execute([$empId, $reportDate]);
            $existing = $checkStmt->fetch();

            $checkWr = $pdo->prepare("SELECT * FROM work_reports WHERE employee_id = ? AND report_date = ?");
            $checkWr->execute([$empId, $reportDate]);
            $existingWr = $checkWr->fetch();

            // Check if report has revision requested
            $isRevisionRequested = false;
            if (($existing && ($existing['status'] ?? '') === 'revision_requested') || 
                ($existingWr && ($existingWr['status'] ?? '') === 'revision_requested') ||
                ($existing && !empty($existing['review_note']) && stripos($existing['review_note'], 'revision') !== false)) {
                $isRevisionRequested = true;
            }

            $isLocked = false;
            if (!$isRevisionRequested) {
                if ($existing) {
                    if (!empty($existing['rating']) || !empty($existing['reviewed_by']) || !empty($existing['reviewed_at']) || !empty($existing['review_note'])) {
                        $isLocked = true;
                    }
                }
                if (!$isLocked && $existingWr) {
                    $st = strtolower($existingWr['status'] ?? 'submitted');
                    if (!in_array($st, ['submitted', 'draft', 'pending', '']) || !empty($existingWr['reviewed_by']) || !empty($existingWr['reviewed_at']) || !empty($existingWr['review_comment'])) {
                        $isLocked = true;
                    }
                }
            }

            if ($isLocked && !is_admin()) {
                $err = "This report has been reviewed or locked and cannot be modified.";
            } else {
                $sql = "INSERT INTO daily_work_reports 
                          (employee_id, report_date, morning_tasks, evening_tasks, projects_worked, hours_worked, blockers, plan_for_tomorrow, status, rating, reviewed_by, reviewed_at, review_note)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NULL, NULL, NULL, NULL)
                        ON DUPLICATE KEY UPDATE
                          morning_tasks=VALUES(morning_tasks), evening_tasks=VALUES(evening_tasks),
                          projects_worked=VALUES(projects_worked), hours_worked=VALUES(hours_worked),
                          blockers=VALUES(blockers), plan_for_tomorrow=VALUES(plan_for_tomorrow),
                          status='submitted', rating=NULL, reviewed_by=NULL, reviewed_at=NULL,
                          updated_at=NOW()";
                $pdo->prepare($sql)->execute([
                    $empId, $reportDate, $morningTasks, $tasksCompleted, $projectsWorked,
                    $hoursWorked, $challenges, $tomorrowPlan
                ]);

                // Also update work_reports table
                $wrStatus = ($status === 'draft') ? 'draft' : 'submitted';
                $sqlWr = "INSERT INTO work_reports
                            (employee_id, report_date, summary, tasks_completed, projects_worked, hours_worked, challenges, tomorrow_plan, status, submitted_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                          ON DUPLICATE KEY UPDATE
                            summary=VALUES(summary), tasks_completed=VALUES(tasks_completed),
                            projects_worked=VALUES(projects_worked), hours_worked=VALUES(hours_worked),
                            challenges=VALUES(challenges), tomorrow_plan=VALUES(tomorrow_plan),
                            status=VALUES(status), reviewed_by=NULL, reviewed_at=NULL, review_comment=NULL, updated_at=NOW()";
                $pdo->prepare($sqlWr)->execute([
                    $empId, $reportDate, $morningTasks, $tasksCompleted, $projectsWorked,
                    $hoursWorked, $challenges, $tomorrowPlan, $wrStatus
                ]);

                $isUpdateAction = !empty($existing) || !empty($existingWr);
                $reportUserId = $_SESSION['user_id'] ?? get_user_id_by_employee_id($pdo, $empId);
                if ($reportUserId) {
                    $notifTitle = $isRevisionRequested ? 'Report Revised & Resubmitted' : ($isUpdateAction ? 'Report Edited' : 'Report Submitted');
                    $notifMsg = $isRevisionRequested
                        ? "Your revised work report for " . date('d M Y', strtotime($reportDate)) . " has been submitted to management for review."
                        : ($isUpdateAction 
                            ? "Your work report for " . date('d M Y', strtotime($reportDate)) . " was updated successfully."
                            : "Your work report for " . date('d M Y', strtotime($reportDate)) . " was submitted successfully.");
                    create_action_notification($pdo, (int)$reportUserId, $notifTitle, $notifMsg, '/employee/work-reports.php', 'report_submitted');
                }

                set_flash("Daily work report for " . date('d M Y', strtotime($reportDate)) . " submitted successfully!", "success");
                header('Location: ' . BASE_URL . 'employee/work-reports.php');
                exit;
            }
        }
    }
}

// If edit or create requested
$editDate = $_GET['date'] ?? date('Y-m-d');
$editRep = null;
$calcDuration = null;
$isEditLocked = false;
$isRevisionRequested = false;
if ($action === 'create' || $action === 'edit') {
    $eStmt = $pdo->prepare("SELECT * FROM daily_work_reports WHERE employee_id = ? AND report_date = ?");
    $eStmt->execute([$empId, $editDate]);
    $editRep = $eStmt->fetch();
    
    $wrStmt = $pdo->prepare("SELECT * FROM work_reports WHERE employee_id = ? AND report_date = ?");
    $wrStmt->execute([$empId, $editDate]);
    $editWr = $wrStmt->fetch();

    if (($editRep && ($editRep['status'] ?? '') === 'revision_requested') || 
        ($editWr && ($editWr['status'] ?? '') === 'revision_requested') ||
        ($editRep && !empty($editRep['review_note']) && stripos($editRep['review_note'], 'revision') !== false)) {
        $isRevisionRequested = true;
    }

    if (!$isRevisionRequested) {
        if ($editRep) {
            if (!empty($editRep['rating']) || !empty($editRep['reviewed_by']) || !empty($editRep['reviewed_at']) || !empty($editRep['review_note'])) {
                $isEditLocked = true;
            }
        }
        if (!$isEditLocked && $editWr) {
            $st = strtolower($editWr['status'] ?? 'submitted');
            if (!in_array($st, ['submitted', 'draft', 'pending', '']) || !empty($editWr['reviewed_by']) || !empty($editWr['reviewed_at']) || !empty($editWr['review_comment'])) {
                $isEditLocked = true;
            }
        }
    }
    if (is_admin()) {
        $isEditLocked = false;
    }

    $targetDate = $editRep['report_date'] ?? $editDate;
    $calcDuration = calculate_work_report_duration($pdo, $empId, $targetDate);
}

// Fetch all work reports for employee
$listStmt = $pdo->prepare("
    SELECT dwr.*, wr.status as wr_status, wr.reviewed_by as wr_reviewed_by, wr.reviewed_at as wr_reviewed_at, wr.review_comment as wr_review_comment
    FROM daily_work_reports dwr
    LEFT JOIN work_reports wr ON wr.employee_id = dwr.employee_id AND wr.report_date = dwr.report_date
    WHERE dwr.employee_id = ? 
    ORDER BY dwr.report_date DESC 
    LIMIT 60
");
$listStmt->execute([$empId]);
$reports = $listStmt->fetchAll();
?>

<div class="container-fluid px-0">

  <!-- Flash message -->
  <?php $flash = get_flash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-check-circle-fill me-2"></i><?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if ($err): ?>
  <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($err) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Top Bar -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-journal-text text-success me-2"></i>Daily Work Reports</h3>
      <p class="text-muted mb-0 small">Log your daily completed tasks, project details, and tomorrow's targets</p>
    </div>
    <div class="d-flex gap-2">
      <?php if ($action !== 'list'): ?>
        <a href="<?= BASE_URL ?>employee/work-reports.php" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-list-ul me-1"></i> View All Reports
        </a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>employee/work-reports.php?action=create" class="btn btn-sm btn-success">
          <i class="bi bi-plus-circle me-1"></i> Submit Work Report
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($action === 'create' || $action === 'edit'): ?>
  <!-- REPORT FORM CARD -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
    <div class="card-header bg-white border-0 py-3">
      <h5 class="fw-bold text-dark mb-0">
        <i class="bi bi-pencil-square text-success me-2"></i>
        <?= $editRep ? 'Edit Daily Work Report' : 'Submit Evening Work Report' ?>
      </h5>
    </div>
    <div class="card-body p-4">

      <?php if ($isRevisionRequested): ?>
        <div class="alert alert-warning border border-warning shadow-sm rounded-3 mb-4 p-3" style="background:#fffbeb; border-left: 5px solid #f59e0b !important;">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-arrow-repeat text-warning-emphasis fs-3"></i>
            <div>
              <div class="fw-bold text-warning-emphasis fs-6">Revision Requested by Admin (Check your report again & submit again)</div>
              <div class="text-dark small mt-1">Admin ne aapki report me sudhar (revision) maanga hai. Kripya niche diye gaye review feedback ke mutabik apni report ko edit karein aur dobara submit karein.</div>
              <?php 
                $revComment = $editRep['review_note'] ?? ($editWr['review_comment'] ?? '');
                if (!empty($revComment)): 
              ?>
                <div class="mt-2 p-2 bg-white rounded border border-warning-subtle text-dark fw-medium small">
                  <i class="bi bi-chat-left-quote-fill me-1 text-warning"></i> <strong>Admin Instructions:</strong> <?= nl2br(e($revComment)) ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!is_admin() && empty($calcDuration['is_clocked_out'])): ?>
        <div id="punchOutRequiredAlert" class="alert alert-danger border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4 rounded-3 p-3" style="background:#fef2f2; border-left: 4px solid #dc2626 !important;">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-exclamation-octagon-fill text-danger fs-3"></i>
            <div>
              <div class="fw-bold text-danger">Punch-Out Required Before Submitting Work Report</div>
              <div class="text-dark small">Work report submit karne se pehle Punch-Out karna anivarya hai. Kripya pehle Dashboard par jakar Punch-Out karein, tabhi report submit ho payegi.</div>
            </div>
          </div>
          <a href="<?= BASE_URL ?>employee/dashboard.php" class="btn btn-sm btn-danger fw-semibold px-3 py-2 text-nowrap">
            <i class="bi bi-box-arrow-right me-1"></i> Go to Dashboard & Punch-Out
          </a>
        </div>
      <?php endif; ?>

      <?php if ($isEditLocked): ?>
        <div class="alert alert-secondary border d-flex align-items-center gap-2 mb-4 rounded-3">
          <i class="bi bi-lock-fill text-dark fs-4"></i>
          <div>
            <div class="fw-bold text-dark">This Work Report is Locked</div>
            <div class="small text-muted">This report has been reviewed or finalized by management and cannot be modified.</div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!$isRevisionRequested && $editRep && !empty($editRep['review_note'])): ?>
        <div class="alert alert-warning border mb-4 rounded-3">
          <div class="fw-bold text-warning-emphasis"><i class="bi bi-chat-left-dots-fill me-1"></i> Manager / Admin Review Note:</div>
          <div class="mt-1 text-dark small"><?= nl2br(e($editRep['review_note'])) ?></div>
        </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Report Date <span class="text-danger">*</span></label>
            <input type="date" name="report_date" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e($editRep['report_date'] ?? $editDate) ?>" required <?= ($editRep && !is_admin()) ? 'readonly' : '' ?> <?= $isEditLocked ? 'disabled' : '' ?>>
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Hours Worked (Auto Computed)</label>
            <input type="text" id="hoursWorkedDisplay" class="form-control bg-light font-monospace" value="<?= $calcDuration ? ($calcDuration['duration_formatted'] . ' (' . number_format($calcDuration['hours'], 2) . 'h)') : '0h 00m (0.00h)' ?>" readonly>
            <input type="hidden" name="hours_worked" id="hoursWorkedInput" value="<?= $calcDuration ? $calcDuration['hours'] : 0.00 ?>">
            <small class="text-muted" style="font-size:0.7rem;">Calculated from attendance punch minus breaks</small>
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Projects / Clients Handled</label>
            <input type="text" name="projects_worked" class="form-control" placeholder="e.g. 3D LED Fascia Board, Highway Billboard" value="<?= e($editRep['projects_worked'] ?? '') ?>" <?= $isEditLocked ? 'disabled' : '' ?>>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold"><i class="bi bi-sun text-warning me-1"></i> Morning Plan / Work Started</label>
          <textarea name="morning_tasks" class="form-control" rows="2" placeholder="Tasks planned or initialized during the morning session..." <?= $isEditLocked ? 'disabled' : '' ?>><?= e($editRep['morning_tasks'] ?? '') ?></textarea>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold"><i class="bi bi-moon text-primary me-1"></i> Evening Work Completed <span class="text-danger">*</span></label>
          <textarea name="tasks_completed" class="form-control" rows="4" placeholder="Detail the work done today (e.g. laser cutting acrylic letters, wiring LEDs, onsite structural mounting, artwork design, etc.)..." required <?= $isEditLocked ? 'disabled' : '' ?>><?= e($editRep['evening_tasks'] ?? '') ?></textarea>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <label class="form-label small fw-semibold"><i class="bi bi-exclamation-triangle text-danger me-1"></i> Challenges / Technical Blockers</label>
            <textarea name="challenges" class="form-control" rows="2" placeholder="Any delays, material shortage, power issues, or dependency..." <?= $isEditLocked ? 'disabled' : '' ?>><?= e($editRep['blockers'] ?? '') ?></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold"><i class="bi bi-arrow-right-circle text-success me-1"></i> Tomorrow's Target</label>
            <textarea name="tomorrow_plan" class="form-control" rows="2" placeholder="What will you work on tomorrow..." <?= $isEditLocked ? 'disabled' : '' ?>><?= e($editRep['plan_for_tomorrow'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center" id="formActionButtons">
          <?php if ($isEditLocked): ?>
            <button type="button" class="btn btn-secondary px-4 py-2 fw-semibold" disabled>
              <i class="bi bi-lock-fill me-1"></i> Locked (Cannot Edit)
            </button>
            <a href="<?= BASE_URL ?>employee/work-reports.php" class="btn btn-outline-secondary px-3 py-2">Back to Reports</a>
          <?php elseif (!is_admin() && empty($calcDuration['is_clocked_out'])): ?>
            <button type="button" class="btn btn-danger px-4 py-2 fw-semibold" id="btnBlockedPunchOut" onclick="alert('Work report submit karne se pehle Punch-Out karna zaroori hai. Kripya pehle Dashboard par jakar Punch-Out karein.');">
              <i class="bi bi-lock-fill me-1"></i> Punch-Out Required to Submit
            </button>
            <a href="<?= BASE_URL ?>employee/dashboard.php" class="btn btn-outline-danger px-3 py-2 fw-semibold">
              <i class="bi bi-box-arrow-right me-1"></i> Punch-Out Now
            </a>
            <a href="<?= BASE_URL ?>employee/work-reports.php" class="btn btn-light border px-3 py-2">Cancel</a>
          <?php else: ?>
            <button type="submit" name="save_status" value="submitted" class="btn btn-success px-4 py-2 fw-semibold" id="btnSubmitReport">
              <i class="bi bi-send-check me-1"></i> Submit Report
            </button>
            <button type="submit" name="save_status" value="draft" class="btn btn-outline-secondary px-3 py-2 fw-semibold" id="btnDraftReport">
              Save as Draft
            </button>
            <a href="<?= BASE_URL ?>employee/work-reports.php" class="btn btn-light border px-3 py-2">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- PAST REPORTS LIST -->
  <div class="card border-0 shadow-sm" style="border-radius:16px;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
      <h5 class="fw-bold text-dark mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>My Submitted Reports</h5>
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted small d-md-none" style="font-size:0.72rem;"><i class="bi bi-arrow-left-right me-1"></i>Scrollable</span>
        <span class="badge bg-light text-muted border"><?= count($reports) ?> Reports</span>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem; min-width: 780px;">
          <thead class="table-light">
            <tr>
              <th class="ps-4 text-nowrap">Report Date</th>
              <th class="text-nowrap">Work Completed</th>
              <th class="text-nowrap">Projects Handled</th>
              <th class="text-nowrap">Hours</th>
              <th class="text-nowrap">Status</th>
              <th class="text-nowrap">Manager Review</th>
              <th class="pe-4 text-end text-nowrap">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($reports)): ?>
              <tr><td colspan="7" class="text-center text-muted py-5">No daily work reports submitted yet.</td></tr>
            <?php else: ?>
              <?php foreach ($reports as $r): 
                $isRevision = (($r['status'] ?? '') === 'revision_requested' || ($r['wr_status'] ?? '') === 'revision_requested' || (!empty($r['review_note']) && stripos($r['review_note'], 'revision') !== false));
                $isRejected = (($r['status'] ?? '') === 'rejected' || ($r['wr_status'] ?? '') === 'rejected');
                $isApproved = (!$isRevision && !$isRejected && ((!empty($r['rating']) && (int)$r['rating'] >= 4) || strtolower($r['wr_status'] ?? '') === 'approved' || ($r['status'] ?? '') === 'approved'));
                $rowLocked = !$isRevision && ($isApproved || $isRejected || (!empty($r['rating']) || !empty($r['reviewed_by']) || !empty($r['reviewed_at']) || !empty($r['review_note']) || (!empty($r['wr_status']) && !in_array(strtolower($r['wr_status']), ['submitted', 'draft', 'pending', ''])) || !empty($r['wr_reviewed_by']) || !empty($r['wr_review_comment'])));
                $isEdited = !empty($r['updated_at']) && !empty($r['submitted_at']) && (strtotime($r['updated_at']) > strtotime($r['submitted_at']) + 60);
              ?>
              <tr>
                <td class="ps-4 fw-semibold text-nowrap">
                  <div><?= date('d M Y', strtotime($r['report_date'])) ?></div>
                  <div class="text-muted" style="font-size:0.7rem; font-weight:normal;">
                    <i class="bi bi-clock me-1"></i><?= !empty($r['submitted_at']) ? date('h:i A', strtotime($r['submitted_at'])) : '—' ?>
                  </div>
                  <?php if ($isEdited): ?>
                    <div class="text-warning-emphasis" style="font-size:0.68rem; font-weight:500;" title="Edited on <?= date('d M Y, h:i A', strtotime($r['updated_at'])) ?>">
                      <i class="bi bi-pencil-square me-1"></i>Edited: <?= date('d M, h:i A', strtotime($r['updated_at'])) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td style="max-width:320px;">
                  <div class="text-dark fw-medium text-truncate"><?= e($r['evening_tasks'] ?? $r['morning_tasks'] ?? '—') ?></div>
                  <?php if (!empty($r['plan_for_tomorrow'])): ?>
                    <div class="text-muted small text-truncate" style="font-size:0.72rem;">Tomorrow: <?= e($r['plan_for_tomorrow']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap text-primary small fw-medium"><?= e($r['projects_worked'] ?? '—') ?></td>
                <td class="text-nowrap"><?= number_format((float)$r['hours_worked'], 1) ?>h</td>
                <td>
                  <?php if ($isRevision): ?>
                    <span class="badge bg-warning text-dark border border-warning-subtle px-2 py-1 rounded-pill">
                      <i class="bi bi-arrow-repeat me-1"></i>Revision Requested
                    </span>
                  <?php elseif ($isRejected): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill">
                      <i class="bi bi-x-circle me-1"></i>Rejected
                    </span>
                  <?php elseif ($isApproved || $rowLocked): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill">
                      <i class="bi bi-check-circle-fill me-1"></i>Approved
                    </span>
                  <?php else: ?>
                    <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1 rounded-pill">
                      Submitted
                    </span>
                  <?php endif; ?>
                </td>
                <td style="max-width:200px;">
                  <?php if (!empty($r['review_note'])): ?>
                    <div class="text-dark small text-truncate" title="<?= e($r['review_note']) ?>"><i class="bi bi-chat-text text-muted me-1"></i><?= e($r['review_note']) ?></div>
                    <?php if ($r['rating']): ?>
                      <div class="text-warning small" style="font-size:0.7rem;">★ <?= $r['rating'] ?>/5</div>
                    <?php endif; ?>
                  <?php elseif (!empty($r['wr_review_comment'])): ?>
                    <div class="text-dark small text-truncate" title="<?= e($r['wr_review_comment']) ?>"><i class="bi bi-chat-text text-muted me-1"></i><?= e($r['wr_review_comment']) ?></div>
                  <?php else: ?>
                    <span class="text-muted small">Pending Review</span>
                  <?php endif; ?>
                </td>
                <td class="pe-4 text-end">
                  <?php if ($isRevision): ?>
                    <a href="<?= BASE_URL ?>employee/work-reports.php?action=edit&date=<?= $r['report_date'] ?>" class="btn btn-sm btn-warning text-dark fw-bold shadow-sm" title="Check report and submit again">
                      <i class="bi bi-pencil-square me-1"></i> Edit & Resubmit
                    </a>
                  <?php elseif ($rowLocked): ?>
                    <a href="<?= BASE_URL ?>employee/work-reports.php?action=edit&date=<?= $r['report_date'] ?>" class="btn btn-sm btn-outline-secondary" title="View Report (Locked)">
                      <i class="bi bi-eye"></i> View
                    </a>
                  <?php else: ?>
                    <a href="<?= BASE_URL ?>employee/work-reports.php?action=edit&date=<?= $r['report_date'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit Report">
                      <i class="bi bi-pencil"></i> Edit
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<script>
document.querySelector('input[name="report_date"]')?.addEventListener('change', function() {
  const d = this.value;
  const disp = document.getElementById('hoursWorkedDisplay');
  const hid = document.getElementById('hoursWorkedInput');
  const alertBox = document.getElementById('punchOutRequiredAlert');
  const btnBlocked = document.getElementById('btnBlockedPunchOut');
  const btnSubmit = document.getElementById('btnSubmitReport');
  const btnDraft = document.getElementById('btnDraftReport');
  const isAdmin = <?= is_admin() ? 'true' : 'false' ?>;

  if (disp) disp.value = 'Calculating...';

  fetch(`<?= BASE_URL ?>modules/team/attendance_api.php?action=get_calculated_duration&employee_id=<?= $empId ?>&date=${d}`)
    .then(r => r.json())
    .then(res => {
      if (res.success && res.data) {
        if (disp) disp.value = `${res.data.duration_formatted} (${parseFloat(res.data.hours).toFixed(2)}h)`;
        if (hid) hid.value = res.data.hours;

        // Punch out check
        if (!isAdmin) {
          const isClockedOut = res.data.is_clocked_out === true;
          if (!isClockedOut) {
            if (alertBox) alertBox.style.display = 'flex';
            if (btnSubmit) {
              btnSubmit.disabled = true;
              btnSubmit.classList.remove('btn-success');
              btnSubmit.classList.add('btn-secondary');
              btnSubmit.innerHTML = '<i class="bi bi-lock-fill me-1"></i> Punch-Out Required';
            }
            if (btnDraft) btnDraft.disabled = true;
          } else {
            if (alertBox) alertBox.style.display = 'none';
            if (btnSubmit) {
              btnSubmit.disabled = false;
              btnSubmit.classList.add('btn-success');
              btnSubmit.classList.remove('btn-secondary');
              btnSubmit.innerHTML = '<i class="bi bi-send-check me-1"></i> Submit Report';
            }
            if (btnDraft) btnDraft.disabled = false;
          }
        }
      } else {
        if (disp) disp.value = '0h 00m (0.00h)';
        if (hid) hid.value = 0.00;
      }
    })
    .catch(() => {
      if (disp) disp.value = '0h 00m (0.00h)';
      if (hid) hid.value = 0.00;
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
