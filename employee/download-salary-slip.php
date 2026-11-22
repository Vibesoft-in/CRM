<?php
// employee/download-salary-slip.php — Secure Authenticated Salary Slip Generator
require_once __DIR__ . '/../includes/functions.php';

// Support authentication from mobile app token
if (!is_logged_in() && !empty($_GET['token'])) {
    $mobileToken = trim($_GET['token']);
    $tokenHash = hash('sha256', $mobileToken);
    $sStmt = $pdo->prepare("SELECT s.user_id, u.role, u.employee_id 
                            FROM user_sessions s 
                            JOIN users u ON u.id = s.user_id 
                            WHERE s.session_id_hash = ? AND s.expires_at > NOW() AND s.revoked_at IS NULL 
                            LIMIT 1");
    $sStmt->execute([$tokenHash]);
    $sessUser = $sStmt->fetch();
    if (!$sessUser) {
        $uStmt = $pdo->prepare("SELECT id as user_id, role, employee_id FROM users WHERE api_key = ? LIMIT 1");
        $uStmt->execute([$mobileToken]);
        $sessUser = $uStmt->fetch();
    }
    if ($sessUser) {
        $_SESSION['user_id'] = $sessUser['user_id'];
        $_SESSION['role'] = $sessUser['role'];
        $_SESSION['employee_id'] = $sessUser['employee_id'];
        $_SESSION['last_activity'] = time();
    }
}

require_login();
\App\Security\SecurityHeaders::applyNoCache();

$payrollId = intval($_GET['id'] ?? 0);
if ($payrollId <= 0) {
    abort_forbidden("Invalid Salary Slip ID.");
}

// Strict Server-Side Verification: Employee can ONLY view their own salary slip!
$payroll = authorize_salary_slip($payrollId);

$companySettings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch() ?: [];
$companyName = $companySettings['company_name'] ?? 'VibeSoft Solutions';
$companySigBase64 = '';
if (!empty($companySettings['signature'])) {
    $cleanSig = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $companySettings['signature']), DIRECTORY_SEPARATOR);
    $fullSigPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $cleanSig;
    if (file_exists($fullSigPath) && is_file($fullSigPath)) {
        $sigMime = mime_content_type($fullSigPath) ?: 'image/png';
        $companySigBase64 = 'data:' . $sigMime . ';base64,' . base64_encode(file_get_contents($fullSigPath));
    }
}

// 3. Fetch Employee Details
$eStmt = $pdo->prepare("SELECT e.*, d.name as dept_title, des.name as desig_title 
                        FROM employees e 
                        LEFT JOIN departments d ON d.id = e.department_id 
                        LEFT JOIN designations des ON des.id = e.designation_id 
                        WHERE e.id = ?");
$eStmt->execute([$payroll['employee_id']]);
$emp = $eStmt->fetch();

if (!$emp) {
    die("Employee details not found.");
}

// 4. Fetch Attendance statistics for this payroll month
$m = $payroll['payroll_month'];
$y = $payroll['payroll_year'];
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $m, $y);
$mStart = "$y-" . str_pad($m, 2, '0', STR_PAD_LEFT) . "-01";
$mEnd   = "$y-" . str_pad($m, 2, '0', STR_PAD_LEFT) . "-$daysInMonth";

$attStmt = $pdo->prepare("SELECT 
    SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
    SUM(late_minutes) as total_late_min,
    SUM(CASE WHEN status NOT IN ('absent', 'on_leave') THEN total_hours ELSE 0 END) as total_work_hours
  FROM attendance 
  WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$attStmt->execute([$emp['id'], $mStart, $mEnd]);
$attSummary = $attStmt->fetch();

$monthName = date('F Y', mktime(0, 0, 0, $m, 1, $y));
$netSalary = (float)$payroll['net_salary'];
$words = function_exists('amount_in_words') ? amount_in_words($netSalary) : 'Rupees ' . number_format($netSalary, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Salary Slip — <?= e($emp['employee_code']) ?> — <?= $monthName ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
    .slip-container { max-width: 820px; margin: 30px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 40px; border: 1px solid #e2e8f0; }
    .slip-header { border-bottom: 2px solid #0d6efd; padding-bottom: 20px; margin-bottom: 25px; }
    .info-table td { padding: 6px 12px; font-size: 0.85rem; border: none; }
    .calc-table th { background: #f1f5f9; font-weight: 600; font-size: 0.85rem; padding: 10px 14px; }
    .calc-table td { padding: 8px 14px; font-size: 0.85rem; }
    @media print {
      body { background: #fff; }
      .slip-container { box-shadow: none; border: none; padding: 0; margin: 0; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>

<div class="container py-3 no-print text-center">
  <button class="btn btn-primary px-4 fw-semibold" onclick="window.print()">
    <i class="bi bi-printer me-1"></i> Print / Download PDF
  </button>
  <button class="btn btn-outline-secondary px-3 ms-2" onclick="window.close()">Close</button>
</div>

<div class="slip-container">

  <!-- Header -->
  <div class="slip-header d-flex justify-content-between align-items-center">
    <div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <img src="<?= get_company_logo() ?>" alt="VibeSoft" height="34" style="max-width: 140px; object-fit: contain;">
        <h3 class="fw-bold text-primary mb-0">VIBESOFT</h3>
      </div>
      <div class="text-muted small">VibeSoft IT Solutions & Management Services</div>
      <div class="text-muted small">Website: https://vibesoft.in · contact.vibesoft@gmail.com</div>
    </div>
    <div class="text-end">
      <h5 class="fw-bold text-dark mb-1">PAYSLIP</h5>
      <div class="badge bg-primary-subtle text-primary border px-3 py-1 fs-6"><?= $monthName ?></div>
    </div>
  </div>

  <!-- Employee & Attendance Info Grid -->
  <div class="row g-3 mb-4">
    <div class="col-6">
      <table class="table info-table mb-0">
        <tr><td class="text-muted" style="width:40%;">Employee Name:</td><td class="fw-bold"><?= e($emp['full_name']) ?></td></tr>
        <tr><td class="text-muted">Employee Code:</td><td class="fw-semibold text-primary"><?= e($emp['employee_code']) ?></td></tr>
        <tr><td class="text-muted">Department:</td><td><?= e($emp['department'] ?? 'Operations') ?></td></tr>
        <tr><td class="text-muted">Designation:</td><td><?= e($emp['designation'] ?? 'Team Member') ?></td></tr>
        <tr><td class="text-muted">Date of Joining:</td><td><?= date('d M Y', strtotime($emp['joining_date'])) ?></td></tr>
      </table>
    </div>
    <div class="col-6">
      <table class="table info-table mb-0">
        <tr><td class="text-muted" style="width:40%;">Bank Name:</td><td><?= e($emp['bank_name'] ?? 'State Bank of India') ?></td></tr>
        <tr><td class="text-muted">Account No:</td><td><?= !empty($emp['bank_acc_no']) ? e($emp['bank_acc_no']) : 'On File' ?></td></tr>
        <tr><td class="text-muted">IFSC Code:</td><td><?= e($emp['bank_ifsc'] ?? 'SBIN0000000') ?></td></tr>
        <tr><td class="text-muted">PAN / ID:</td><td><?= e($emp['pan'] ?? '—') ?></td></tr>
        <tr>
          <td class="text-muted">Attendance:</td>
          <td>
            <strong><?= (int)($attSummary['present_days'] ?? 26) ?></strong> Present 
            · <strong><?= (int)($attSummary['late_days'] ?? 0) ?></strong> Late
          </td>
        </tr>
      </table>
    </div>
  </div>

  <!-- Salary Breakdown Table -->
  <div class="table-responsive mb-4">
    <table class="table table-bordered calc-table mb-0">
      <thead>
        <tr>
          <th style="width:50%;">Earnings</th>
          <th class="text-end">Amount (₹)</th>
          <th style="width:50%;">Deductions</th>
          <th class="text-end">Amount (₹)</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Basic Salary</td>
          <td class="text-end fw-semibold">₹<?= number_format((float)$payroll['base_salary'], 2) ?></td>
          <td>Late Arrival Deduction</td>
          <td class="text-end text-danger"><?= (float)$payroll['late_deduction'] > 0 ? '₹' . number_format((float)$payroll['late_deduction'], 2) : '₹0.00' ?></td>
        </tr>
        <tr>
          <td>Allowances</td>
          <td class="text-end">₹<?= number_format((float)($payroll['allowances'] ?? 0), 2) ?></td>
          <td>Absence / Leave Deductions</td>
          <td class="text-end text-danger"><?= (float)$payroll['other_deduction'] > 0 ? '₹' . number_format((float)$payroll['other_deduction'], 2) : '₹0.00' ?></td>
        </tr>
        <tr>
          <td>Bonus / Incentives</td>
          <td class="text-end">₹<?= number_format((float)($payroll['bonus'] ?? 0), 2) ?></td>
          <td>Advance Salary Deducted</td>
          <td class="text-end text-danger"><?= (float)($payroll['advance_deducted'] ?? 0) > 0 ? '₹' . number_format((float)$payroll['advance_deducted'], 2) : '₹0.00' ?></td>
        </tr>
        <tr class="table-light fw-bold">
          <td>Total Gross Earnings</td>
          <td class="text-end text-success">₹<?= number_format((float)$payroll['base_salary'] + (float)($payroll['allowances'] ?? 0) + (float)($payroll['bonus'] ?? 0), 2) ?></td>
          <td>Total Deductions</td>
          <td class="text-end text-danger">₹<?= number_format((float)$payroll['late_deduction'] + (float)$payroll['other_deduction'] + (float)($payroll['advance_deducted'] ?? 0), 2) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Net Payable Box -->
  <div class="p-3 bg-light rounded-3 border mb-4 d-flex justify-content-between align-items-center">
    <div>
      <div class="text-muted small">NET AMOUNT PAYABLE:</div>
      <div class="fw-bold fs-4 text-success">₹<?= number_format($netSalary, 2) ?></div>
      <div class="text-muted small" style="font-size:0.75rem;"><?= $words ?></div>
    </div>
    <div class="text-end">
      <div class="badge bg-success px-3 py-2 fs-6">STATUS: <?= strtoupper($payroll['status']) ?></div>
      <?php if (!empty($payroll['payment_date'])): ?>
        <div class="text-muted small mt-1">Paid on <?= date('d M Y', strtotime($payroll['payment_date'])) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Signatures -->
  <div class="row pt-5 mt-4 text-center">
    <div class="col-6">
      <div style="border-top:1px solid #cbd5e1; width:200px; margin:auto; padding-top:8px;">
        <span class="small text-muted">Employee Signature</span>
      </div>
    </div>
    <div class="col-6">
      <div style="border-top:1px solid #cbd5e1; width:200px; margin:auto; padding-top:8px; position:relative;">
        <?php if (!empty($companySigBase64)): ?>
          <div style="height:48px; margin-top:-52px; margin-bottom:4px; text-align:center;">
            <img src="<?= $companySigBase64 ?>" style="max-height:46px; max-width:150px; object-fit:contain;" alt="Authorized Signature">
          </div>
        <?php endif; ?>
        <span class="small fw-semibold text-dark"><?= e($companyName) ?></span><br>
        <span class="small text-muted">Authorized Signatory</span>
      </div>
    </div>
  </div>

  <div class="text-center text-muted mt-4" style="font-size:0.7rem;">
    * Computer generated payslip issued by VibeSoft CMS. No physical signature required.
  </div>

</div>

</body>
</html>
