<?php
// modules/team/daily_report.php — Evening Work Report
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$csrf = csrf_token();

// Filters
$filterEmp   = intval($_GET['emp'] ?? 0);
$filterMonth = intval($_GET['month'] ?? date('m'));
$filterYear  = intval($_GET['year'] ?? date('Y'));
$viewDate    = $_GET['date'] ?? date('Y-m-d');

// Employees list
$employees = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE status='active' ORDER BY full_name ASC")->fetchAll();

// Fetch reports
$params = [];
$where  = ["r.report_date BETWEEN DATE_FORMAT(STR_TO_DATE(CONCAT(?,'-',?,'-01'), '%Y-%m-%d'), '%Y-%m-%d') AND LAST_DAY(STR_TO_DATE(CONCAT(?,'-',?,'-01'), '%Y-%m-%d'))"];
$params = [$filterYear, $filterMonth, $filterYear, $filterMonth];

if ($filterEmp > 0) {
    $where[] = "r.employee_id = ?";
    $params[] = $filterEmp;
}

$reportsStmt = $pdo->prepare("
    SELECT r.*, e.full_name, e.employee_code, e.designation
    FROM daily_work_reports r
    JOIN employees e ON e.id = r.employee_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.report_date DESC, e.full_name ASC
");
$reportsStmt->execute($params);
$reports = $reportsStmt->fetchAll();

// Load single report for edit (via GET)
$editReport = null;
$editAttendance = null;
if (isset($_GET['edit'])) {
    $editEmpId = intval($_GET['emp_id'] ?? 0);
    $editDate  = $_GET['edit'];
    if ($editEmpId) {
        $s = $pdo->prepare("SELECT * FROM daily_work_reports WHERE employee_id=? AND report_date=?");
        $s->execute([$editEmpId, $editDate]);
        $editReport = $s->fetch();

        $a = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?");
        $a->execute([$editEmpId, $editDate]);
        $editAttendance = $a->fetch();
    }
}

$moodIcons = ['great'=>'😄','good'=>'🙂','okay'=>'😐','tired'=>'😴','stressed'=>'😰'];
?>
<style>
.report-card { border-radius: 14px; border: 1px solid #e9ecef; background: #fff; transition: all .2s; }
.report-card:hover { border-color: #0d6efd33; box-shadow: 0 4px 20px rgba(13,110,253,.08); }
.task-block { background: #f8fafc; border-radius: 8px; padding: .75rem 1rem; border-left: 3px solid #0d6efd; }
.rating-star { color: #fbbf24; }
.mood-badge { font-size: .8rem; background: #f0f9ff; border-radius: 50rem; padding: .25em .7em; }
.section-divider { border: none; border-top: 1px dashed #e2e8f0; margin: .75rem 0; }
.filter-card { border-radius: 12px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 1rem 1.25rem; }
.emp-avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; }
</style>

<div class="container-fluid py-4">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-journal-text text-success me-2"></i>Daily Work Reports</h3>
      <p class="text-muted mb-0 small">Evening work reports — tasks done, projects handled, blockers</p>
    </div>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addReportModal">
      <i class="bi bi-plus-circle me-1"></i>Add Report
    </button>
  </div>

  <!-- Filters -->
  <div class="filter-card mb-4">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Employee</label>
        <select name="emp" class="form-select form-select-sm">
          <option value="">All Employees</option>
          <?php foreach ($employees as $emp): ?>
          <option value="<?= $emp['id'] ?>" <?= $filterEmp == $emp['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($emp['full_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3 col-md-2">
        <label class="form-label small fw-semibold mb-1">Month</label>
        <select name="month" class="form-select form-select-sm">
          <?php for ($m=1; $m<=12; $m++): ?>
          <option value="<?= $m ?>" <?= $m == $filterMonth ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-sm-3 col-md-2">
        <label class="form-label small fw-semibold mb-1">Year</label>
        <select name="year" class="form-select form-select-sm">
          <?php for ($y=date('Y'); $y>=date('Y')-3; $y--): ?>
          <option value="<?= $y ?>" <?= $y == $filterYear ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a href="daily_report.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
      </div>
    </form>
  </div>

  <!-- Reports Grid -->
  <?php if (empty($reports)): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-journal-x fs-1 d-block mb-2 text-muted"></i>
    <h6>No reports found for this period</h6>
    <p class="small">Add a daily report using the button above.</p>
  </div>
  <?php else: ?>
  <div class="row g-3">
    <?php foreach ($reports as $r): 
      $stars = intval($r['rating'] ?? 0);
      $isApproved = (!empty($r['rating']) || !empty($r['reviewed_by']) || !empty($r['reviewed_at']));
      $isEdited = !empty($r['updated_at']) && !empty($r['submitted_at']) && (strtotime($r['updated_at']) > strtotime($r['submitted_at']) + 60);
    ?>
    <div class="col-12 col-lg-6">
      <div class="report-card p-3">
        <!-- Report Header -->
        <div class="d-flex align-items-center gap-3 mb-3">
          <img src="https://ui-avatars.com/api/?name=<?= urlencode($r['full_name']) ?>&background=0d6efd&color=fff&size=80" class="emp-avatar" alt="">
          <div class="flex-grow-1">
            <div class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
            <div class="text-muted small">
              <?= htmlspecialchars($r['employee_code']) ?> · <?= date('D, d M Y', strtotime($r['report_date'])) ?>
              <?php if (!empty($r['submitted_at'])): ?>
                · <span title="Initial submission time"><i class="bi bi-send text-primary me-1"></i><?= date('h:i A', strtotime($r['submitted_at'])) ?></span>
              <?php endif; ?>
              <?php if ($isEdited): ?>
                · <span class="text-warning-emphasis fw-medium" title="Employee edited this report at <?= date('d M Y, h:i A', strtotime($r['updated_at'])) ?>">
                  <i class="bi bi-pencil-square text-warning me-1"></i>Edited: <?= date('d M, h:i A', strtotime($r['updated_at'])) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex flex-column align-items-end gap-1">
            <div class="d-flex align-items-center gap-1">
              <?php if ($isApproved): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill" style="font-size:.72rem;">
                  <i class="bi bi-lock-fill me-1"></i>Approved
                </span>
              <?php else: ?>
                <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1 rounded-pill" style="font-size:.72rem;">
                  Submitted
                </span>
              <?php endif; ?>
              <span class="mood-badge"><?= $moodIcons[$r['mood']] ?? '🙂' ?> <?= ucfirst($r['mood'] ?? 'good') ?></span>
            </div>
            <?php if ($r['hours_worked'] > 0): ?>
              <span class="badge bg-light text-dark border" style="font-size:.7rem;"><i class="bi bi-clock me-1"></i><?= $r['hours_worked'] ?> hrs</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Tasks -->
        <?php if ($r['morning_tasks']): ?>
        <div class="task-block mb-2" style="border-left-color:#10b981;">
          <div class="small fw-semibold text-success mb-1"><i class="bi bi-sun me-1"></i>Morning Tasks</div>
          <div class="small text-dark"><?= nl2br(htmlspecialchars($r['morning_tasks'])) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($r['evening_tasks']): ?>
        <div class="task-block mb-2" style="border-left-color:#f59e0b;">
          <div class="small fw-semibold text-warning mb-1" style="color:#b45309!important;"><i class="bi bi-moon me-1"></i>Evening Tasks / Work Done</div>
          <div class="small text-dark"><?= nl2br(htmlspecialchars($r['evening_tasks'])) ?></div>
        </div>
        <?php endif; ?>

        <!-- Projects & Clients -->
        <?php if ($r['projects_worked'] || $r['clients_worked']): ?>
        <div class="d-flex gap-2 flex-wrap mb-2">
          <?php if ($r['projects_worked']): ?>
          <div class="small"><i class="bi bi-kanban text-primary me-1"></i><?= htmlspecialchars($r['projects_worked']) ?></div>
          <?php endif; ?>
          <?php if ($r['clients_worked']): ?>
          <div class="small"><i class="bi bi-people text-info me-1"></i><?= htmlspecialchars($r['clients_worked']) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Blockers -->
        <?php if ($r['blockers']): ?>
        <div class="task-block mb-2" style="border-left-color:#ef4444;">
          <div class="small fw-semibold text-danger mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Blockers</div>
          <div class="small text-dark"><?= nl2br(htmlspecialchars($r['blockers'])) ?></div>
        </div>
        <?php endif; ?>

        <!-- Plan Tomorrow -->
        <?php if ($r['plan_for_tomorrow']): ?>
        <div class="task-block" style="border-left-color:#8b5cf6;">
          <div class="small fw-semibold mb-1" style="color:#5b21b6;"><i class="bi bi-arrow-right-circle me-1"></i>Plan for Tomorrow</div>
          <div class="small text-dark"><?= nl2br(htmlspecialchars($r['plan_for_tomorrow'])) ?></div>
        </div>
        <?php endif; ?>

        <hr class="section-divider">

        <!-- Rating & Review -->
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <?php if ($stars > 0): ?>
              <span class="rating-star"><?= str_repeat('★', $stars) ?><?= str_repeat('☆', 5-$stars) ?></span>
              <span class="text-muted small ms-1"><?= $stars ?>/5</span>
            <?php else: ?>
              <span class="text-muted small">Not reviewed yet</span>
            <?php endif; ?>
            <?php if ($r['review_note']): ?>
              <div class="small text-muted mt-1"><i class="bi bi-chat-text me-1"></i><?= htmlspecialchars($r['review_note']) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-primary" style="font-size:.72rem;"
              onclick="openReview(<?= $r['id'] ?>, <?= $stars ?>, '<?= htmlspecialchars(addslashes($r['review_note'] ?? '')) ?>')">
              <i class="bi bi-star me-1"></i>Review
            </button>
            <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;"
              onclick="openEditReport(<?= $r['employee_id'] ?>, '<?= $r['report_date'] ?>')">
              <i class="bi bi-pencil"></i>
            </button>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ============== ADD / EDIT REPORT MODAL ============== -->
<div class="modal fade" id="addReportModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-journal-plus text-success me-2"></i>Daily Work Report</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="reportForm">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="save_daily_report">

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Employee <span class="text-danger">*</span></label>
              <select name="employee_id" id="repEmpId" class="form-select form-select-sm" required>
                <option value="">Select Employee</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?> (<?= $emp['employee_code'] ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Report Date <span class="text-danger">*</span></label>
              <input type="date" name="report_date" id="repDate" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Hours Worked</label>
              <input type="number" name="hours_worked" id="repHours" step="0.5" min="0" max="24" class="form-control form-control-sm" placeholder="e.g. 8">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold"><i class="bi bi-sun text-success me-1"></i>Morning Tasks</label>
            <textarea name="morning_tasks" id="repMorning" class="form-control form-control-sm" rows="3" placeholder="What did you work on in the morning..."></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold"><i class="bi bi-moon text-warning me-1"></i>Evening Tasks / Work Done <span class="text-danger">*</span></label>
            <textarea name="evening_tasks" id="repEvening" class="form-control form-control-sm" rows="4" placeholder="Detailed work done during the day — tasks completed, deliverables..." required></textarea>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold"><i class="bi bi-kanban text-primary me-1"></i>Projects Worked On</label>
              <input type="text" name="projects_worked" id="repProjects" class="form-control form-control-sm" placeholder="Project A, Project B...">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold"><i class="bi bi-people text-info me-1"></i>Clients Handled</label>
              <input type="text" name="clients_worked" id="repClients" class="form-control form-control-sm" placeholder="Client X, Client Y...">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold"><i class="bi bi-exclamation-triangle text-danger me-1"></i>Blockers / Issues</label>
            <textarea name="blockers" id="repBlockers" class="form-control form-control-sm" rows="2" placeholder="Any blockers, issues, or dependencies..."></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold"><i class="bi bi-arrow-right-circle" style="color:#8b5cf6;"></i> Plan for Tomorrow</label>
            <textarea name="plan_for_tomorrow" id="repPlan" class="form-control form-control-sm" rows="2" placeholder="What will you work on tomorrow..."></textarea>
          </div>

          <div>
            <label class="form-label small fw-semibold">How was your day?</label>
            <div class="d-flex gap-2 flex-wrap" id="moodBtns">
              <?php foreach ($moodIcons as $moodVal => $moodIcon): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary mood-opt" data-val="<?= $moodVal ?>">
                <?= $moodIcon ?> <?= ucfirst($moodVal) ?>
              </button>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="mood" id="repMood" value="good">
          </div>
        </form>
      </div>
      <div class="modal-footer border-0">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" onclick="submitReport()">
          <i class="bi bi-save me-1"></i>Save Report
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ============== REVIEW MODAL ============== -->
<div class="modal fade" id="reviewModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0"><h5 class="modal-title fw-bold"><i class="bi bi-star text-warning me-2"></i>Review Report</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="reviewReportId">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Rating (1-5 Stars)</label>
          <div class="d-flex gap-2" id="starBtns">
            <?php for ($i=1; $i<=5; $i++): ?>
            <button type="button" class="btn btn-outline-warning star-btn" data-val="<?= $i ?>" style="font-size:1.4rem; padding:.2em .5em;">★</button>
            <?php endfor; ?>
          </div>
          <input type="hidden" id="reviewRating" value="0">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Manager Note</label>
          <textarea class="form-control form-control-sm" id="reviewNote" rows="3" placeholder="Add your feedback..."></textarea>
        </div>
      </div>
      <div class="modal-footer border-0">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-warning" onclick="submitReview()"><i class="bi bi-check-circle me-1"></i>Save Review</button>
      </div>
    </div>
  </div>
</div>

<script>
const API = '<?= BASE_URL ?>modules/team/attendance_api.php';

// Mood selection
document.querySelectorAll('.mood-opt').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.mood-opt').forEach(b => b.classList.remove('active','fw-bold'));
    this.classList.add('active','fw-bold');
    document.getElementById('repMood').value = this.dataset.val;
  });
});

// Pre-select "good"
document.querySelector('.mood-opt[data-val="good"]')?.classList.add('active','fw-bold');

function submitReport() {
  const form = document.getElementById('reportForm');
  const data = new FormData(form);
  fetch(API, { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
      if (res.success) { location.reload(); }
      else alert(res.msg || 'Error saving report');
    });
}

function openEditReport(empId, date) {
  fetch(`${API}?action=get_employee_day&employee_id=${empId}&date=${date}`)
    .then(r => r.json())
    .then(res => {
      if (!res.success) return;
      const rep = res.report;
      if (!rep) { alert('No report found for this date'); return; }
      document.getElementById('repEmpId').value = empId;
      document.getElementById('repDate').value = date;
      document.getElementById('repMorning').value = rep.morning_tasks || '';
      document.getElementById('repEvening').value = rep.evening_tasks || '';
      document.getElementById('repProjects').value = rep.projects_worked || '';
      document.getElementById('repClients').value = rep.clients_worked || '';
      document.getElementById('repHours').value = rep.hours_worked || '';
      document.getElementById('repBlockers').value = rep.blockers || '';
      document.getElementById('repPlan').value = rep.plan_for_tomorrow || '';
      document.getElementById('repMood').value = rep.mood || 'good';
      // Set mood button
      document.querySelectorAll('.mood-opt').forEach(b => {
        b.classList.toggle('active', b.dataset.val === rep.mood);
        b.classList.toggle('fw-bold', b.dataset.val === rep.mood);
      });
      new bootstrap.Modal('#addReportModal').show();
    });
}

// Review
function openReview(reportId, currentRating, currentNote) {
  document.getElementById('reviewReportId').value = reportId;
  document.getElementById('reviewRating').value = currentRating;
  document.getElementById('reviewNote').value = currentNote || '';
  setStars(currentRating);
  new bootstrap.Modal('#reviewModal').show();
}

function setStars(val) {
  document.getElementById('reviewRating').value = val;
  document.querySelectorAll('.star-btn').forEach(btn => {
    btn.classList.toggle('active', parseInt(btn.dataset.val) <= val);
    btn.style.color = parseInt(btn.dataset.val) <= val ? '#f59e0b' : '';
  });
}

document.querySelectorAll('.star-btn').forEach(btn => {
  btn.addEventListener('click', () => setStars(parseInt(btn.dataset.val)));
});

function submitReview() {
  const data = new URLSearchParams({
    action: 'review_report',
    report_id: document.getElementById('reviewReportId').value,
    rating: document.getElementById('reviewRating').value,
    review_note: document.getElementById('reviewNote').value,
    csrf: '<?= $csrf ?>'
  });
  fetch(API, { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
      if (res.success) { location.reload(); }
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
