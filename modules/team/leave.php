<?php
// modules/team/leave.php — Admin Leave Management & Approvals
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/audit_helper.php';
require_login();

$csrf = csrf_token();

// Handle Approve / Reject POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve', 'reject'])) {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $reqId   = intval($_POST['request_id']);
        $action  = $_POST['action'];
        $comment = trim($_POST['review_comment'] ?? '');
        $status  = ($action === 'approve') ? 'approved' : 'rejected';

        // Fetch leave request
        $rqStmt = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ?");
        $rqStmt->execute([$reqId]);
        $req = $rqStmt->fetch();

        if ($req) {
            $upd = $pdo->prepare("UPDATE leave_requests SET status=?, reviewed_by=?, reviewed_at=NOW(), review_comment=? WHERE id=?");
            $upd->execute([$status, $_SESSION['user_id'], $comment, $reqId]);

            // If approved, synchronize attendance for the date range
            if ($status === 'approved') {
                $start = new DateTime($req['start_date']);
                $end   = new DateTime($req['end_date']);
                $interval = new DateInterval('P1D');
                $period   = new DatePeriod($start, $interval, (clone $end)->modify('+1 day'));

                foreach ($period as $dt) {
                    $dStr = $dt->format('Y-m-d');
                    // Check if Sunday
                    if ((int)$dt->format('w') === 0) continue;

                    $attSql = "INSERT INTO attendance 
                                 (employee_id, attendance_date, status, leave_type, notes, marked_by)
                               VALUES (?, ?, 'on_leave', 'Approved Leave', ?, ?)
                               ON DUPLICATE KEY UPDATE 
                                 status = 'on_leave', leave_type = 'Approved Leave', notes = VALUES(notes)";
                    $pdo->prepare($attSql)->execute([$req['employee_id'], $dStr, "Approved Leave: " . $req['reason'], $_SESSION['user_id']]);
                }
            }

            log_audit('leave', $action, $reqId, [
                'employee_id' => $req['employee_id'],
                'days' => $req['total_days'],
                'comment' => $comment
            ]);

            set_flash("Leave request has been " . ucfirst($status) . " successfully!", "success");
            header("Location: leave.php");
            exit;
        }
    }
}

// Fetch requests with employee and leave type details
$reqs = $pdo->query("
    SELECT lr.*, e.full_name as emp_name, e.employee_code, e.department, lt.name as leave_type_name, u.name as reviewer_name 
    FROM leave_requests lr
    JOIN employees e ON e.id = lr.employee_id
    JOIN leave_types lt ON lt.id = lr.leave_type_id
    LEFT JOIN users u ON u.id = lr.reviewed_by
    ORDER BY CASE WHEN lr.status = 'pending' THEN 1 ELSE 2 END, lr.created_at DESC
")->fetchAll();

$pendingCount = 0;
foreach ($reqs as $r) {
    if ($r['status'] === 'pending') $pendingCount++;
}
?>

<div class="container-fluid py-4">

  <!-- Top bar -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-calendar2-heart text-primary me-2"></i>Leave Applications & Approvals</h3>
      <p class="text-muted mb-0 small">Review employee time-off requests · Automatically syncs with daily attendance</p>
    </div>
    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2 fs-6 fw-semibold">
      <i class="bi bi-clock me-1"></i><?= $pendingCount ?> Pending Requests
    </span>
  </div>

  <?php $flash = get_flash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-check-circle-fill me-2"></i><?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Table Card -->
  <div class="card border-0 shadow-sm" style="border-radius:16px;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
      <h5 class="fw-bold text-dark mb-0"><i class="bi bi-list-check me-2 text-primary"></i>All Leave Requests</h5>
      <span class="badge bg-light text-muted border"><?= count($reqs) ?> Total</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
          <thead class="table-light">
            <tr>
              <th class="ps-4">Employee</th>
              <th>Leave Type</th>
              <th>Duration</th>
              <th>Days</th>
              <th>Reason</th>
              <th>Status</th>
              <th class="pe-4 text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($reqs)): ?>
              <tr><td colspan="7" class="text-center text-muted py-5">No leave applications recorded.</td></tr>
            <?php else: ?>
              <?php foreach ($reqs as $rq): 
                $st = $rq['status'];
                $bColor = match($st) {
                  'approved' => 'bg-success-subtle text-success border border-success-subtle',
                  'rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
                  default => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'
                };
              ?>
              <tr class="admin-leave-row" style="cursor: pointer; transition: background 0.15s;"
                  onclick="openAdminLeaveDetails(this)"
                  data-id="<?= $rq['id'] ?>"
                  data-emp-name="<?= htmlspecialchars($rq['emp_name'], ENT_QUOTES) ?>"
                  data-emp-code="<?= htmlspecialchars($rq['employee_code'], ENT_QUOTES) ?>"
                  data-emp-dept="<?= htmlspecialchars($rq['department'], ENT_QUOTES) ?>"
                  data-leave-type="<?= htmlspecialchars($rq['leave_type_name'], ENT_QUOTES) ?>"
                  data-start-date="<?= date('d M Y', strtotime($rq['start_date'])) ?>"
                  data-end-date="<?= date('d M Y', strtotime($rq['end_date'])) ?>"
                  data-total-days="<?= $rq['total_days'] ?>"
                  data-reason="<?= htmlspecialchars($rq['reason'] ?? '', ENT_QUOTES) ?>"
                  data-status="<?= $st ?>"
                  data-created-at="<?= !empty($rq['created_at']) ? date('d M Y, h:i A', strtotime($rq['created_at'])) : '—' ?>"
                  data-reviewer-name="<?= htmlspecialchars($rq['reviewer_name'] ?? '', ENT_QUOTES) ?>"
                  data-reviewed-at="<?= !empty($rq['reviewed_at']) ? date('d M Y, h:i A', strtotime($rq['reviewed_at'])) : '' ?>"
                  data-review-comment="<?= htmlspecialchars($rq['review_comment'] ?? '', ENT_QUOTES) ?>">
                <td class="ps-4">
                  <div class="fw-bold text-dark"><?= e($rq['emp_name']) ?></div>
                  <div class="text-muted small" style="font-size:0.7rem;"><?= e($rq['employee_code']) ?> · <?= e($rq['department']) ?></div>
                </td>
                <td class="fw-semibold text-primary">
                  <i class="bi bi-calendar2-check me-1"></i> <?= e($rq['leave_type_name']) ?>
                </td>
                <td class="text-nowrap">
                  <?= date('d M Y', strtotime($rq['start_date'])) ?> 
                  <i class="bi bi-arrow-right text-muted mx-1"></i> 
                  <?= date('d M Y', strtotime($rq['end_date'])) ?>
                </td>
                <td class="fw-bold"><?= $rq['total_days'] ?>d</td>
                <td style="max-width:230px;">
                  <div class="text-dark small text-truncate" title="<?= e($rq['reason']) ?>"><?= e($rq['reason']) ?></div>
                  <?php if (!empty($rq['review_comment'])): ?>
                    <div class="text-muted" style="font-size:0.7rem;">Note: <?= e($rq['review_comment']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?= $bColor ?> px-2 py-1 rounded-pill fw-semibold"><?= ucfirst($st) ?></span>
                </td>
                <td class="pe-4 text-end" onclick="event.stopPropagation();">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      Actions
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <button type="button" class="dropdown-item" onclick="openAdminLeaveDetails(this.closest('tr'))">
                          <i class="bi bi-eye text-primary me-2"></i> View Details
                        </button>
                      </li>
                      <?php if ($st === 'pending'): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                          <button type="button" class="dropdown-item text-success fw-semibold" onclick="openLeaveModal(<?= $rq['id'] ?>, 'approve', '<?= addslashes(e($rq['emp_name'])) ?>')">
                            <i class="bi bi-check-circle me-2"></i> Approve Leave
                          </button>
                        </li>
                        <li>
                          <button type="button" class="dropdown-item text-danger fw-semibold" onclick="openLeaveModal(<?= $rq['id'] ?>, 'reject', '<?= addslashes(e($rq['emp_name'])) ?>')">
                            <i class="bi bi-x-circle me-2"></i> Reject Leave
                          </button>
                        </li>
                      <?php endif; ?>
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

<!-- ADMIN LEAVE FULL DETAILS MODAL -->
<div class="modal fade" id="adminLeaveDetailsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0 pb-1">
        <div>
          <h5 class="modal-title fw-bold mb-0 text-dark">
            <i class="bi bi-file-earmark-person text-primary me-2"></i>Leave Request Details
          </h5>
          <small class="text-muted" id="adminModalReqSub">Complete request breakdown & history</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-3">
        <!-- Top Status & Employee Header -->
        <div class="p-3 rounded mb-3 d-flex align-items-center justify-content-between" id="adminModalStatusBanner">
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-white text-primary fw-bold d-flex align-items-center justify-content-center border" style="width:44px; height:44px; font-size:1.1rem;" id="adminModalAvatar">
              E
            </div>
            <div>
              <div class="fw-bold text-dark fs-6" id="adminModalEmpName">—</div>
              <div class="text-muted small" style="font-size:0.75rem;" id="adminModalEmpMeta">—</div>
            </div>
          </div>
          <span class="badge px-3 py-2 rounded-pill fw-semibold fs-6" id="adminModalStatusBadge">Pending</span>
        </div>

        <!-- Application Parameters Grid -->
        <div class="card border bg-light bg-opacity-50 p-3 mb-3" style="border-radius:12px;">
          <div class="row g-2">
            <div class="col-6">
              <span class="text-muted small d-block">Leave Type</span>
              <strong class="text-dark" id="adminModalLeaveType">Casual Leave</strong>
            </div>
            <div class="col-6">
              <span class="text-muted small d-block">Duration Count</span>
              <strong class="text-primary" id="adminModalDays">1 Day</strong>
            </div>
            <div class="col-12 mt-2 pt-2 border-top">
              <span class="text-muted small d-block">Date Range</span>
              <div class="fw-semibold text-dark">
                <i class="bi bi-calendar-range text-muted me-1"></i>
                <span id="adminModalDateRange">24 Sep 2026 to 24 Sep 2026</span>
              </div>
            </div>
            <div class="col-12 mt-1">
              <span class="text-muted small d-block">Submitted / Applied At</span>
              <div class="text-dark small" id="adminModalAppliedAt">—</div>
            </div>
          </div>
        </div>

        <!-- Employee Reason Card -->
        <div class="mb-3">
          <label class="form-label small fw-bold text-dark mb-1">
            <i class="bi bi-chat-quote-fill text-primary me-1"></i> Reason for Leave (Filled by Employee)
          </label>
          <div class="p-3 bg-white border rounded text-dark small" id="adminModalReason" style="white-space: pre-wrap; min-height: 50px; border-radius:10px;">
          </div>
        </div>

        <!-- Review / Action Card -->
        <div id="adminModalReviewCard" class="card border p-3" style="border-radius:12px;">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-shield-check text-primary fs-5" id="adminModalReviewIcon"></i>
            <h6 class="fw-bold mb-0 text-dark" id="adminModalReviewTitle">Review & Audit Information</h6>
          </div>
          <div class="small mb-1">
            <span class="text-muted">Reviewed By:</span>
            <strong class="text-dark ms-1" id="adminModalReviewer">—</strong>
            <span class="text-muted ms-2">on</span>
            <span class="text-dark ms-1" id="adminModalReviewedAt">—</span>
          </div>
          <div class="mt-2 p-2 px-3 rounded small" id="adminModalCommentBox" style="background:#f8fafc; border:1px solid #e2e8f0;">
            <div class="text-muted mb-1 fw-semibold" style="font-size:0.7rem;">Review / Rejection Note:</div>
            <div class="text-dark" id="adminModalReviewComment" style="white-space: pre-wrap;">No comments provided.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 d-flex justify-content-between">
        <button type="button" class="btn btn-secondary btn-sm px-4 fw-medium" data-bs-dismiss="modal">Close</button>
        <div id="adminModalActionBtns" class="d-none">
          <button type="button" class="btn btn-sm btn-outline-danger fw-semibold me-2" id="adminModalRejectBtn">
            <i class="bi bi-x-lg me-1"></i> Reject
          </button>
          <button type="button" class="btn btn-sm btn-success fw-semibold" id="adminModalApproveBtn">
            <i class="bi bi-check-lg me-1"></i> Approve
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- APPROVE / REJECT MODAL -->
<div class="modal fade" id="leaveActionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold" id="leaveModalTitle">Process Leave Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="leaveActionForm">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" id="modalLeaveAction" value="approve">
          <input type="hidden" name="request_id" id="modalLeaveReqId">

          <p class="small text-muted" id="modalLeaveMsg">Confirm decision for employee leave request.</p>

          <div class="mb-3">
            <label class="form-label small fw-semibold">Manager Review Comment</label>
            <textarea name="review_comment" class="form-control" rows="3" placeholder="Optional comments, guidelines or reasons..."></textarea>
          </div>

          <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" id="modalLeaveBtn">Confirm Decision</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function openAdminLeaveDetails(tr) {
  if (!tr) return;
  const d = tr.dataset;

  document.getElementById('adminModalReqSub').textContent = 'Request #' + (d.id || '') + ' · ' + (d.leaveType || '');
  document.getElementById('adminModalEmpName').textContent = d.empName || 'Employee';
  document.getElementById('adminModalEmpMeta').textContent = (d.empCode || '') + ' · ' + (d.empDept || '');
  document.getElementById('adminModalAvatar').textContent = (d.empName || 'E').trim().charAt(0).toUpperCase();

  document.getElementById('adminModalLeaveType').textContent = d.leaveType || '—';
  document.getElementById('adminModalDays').textContent = (d.totalDays || 1) + (d.totalDays == 1 ? ' Day' : ' Days');
  document.getElementById('adminModalDateRange').textContent = (d.startDate || '') + ' to ' + (d.endDate || '');
  document.getElementById('adminModalAppliedAt').textContent = d.createdAt || '—';
  document.getElementById('adminModalReason').textContent = d.reason || 'No explanation provided.';

  const st = (d.status || 'pending').toLowerCase();
  const banner = document.getElementById('adminModalStatusBanner');
  const badge = document.getElementById('adminModalStatusBadge');
  const reviewCard = document.getElementById('adminModalReviewCard');
  const reviewTitle = document.getElementById('adminModalReviewTitle');
  const reviewer = document.getElementById('adminModalReviewer');
  const reviewedAt = document.getElementById('adminModalReviewedAt');
  const commentBox = document.getElementById('adminModalCommentBox');
  const reviewComment = document.getElementById('adminModalReviewComment');
  const actionBtns = document.getElementById('adminModalActionBtns');

  if (st === 'approved') {
    banner.style.background = '#f0fdf4';
    banner.style.border = '1px solid #bbf7d0';
    badge.className = 'badge bg-success text-white px-3 py-2 rounded-pill fw-semibold';
    badge.textContent = 'Approved';

    reviewCard.className = 'card border p-3 border-success-subtle bg-success-subtle bg-opacity-25';
    reviewTitle.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> Approved by Management';
    reviewTitle.className = 'fw-bold mb-0 text-success';
    reviewer.textContent = d.reviewerName || 'Admin';
    reviewedAt.textContent = d.reviewedAt || 'Recorded';
    reviewComment.textContent = d.reviewComment || 'Request was approved. Daily attendance synchronized.';
    commentBox.style.background = '#ffffff';
    reviewCard.classList.remove('d-none');
    actionBtns.classList.add('d-none');
  } else if (st === 'rejected') {
    banner.style.background = '#fef2f2';
    banner.style.border = '1px solid #fecaca';
    badge.className = 'badge bg-danger text-white px-3 py-2 rounded-pill fw-semibold';
    badge.textContent = 'Rejected';

    reviewCard.className = 'card border p-3 border-danger-subtle bg-danger-subtle bg-opacity-25';
    reviewTitle.innerHTML = '<i class="bi bi-x-circle-fill text-danger me-1"></i> Rejected by Management';
    reviewTitle.className = 'fw-bold mb-0 text-danger';
    reviewer.textContent = d.reviewerName || 'Admin';
    reviewedAt.textContent = d.reviewedAt || 'Recorded';
    reviewComment.textContent = d.reviewComment || 'Request was rejected without additional remarks.';
    commentBox.style.background = '#ffffff';
    reviewCard.classList.remove('d-none');
    actionBtns.classList.add('d-none');
  } else {
    banner.style.background = '#fffbeb';
    banner.style.border = '1px solid #fde68a';
    badge.className = 'badge bg-warning text-dark px-3 py-2 rounded-pill fw-semibold';
    badge.textContent = 'Pending';

    reviewCard.className = 'card border p-3 border-warning-subtle bg-warning-subtle bg-opacity-25';
    reviewTitle.innerHTML = '<i class="bi bi-hourglass-split text-warning me-1"></i> Awaiting Your Review';
    reviewTitle.className = 'fw-bold mb-0 text-dark';
    reviewer.textContent = 'Pending Decision';
    reviewedAt.textContent = '—';
    reviewComment.textContent = 'This leave request has not been reviewed yet. You can approve or reject it below.';
    commentBox.style.background = '#ffffff';
    reviewCard.classList.remove('d-none');
    actionBtns.classList.remove('d-none');

    // Attach approve / reject handlers to modal footer buttons
    document.getElementById('adminModalApproveBtn').onclick = function() {
      bootstrap.Modal.getInstance(document.getElementById('adminLeaveDetailsModal')).hide();
      openLeaveModal(d.id, 'approve', d.empName);
    };
    document.getElementById('adminModalRejectBtn').onclick = function() {
      bootstrap.Modal.getInstance(document.getElementById('adminLeaveDetailsModal')).hide();
      openLeaveModal(d.id, 'reject', d.empName);
    };
  }

  new bootstrap.Modal(document.getElementById('adminLeaveDetailsModal')).show();
}

function openLeaveModal(reqId, action, empName) {
  document.getElementById('modalLeaveReqId').value = reqId;
  document.getElementById('modalLeaveAction').value = action;
  
  if (action === 'approve') {
    document.getElementById('leaveModalTitle').innerHTML = '<i class="bi bi-check-circle text-success me-2"></i>Approve Leave';
    document.getElementById('modalLeaveMsg').textContent = 'Approve time-off for ' + empName + '? Attendance will automatically update to On Leave.';
    document.getElementById('modalLeaveBtn').className = 'btn btn-success w-100 py-2 fw-semibold';
    document.getElementById('modalLeaveBtn').textContent = 'Approve Leave Request';
  } else {
    document.getElementById('leaveModalTitle').innerHTML = '<i class="bi bi-x-circle text-danger me-2"></i>Reject Leave';
    document.getElementById('modalLeaveMsg').textContent = 'Reject time-off request for ' + empName + '?';
    document.getElementById('modalLeaveBtn').className = 'btn btn-danger w-100 py-2 fw-semibold';
    document.getElementById('modalLeaveBtn').textContent = 'Reject Leave Request';
  }

  new bootstrap.Modal('#leaveActionModal').show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
