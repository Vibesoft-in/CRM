<?php
// employee/leave.php — Employee Leave Management & Applications
require_once __DIR__ . '/layout.php';

$empId = (int)($curEmp['id'] ?? 0);
if ($empId <= 0) {
    abort_forbidden("No verified employee profile linked to your account.");
}
$csrf = csrf_token();
$err = '';

// Handle Leave Application POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token.";
    } else {
        $typeId    = intval($_POST['leave_type_id'] ?? 1);
        $startDate = $_POST['start_date'] ?? '';
        $endDate   = $_POST['end_date'] ?? '';
        $reason    = trim($_POST['reason'] ?? '');

        if (empty($startDate) || empty($endDate) || empty($reason)) {
            $err = "Please select valid start date, end date, and provide reason.";
        } elseif (strtotime($endDate) < strtotime($startDate)) {
            $err = "End date cannot be before start date.";
        } else {
            $days = round((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
            
            $ins = $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, total_days, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $ins->execute([$empId, $typeId, $startDate, $endDate, $days, $reason]);

            $leaveUserId = $_SESSION['user_id'] ?? get_user_id_by_employee_id($pdo, $empId);
            if ($leaveUserId) {
                create_action_notification($pdo, (int)$leaveUserId, 'Leave Application Submitted', "Your {$days}-day leave request from {$startDate} to {$endDate} was submitted successfully.", '/employee/leave.php', 'leave_applied');
            }

            set_flash("Leave request submitted successfully. Awaiting manager approval.", "success");
            header('Location: ' . BASE_URL . 'employee/leave.php');
            exit;
        }
    }
}

// Fetch leave types
$leaveTypes = $pdo->query("SELECT * FROM leave_types WHERE status='active'")->fetchAll();

// Fetch leave history
$requestsStmt = $pdo->prepare("
    SELECT lr.*, lt.name as leave_type_name, u.name as reviewer_name 
    FROM leave_requests lr
    JOIN leave_types lt ON lt.id = lr.leave_type_id
    LEFT JOIN users u ON u.id = lr.reviewed_by
    WHERE lr.employee_id = ?
    ORDER BY lr.created_at DESC
");
$requestsStmt->execute([$empId]);
$requests = $requestsStmt->fetchAll();

// Calculate accurate leave balances using company policy (4 allowed per year)
$leaveSummary = get_employee_annual_leave_summary($pdo, $empId, (int)date('Y'));
$totalAllowed = $leaveSummary['allowed_leaves'];
$usedDays     = $leaveSummary['total_taken'];
$availableDays= $leaveSummary['remaining_allowed'];
$extraDays    = $leaveSummary['extra_leaves'];
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

  <!-- Top bar -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-calendar2-heart text-info me-2"></i>Leave Management</h3>
      <p class="text-muted mb-0 small">Apply for leave, check approval status, and track annual leave quota</p>
    </div>
    <button class="btn btn-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#applyLeaveModal">
      <i class="bi bi-plus-circle me-1"></i> Apply for Leave
    </button>
  </div>

  <!-- Leave Quota Summary Cards -->
  <!-- Leave Quota Summary Cards -->
  <div class="row g-2 g-md-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center h-100" style="border-radius:12px;">
        <div class="text-muted small mb-1">Annual Allowed Quota</div>
        <div class="fw-bold fs-4 fs-sm-3 text-dark"><?= $totalAllowed ?> Days</div>
        <small class="text-muted" style="font-size:0.7rem;">Company Policy (Paid)</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center h-100" style="border-radius:12px;">
        <div class="text-muted small mb-1">Approved & Used</div>
        <div class="fw-bold fs-4 fs-sm-3 text-warning-emphasis"><?= $usedDays ?> Days</div>
        <small class="text-muted" style="font-size:0.7rem;">Taken in <?= date('Y') ?></small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center h-100" style="border-radius:12px;">
        <div class="text-muted small mb-1">Remaining Allowed</div>
        <div class="fw-bold fs-4 fs-sm-3 text-success"><?= $availableDays ?> Days</div>
        <small class="text-muted" style="font-size:0.7rem;">Available paid quota</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center h-100" style="border-radius:12px;">
        <div class="text-muted small mb-1">Extra Leaves (Unpaid)</div>
        <div class="fw-bold fs-4 fs-sm-3 <?= $extraDays > 0 ? 'text-danger' : 'text-muted' ?>"><?= $extraDays ?> Days</div>
        <small class="text-muted" style="font-size:0.7rem;">Beyond 4 days quota</small>
      </div>
    </div>
  </div>

  <!-- Leave Applications Table -->
  <div class="card border-0 shadow-sm" style="border-radius:16px;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
      <h5 class="fw-bold text-dark mb-0"><i class="bi bi-list-task me-2 text-primary"></i>My Leave Applications</h5>
      <span class="text-muted small d-md-none" style="font-size:0.72rem;"><i class="bi bi-arrow-left-right me-1"></i>Scrollable</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem; min-width: 680px;">
          <thead class="table-light">
            <tr>
              <th class="ps-4 text-nowrap">Leave Type</th>
              <th class="text-nowrap">Period</th>
              <th class="text-nowrap">Total Days</th>
              <th class="text-nowrap">Reason</th>
              <th class="text-nowrap">Status</th>
              <th class="text-nowrap">Manager Note</th>
              <th class="pe-4 text-end text-nowrap">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($requests)): ?>
              <tr><td colspan="7" class="text-center text-muted py-5">No leave applications submitted yet.</td></tr>
            <?php else: ?>
              <?php foreach ($requests as $rq): 
                $st = $rq['status'];
                $bColor = match($st) {
                  'approved' => 'bg-success-subtle text-success border border-success-subtle',
                  'rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
                  'cancelled' => 'bg-secondary-subtle text-secondary',
                  default => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'
                };
              ?>
              <tr class="emp-leave-row" style="cursor: pointer; transition: background 0.15s;" 
                  onclick="openEmpLeaveDetails(this)"
                  data-id="<?= $rq['id'] ?>"
                  data-leave-type="<?= htmlspecialchars($rq['leave_type_name'], ENT_QUOTES) ?>"
                  data-start-date="<?= date('d M Y', strtotime($rq['start_date'])) ?>"
                  data-end-date="<?= date('d M Y', strtotime($rq['end_date'])) ?>"
                  data-total-days="<?= $rq['total_days'] ?>"
                  data-reason="<?= htmlspecialchars($rq['reason'] ?? '', ENT_QUOTES) ?>"
                  data-status="<?= $st ?>"
                  data-created-at="<?= !empty($rq['created_at']) ? date('d M Y, h:i A', strtotime($rq['created_at'])) : '—' ?>"
                  data-reviewed-by="<?= htmlspecialchars($rq['reviewer_name'] ?? '', ENT_QUOTES) ?>"
                  data-reviewed-at="<?= !empty($rq['reviewed_at']) ? date('d M Y, h:i A', strtotime($rq['reviewed_at'])) : '' ?>"
                  data-review-comment="<?= htmlspecialchars($rq['review_comment'] ?? '', ENT_QUOTES) ?>">
                <td class="ps-4 fw-semibold text-dark text-nowrap">
                  <i class="bi bi-calendar2-check text-primary me-1"></i> <?= e($rq['leave_type_name']) ?>
                </td>
                <td class="text-nowrap">
                  <?= date('d M Y', strtotime($rq['start_date'])) ?> 
                  <i class="bi bi-arrow-right text-muted mx-1"></i>
                  <?= date('d M Y', strtotime($rq['end_date'])) ?>
                </td>
                <td class="fw-bold text-nowrap"><?= $rq['total_days'] ?> <?= $rq['total_days'] == 1 ? 'Day' : 'Days' ?></td>
                <td class="text-muted" style="max-width:220px;">
                  <div class="text-truncate" title="<?= e($rq['reason']) ?>"><?= e($rq['reason']) ?></div>
                </td>
                <td class="text-nowrap"><span class="badge <?= $bColor ?> px-2 py-1 rounded-pill fw-semibold"><?= ucfirst($st) ?></span></td>
                <td class="text-muted small text-truncate" style="max-width:180px;">
                  <?= !empty($rq['review_comment']) ? e($rq['review_comment']) : '<span class="text-muted opacity-50">—</span>' ?>
                </td>
                <td class="pe-4 text-end text-nowrap" onclick="event.stopPropagation();">
                  <button class="btn btn-sm btn-outline-primary py-1 px-2 fw-medium" onclick="openEmpLeaveDetails(this.closest('tr'))">
                    <i class="bi bi-eye me-1"></i> Details
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

<!-- APPLY LEAVE MODAL -->
<div class="modal fade" id="applyLeaveModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-calendar2-plus text-primary me-2"></i>Apply for Leave</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="leaveForm">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <div class="mb-3">
            <label class="form-label small fw-semibold">Leave Type <span class="text-danger">*</span></label>
            <select name="leave_type_id" class="form-select" required>
              <?php foreach ($leaveTypes as $lt): ?>
                <option value="<?= $lt['id'] ?>"><?= e($lt['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small fw-semibold">From Date <span class="text-danger">*</span></label>
              <input type="date" name="start_date" id="leaveStartDate" class="form-control" value="<?= date('Y-m-d') ?>" required onchange="calculateApplyDays()" oninput="calculateApplyDays()">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">To Date <span class="text-danger">*</span></label>
              <input type="date" name="end_date" id="leaveEndDate" class="form-control" value="<?= date('Y-m-d') ?>" required onchange="calculateApplyDays()" oninput="calculateApplyDays()">
            </div>
          </div>

          <!-- DYNAMIC DAYS COUNTER -->
          <div id="applyDaysBadgeWrapper" class="p-2 px-3 rounded mb-3 d-flex align-items-center justify-content-between" style="background:#eff6ff; border:1px solid #bfdbfe;">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-clock-history text-primary fs-5"></i>
              <div>
                <div class="text-muted" style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Calculated Duration</div>
                <div class="fw-bold text-dark" id="applyDaysText">1 Day</div>
              </div>
            </div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill fw-semibold" id="applyDaysPill">1 Day</span>
          </div>

          <div id="applyDaysError" class="alert alert-danger py-2 px-3 small mb-3 d-none" style="border-radius:10px;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> To Date cannot be earlier than From Date.
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold">Reason for Leave <span class="text-danger">*</span></label>
            <textarea name="reason" class="form-control" rows="3" placeholder="State reason (e.g. medical, personal urgency, family function)..." required></textarea>
          </div>

          <button type="submit" id="applyLeaveSubmitBtn" class="btn btn-primary w-100 py-2 fw-semibold">
            <i class="bi bi-send me-1"></i> Submit Application
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- LEAVE DETAILS VIEW MODAL (EMPLOYEE SIDE) -->
<div class="modal fade" id="empLeaveDetailsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0 pb-1">
        <div>
          <h5 class="modal-title fw-bold mb-0 text-dark">
            <i class="bi bi-calendar2-range text-primary me-2"></i>Leave Request Details
          </h5>
          <small class="text-muted" id="empModalRefText">Application details & status</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-3">
        <!-- Top Status Banner -->
        <div id="empModalStatusBanner" class="p-3 rounded mb-3 d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <span id="empModalStatusIcon" class="fs-4"></span>
            <div>
              <div class="text-muted small" style="font-size:0.7rem; text-transform:uppercase; font-weight:700;">Status</div>
              <div class="fw-bold fs-6" id="empModalStatusText">Pending</div>
            </div>
          </div>
          <span class="badge px-3 py-2 rounded-pill fw-semibold" id="empModalStatusBadge">Pending</span>
        </div>

        <!-- Duration & Leave Type Details -->
        <div class="card border bg-light bg-opacity-50 p-3 mb-3" style="border-radius:12px;">
          <div class="row g-2">
            <div class="col-6">
              <span class="text-muted small d-block">Leave Type</span>
              <strong class="text-dark" id="empModalLeaveType">Casual Leave</strong>
            </div>
            <div class="col-6">
              <span class="text-muted small d-block">Total Days</span>
              <strong class="text-primary" id="empModalTotalDays">1 Day</strong>
            </div>
            <div class="col-12 mt-2 pt-2 border-top">
              <span class="text-muted small d-block">Duration Range</span>
              <div class="fw-semibold text-dark">
                <i class="bi bi-calendar-check text-muted me-1"></i>
                <span id="empModalPeriod">24 Sep 2026 to 24 Sep 2026</span>
              </div>
            </div>
            <div class="col-12 mt-1">
              <span class="text-muted small d-block">Applied On</span>
              <div class="text-dark small" id="empModalAppliedOn">—</div>
            </div>
          </div>
        </div>

        <!-- Full Reason Filled by User -->
        <div class="mb-3">
          <label class="form-label small fw-bold text-dark mb-1">
            <i class="bi bi-chat-left-text text-secondary me-1"></i> Reason for Leave (Submitted by You)
          </label>
          <div class="p-3 bg-white border rounded text-dark small" id="empModalReason" style="white-space: pre-wrap; min-height: 50px; border-radius:10px;">
          </div>
        </div>

        <!-- Manager Review Decision Card (Crucial for Rejected / Approved) -->
        <div id="empModalReviewCard" class="card border p-3" style="border-radius:12px;">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-shield-check text-primary fs-5" id="empModalReviewIcon"></i>
            <h6 class="fw-bold mb-0 text-dark" id="empModalReviewTitle">Management Decision Details</h6>
          </div>
          <div class="small mb-1">
            <span class="text-muted">Reviewed By:</span>
            <strong class="text-dark ms-1" id="empModalReviewerName">—</strong>
            <span class="text-muted ms-2">on</span>
            <span class="text-dark ms-1" id="empModalReviewedAt">—</span>
          </div>
          <div class="mt-2 p-2 px-3 rounded small" id="empModalCommentBox" style="background:#f8fafc; border:1px solid #e2e8f0;">
            <div class="text-muted mb-1 fw-semibold" style="font-size:0.7rem;">Review / Rejection Note:</div>
            <div class="text-dark" id="empModalReviewComment" style="white-space: pre-wrap;">No comments provided.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-secondary btn-sm px-4 fw-medium" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
// Dynamic Days Calculation in Apply Modal
function calculateApplyDays() {
  const startInput = document.getElementById('leaveStartDate');
  const endInput = document.getElementById('leaveEndDate');
  const textEl = document.getElementById('applyDaysText');
  const pillEl = document.getElementById('applyDaysPill');
  const errorEl = document.getElementById('applyDaysError');
  const submitBtn = document.getElementById('applyLeaveSubmitBtn');
  const wrapper = document.getElementById('applyDaysBadgeWrapper');

  if (!startInput || !endInput) return;

  const startVal = startInput.value;
  const endVal = endInput.value;

  if (!startVal || !endVal) {
    if (textEl) textEl.textContent = 'Select both dates';
    if (pillEl) pillEl.textContent = '—';
    return;
  }

  const sDate = new Date(startVal + 'T00:00:00');
  const eDate = new Date(endVal + 'T00:00:00');

  if (eDate < sDate) {
    if (errorEl) errorEl.classList.remove('d-none');
    if (wrapper) wrapper.classList.add('d-none');
    if (submitBtn) submitBtn.disabled = true;
  } else {
    if (errorEl) errorEl.classList.add('d-none');
    if (wrapper) wrapper.classList.remove('d-none');
    if (submitBtn) submitBtn.disabled = false;

    const diffDays = Math.round((eDate - sDate) / (1000 * 60 * 60 * 24)) + 1;
    const label = diffDays + (diffDays === 1 ? ' Day' : ' Days');
    if (textEl) textEl.textContent = label;
    if (pillEl) pillEl.textContent = label;
  }
}

// Open Leave Details Modal for Employee
function openEmpLeaveDetails(tr) {
  if (!tr) return;

  const d = tr.dataset;
  document.getElementById('empModalRefText').textContent = 'Request #' + (d.id || '') + ' · ' + (d.leaveType || '');
  document.getElementById('empModalLeaveType').textContent = d.leaveType || '—';
  document.getElementById('empModalTotalDays').textContent = (d.totalDays || 1) + ((d.totalDays == 1) ? ' Day' : ' Days');
  document.getElementById('empModalPeriod').textContent = (d.startDate || '') + ' to ' + (d.endDate || '');
  document.getElementById('empModalAppliedOn').textContent = d.createdAt || '—';
  document.getElementById('empModalReason').textContent = d.reason || 'No specific reason provided.';

  const status = (d.status || 'pending').toLowerCase();
  const banner = document.getElementById('empModalStatusBanner');
  const icon = document.getElementById('empModalStatusIcon');
  const statusText = document.getElementById('empModalStatusText');
  const statusBadge = document.getElementById('empModalStatusBadge');
  const reviewCard = document.getElementById('empModalReviewCard');
  const reviewTitle = document.getElementById('empModalReviewTitle');
  const reviewerName = document.getElementById('empModalReviewerName');
  const reviewedAt = document.getElementById('empModalReviewedAt');
  const commentBox = document.getElementById('empModalCommentBox');
  const reviewComment = document.getElementById('empModalReviewComment');

  if (status === 'approved') {
    banner.style.background = '#f0fdf4';
    banner.style.border = '1px solid #bbf7d0';
    icon.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
    statusText.textContent = 'Approved';
    statusText.className = 'fw-bold fs-6 text-success';
    statusBadge.className = 'badge bg-success text-white px-3 py-2 rounded-pill fw-semibold';
    statusBadge.textContent = 'Approved';

    reviewCard.className = 'card border p-3 border-success-subtle bg-success-subtle bg-opacity-25';
    reviewTitle.innerHTML = '<i class="bi bi-check-circle text-success me-1"></i> Approved by Management';
    reviewTitle.className = 'fw-bold mb-0 text-success';
    reviewerName.textContent = d.reviewedBy || 'Admin / Manager';
    reviewedAt.textContent = d.reviewedAt || 'Recently';
    reviewComment.textContent = d.reviewComment || 'Request was approved. Syncing with daily attendance.';
    commentBox.style.background = '#ffffff';
    reviewCard.classList.remove('d-none');
  } else if (status === 'rejected') {
    banner.style.background = '#fef2f2';
    banner.style.border = '1px solid #fecaca';
    icon.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>';
    statusText.textContent = 'Rejected';
    statusText.className = 'fw-bold fs-6 text-danger';
    statusBadge.className = 'badge bg-danger text-white px-3 py-2 rounded-pill fw-semibold';
    statusBadge.textContent = 'Rejected';

    reviewCard.className = 'card border p-3 border-danger-subtle bg-danger-subtle bg-opacity-25';
    reviewTitle.innerHTML = '<i class="bi bi-x-circle text-danger me-1"></i> Rejected by Management';
    reviewTitle.className = 'fw-bold mb-0 text-danger';
    reviewerName.textContent = d.reviewedBy || 'Admin / Manager';
    reviewedAt.textContent = d.reviewedAt || 'Recently';
    reviewComment.textContent = d.reviewComment || 'No explanation provided for rejection.';
    commentBox.style.background = '#ffffff';
    reviewCard.classList.remove('d-none');
  } else {
    banner.style.background = '#fffbeb';
    banner.style.border = '1px solid #fde68a';
    icon.innerHTML = '<i class="bi bi-clock-history text-warning"></i>';
    statusText.textContent = 'Pending Review';
    statusText.className = 'fw-bold fs-6 text-warning-emphasis';
    statusBadge.className = 'badge bg-warning text-dark px-3 py-2 rounded-pill fw-semibold';
    statusBadge.textContent = 'Pending';

    reviewCard.className = 'card border p-3 border-warning-subtle bg-warning-subtle bg-opacity-25';
    reviewTitle.innerHTML = '<i class="bi bi-hourglass-split text-warning me-1"></i> Awaiting Manager Decision';
    reviewTitle.className = 'fw-bold mb-0 text-dark';
    reviewerName.textContent = 'Pending Review';
    reviewedAt.textContent = '—';
    reviewComment.textContent = 'Your application is currently under review by your department manager/admin.';
    commentBox.style.background = '#ffffff';
    reviewCard.classList.remove('d-none');
  }

  new bootstrap.Modal(document.getElementById('empLeaveDetailsModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
  calculateApplyDays();
  const applyModal = document.getElementById('applyLeaveModal');
  if (applyModal) {
    applyModal.addEventListener('shown.bs.modal', calculateApplyDays);
  }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
