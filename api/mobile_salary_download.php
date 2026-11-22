<?php
// Crm and backend/api/mobile_salary_download.php — Authenticated Salary Slip Downloader for Mobile App
// Exactly matches format of employee/download-salary-slip.php
require_once __DIR__ . '/mobile_helper.php';

$auth = authenticate_mobile();
$empId = (int)$auth['employee_id'];
$payrollId = intval($_GET['id'] ?? 0);
$payroll = null;

$token = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}
if (empty($token)) {
    $token = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? ($_GET['token'] ?? ($_POST['token'] ?? '')));
}

if ($payrollId > 0) {
    // Strict IDOR Check: Ensure employee only opens their own slip unless Admin/Manager
    $pStmt = $pdo->prepare("SELECT * FROM payroll WHERE id = ? LIMIT 1");
    $pStmt->execute([$payrollId]);
    $payroll = $pStmt->fetch(PDO::FETCH_ASSOC);

    $isAdmin = in_array(strtolower($auth['role'] ?? ''), ['admin', 'super admin', 'superadmin']);
    if ($payroll && !$isAdmin && (int)$payroll['employee_id'] !== $empId) {
        http_response_code(403);
        die("Access Denied: You are not authorized to view this salary slip.");
    }
} else {
    // If no specific ID provided, check by month & year or fallback to latest payroll
    $m = intval($_GET['month'] ?? 0);
    $y = intval($_GET['year'] ?? 0);
    if ($m > 0 && $y > 0) {
        $pStmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? AND payroll_month = ? AND payroll_year = ? LIMIT 1");
        $pStmt->execute([$empId, $m, $y]);
        $payroll = $pStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$payroll) {
        $pStmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? ORDER BY payroll_year DESC, payroll_month DESC LIMIT 1");
        $pStmt->execute([$empId]);
        $payroll = $pStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$payroll) {
        $mCur = $m > 0 ? $m : (int)date('m');
        $yCur = $y > 0 ? $y : (int)date('Y');
        $eStmt = $pdo->prepare("SELECT base_salary FROM employees WHERE id = ?");
        $eStmt->execute([$empId]);
        $baseSalary = (float)($eStmt->fetchColumn() ?: 0);
        $payroll = [
            'id' => 0,
            'employee_id' => $empId,
            'payroll_month' => $mCur,
            'payroll_year' => $yCur,
            'base_salary' => $baseSalary,
            'allowances' => 0,
            'bonus' => 0,
            'advance_deducted' => 0,
            'late_deduction' => 0,
            'other_deduction' => 0,
            'net_salary' => $baseSalary,
            'status' => 'PROJECTED'
        ];
    }
}

if (!$payroll) {
    die("Salary slip not found.");
}

// Fetch Employee Details
$eStmt = $pdo->prepare("
    SELECT e.*, d.name as dept_title, des.name as desig_title 
    FROM employees e 
    LEFT JOIN departments d ON d.id = e.department_id 
    LEFT JOIN designations des ON des.id = e.designation_id 
    WHERE e.id = ?
");
$eStmt->execute([$payroll['employee_id']]);
$emp = $eStmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) {
    die("Employee details not found.");
}

// Fetch Attendance statistics for this payroll month
$m = (int)$payroll['payroll_month'];
$y = (int)$payroll['payroll_year'];
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $m, $y);
$mStart = sprintf('%04d-%02d-01', $y, $m);
$mEnd   = sprintf('%04d-%02d-%02d', $y, $m, $daysInMonth);

$attStmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(late_minutes) as total_late_min,
        SUM(CASE WHEN status NOT IN ('absent', 'on_leave') THEN total_hours ELSE 0 END) as total_work_hours
    FROM attendance 
    WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
");
$attStmt->execute([$emp['id'], $mStart, $mEnd]);
$attSummary = $attStmt->fetch(PDO::FETCH_ASSOC);

$monthName = date('F Y', mktime(0, 0, 0, $m, 1, $y));
$netSalary = (float)$payroll['net_salary'];
$words = function_exists('amount_in_words') ? amount_in_words($netSalary) : (function_exists('convert_number_to_words') ? 'Rupees ' . convert_number_to_words($netSalary) : 'Rupees ' . number_format($netSalary, 2));

$format = strtolower($_GET['format'] ?? 'html');
$logoUrl = function_exists('get_company_logo') ? get_company_logo() : (BASE_URL . 'assets/images/logo.png');

$grossEarnings = (float)$payroll['base_salary'] + (float)($payroll['allowances'] ?? 0) + (float)($payroll['bonus'] ?? 0);
$totalDeductions = (float)$payroll['late_deduction'] + (float)$payroll['other_deduction'] + (float)($payroll['advance_deducted'] ?? 0);

// If PDF output requested via mPDF
if ($format === 'pdf') {
    require_once __DIR__ . '/../vendor/autoload.php';
    try {
        $mpdf = new \Mpdf\Mpdf([
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 12,
            'tempDir' => sys_get_temp_dir(),
        ]);

        $pdfHtml = '
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="UTF-8">
          <style>
            body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1e293b; }
            .slip-container { border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; background: #fff; }
            .header-table { width: 100%; border-bottom: 2px solid #0d6efd; padding-bottom: 12px; margin-bottom: 16px; }
            .brand-name { font-size: 20px; font-weight: bold; color: #0d6efd; margin: 0; }
            .brand-sub { font-size: 10px; color: #64748b; line-height: 1.4; }
            .payslip-badge { background: #e0f2fe; color: #0d6efd; padding: 4px 12px; font-size: 12px; font-weight: bold; border-radius: 4px; display: inline-block; }
            .info-grid { width: 100%; margin-bottom: 16px; }
            .info-cell { width: 50%; vertical-align: top; }
            .info-table { width: 100%; font-size: 10.5px; border-collapse: collapse; }
            .info-table td { padding: 4px 6px; border: none; }
            .lbl { color: #64748b; width: 40%; }
            .val { font-weight: 600; color: #1e293b; }
            .calc-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5px; }
            .calc-table th { background: #f1f5f9; font-weight: 600; padding: 7px 10px; border: 1px solid #cbd5e1; text-align: left; }
            .calc-table td { padding: 6px 10px; border: 1px solid #e2e8f0; }
            .text-right { text-align: right; }
            .total-row { background: #f8fafc; font-weight: bold; }
            .net-box { width: 100%; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; margin-bottom: 24px; }
            .net-amount { font-size: 18px; font-weight: bold; color: #16a34a; }
            .badge-status { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 11px; }
            .sig-table { width: 100%; margin-top: 30px; text-align: center; }
            .sig-line { border-top: 1px solid #cbd5e1; width: 180px; margin: auto; padding-top: 6px; font-size: 10px; color: #64748b; }
            .footer-note { text-align: center; font-size: 9px; color: #94a3b8; margin-top: 16px; }
          </style>
        </head>
        <body>
          <div class="slip-container">
            <table class="header-table">
              <tr>
                <td style="width: 65%;">
                  <div class="brand-name">VIBESOFT</div>
                  <div class="brand-sub">VibeSoft IT Solutions & Management Services</div>
                  <div class="brand-sub">Website: https://vibesoft.in · contact.vibesoft@gmail.com</div>
                </td>
                <td style="width: 35%; text-align: right;">
                  <div style="font-size: 14px; font-weight: bold; color: #1e293b; margin-bottom: 4px;">PAYSLIP</div>
                  <div class="payslip-badge">' . htmlspecialchars($monthName) . '</div>
                </td>
              </tr>
            </table>

            <table class="info-grid">
              <tr>
                <td class="info-cell" style="padding-right: 10px;">
                  <table class="info-table">
                    <tr><td class="lbl">Employee Name:</td><td class="val">' . htmlspecialchars($emp['full_name']) . '</td></tr>
                    <tr><td class="lbl">Employee Code:</td><td class="val" style="color:#0d6efd;">' . htmlspecialchars($emp['employee_code'] ?? 'EMP-'.$emp['id']) . '</td></tr>
                    <tr><td class="lbl">Department:</td><td class="val">' . htmlspecialchars($emp['dept_title'] ?? ($emp['department'] ?? 'Operations')) . '</td></tr>
                    <tr><td class="lbl">Designation:</td><td class="val">' . htmlspecialchars($emp['desig_title'] ?? ($emp['designation'] ?? 'Team Member')) . '</td></tr>
                    <tr><td class="lbl">Date of Joining:</td><td class="val">' . (!empty($emp['joining_date']) ? date('d M Y', strtotime($emp['joining_date'])) : '—') . '</td></tr>
                  </table>
                </td>
                <td class="info-cell" style="padding-left: 10px;">
                  <table class="info-table">
                    <tr><td class="lbl">Bank Name:</td><td class="val">' . htmlspecialchars($emp['bank_name'] ?? 'State Bank of India') . '</td></tr>
                    <tr><td class="lbl">Account No:</td><td class="val">' . (!empty($emp['bank_acc_no']) ? htmlspecialchars($emp['bank_acc_no']) : 'On File') . '</td></tr>
                    <tr><td class="lbl">IFSC Code:</td><td class="val">' . htmlspecialchars($emp['bank_ifsc'] ?? 'SBIN0000000') . '</td></tr>
                    <tr><td class="lbl">PAN / ID:</td><td class="val">' . htmlspecialchars($emp['pan'] ?? '—') . '</td></tr>
                    <tr><td class="lbl">Attendance:</td><td class="val"><strong>' . (int)($attSummary['present_days'] ?? 26) . '</strong> Present · <strong>' . (int)($attSummary['late_days'] ?? 0) . '</strong> Late</td></tr>
                  </table>
                </td>
              </tr>
            </table>

            <table class="calc-table">
              <thead>
                <tr>
                  <th style="width: 35%;">Earnings</th>
                  <th style="width: 15%; text-align: right;">Amount (₹)</th>
                  <th style="width: 35%;">Deductions</th>
                  <th style="width: 15%; text-align: right;">Amount (₹)</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Basic Salary</td>
                  <td class="text-right">₹' . number_format((float)$payroll['base_salary'], 2) . '</td>
                  <td>Late Arrival Deduction</td>
                  <td class="text-right" style="color:#dc2626;">' . ((float)$payroll['late_deduction'] > 0 ? '₹' . number_format((float)$payroll['late_deduction'], 2) : '₹0.00') . '</td>
                </tr>
                <tr>
                  <td>Allowances</td>
                  <td class="text-right">₹' . number_format((float)($payroll['allowances'] ?? 0), 2) . '</td>
                  <td>Absence / Leave Deductions</td>
                  <td class="text-right" style="color:#dc2626;">' . ((float)$payroll['other_deduction'] > 0 ? '₹' . number_format((float)$payroll['other_deduction'], 2) : '₹0.00') . '</td>
                </tr>
                <tr>
                  <td>Bonus / Incentives</td>
                  <td class="text-right">₹' . number_format((float)($payroll['bonus'] ?? 0), 2) . '</td>
                  <td>Advance Salary Deducted</td>
                  <td class="text-right" style="color:#dc2626;">' . ((float)($payroll['advance_deducted'] ?? 0) > 0 ? '₹' . number_format((float)$payroll['advance_deducted'], 2) : '₹0.00') . '</td>
                </tr>
                <tr class="total-row">
                  <td>Total Gross Earnings</td>
                  <td class="text-right" style="color:#16a34a;">₹' . number_format($grossEarnings, 2) . '</td>
                  <td>Total Deductions</td>
                  <td class="text-right" style="color:#dc2626;">₹' . number_format($totalDeductions, 2) . '</td>
                </tr>
              </tbody>
            </table>

            <table class="net-box">
              <tr>
                <td>
                  <div style="font-size: 9.5px; color: #64748b;">NET AMOUNT PAYABLE:</div>
                  <div class="net-amount">₹' . number_format($netSalary, 2) . '</div>
                  <div style="font-size: 9px; color: #64748b;">' . htmlspecialchars($words) . '</div>
                </td>
                <td style="text-align: right; vertical-align: middle;">
                  <span class="badge-status">STATUS: ' . strtoupper($payroll['status'] ?? 'PROCESSED') . '</span>' .
                  (!empty($payroll['payment_date']) ? '<div style="font-size: 9px; color: #64748b; margin-top: 4px;">Paid on ' . date('d M Y', strtotime($payroll['payment_date'])) . '</div>' : '') . '
                </td>
              </tr>
            </table>

            <table class="sig-table">
              <tr>
                <td style="width: 50%;">
                  <div class="sig-line">Employee Signature</div>
                </td>
                <td style="width: 50%;">
                  <div class="sig-line"><strong>VibeSoft Solutions</strong><br>Authorized Signatory</div>
                </td>
              </tr>
            </table>

            <div class="footer-note">
              * Computer generated payslip issued by VibeSoft CMS. No physical signature required.
            </div>
          </div>
        </body>
        </html>';

        $cleanEmpCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($emp['employee_code'] ?? 'EMP'));
        $cleanMonth = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$monthName);
        $filename = "Salary_Slip_{$cleanEmpCode}_{$cleanMonth}.pdf";
        $mpdf->SetTitle("Salary Slip - {$cleanEmpCode} - {$monthName}");
        $mpdf->WriteHTML($pdfHtml);
        $mpdf->Output($filename, 'D');
        exit;
    } catch (\Throwable $e) {
        // Fallback to HTML if PDF error
    }
}

// Default & HTML output: EXACT 1:1 match with Crm and backend/employee/download-salary-slip.php
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Salary Slip — <?= htmlspecialchars($emp['employee_code'] ?? 'EMP') ?> — <?= htmlspecialchars($monthName) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
  <a href="?id=<?= (int)$payroll['id'] ?>&token=<?= urlencode($token) ?>&format=pdf" class="btn btn-outline-primary px-3 ms-2 fw-semibold">
    <i class="bi bi-file-earmark-pdf me-1"></i> Direct PDF
  </a>
  <button class="btn btn-outline-secondary px-3 ms-2" onclick="window.close()">Close</button>
</div>

<div class="slip-container">

  <!-- Header -->
  <div class="slip-header d-flex justify-content-between align-items-center">
    <div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="VibeSoft" height="34" style="max-width: 140px; object-fit: contain;">
        <h3 class="fw-bold text-primary mb-0">VIBESOFT</h3>
      </div>
      <div class="text-muted small">VibeSoft IT Solutions & Management Services</div>
      <div class="text-muted small">Website: https://vibesoft.in · contact.vibesoft@gmail.com</div>
    </div>
    <div class="text-end">
      <h5 class="fw-bold text-dark mb-1">PAYSLIP</h5>
      <div class="badge bg-primary-subtle text-primary border px-3 py-1 fs-6"><?= htmlspecialchars($monthName) ?></div>
    </div>
  </div>

  <!-- Employee & Attendance Info Grid -->
  <div class="row g-3 mb-4">
    <div class="col-6">
      <table class="table info-table mb-0">
        <tr><td class="text-muted" style="width:40%;">Employee Name:</td><td class="fw-bold"><?= htmlspecialchars($emp['full_name']) ?></td></tr>
        <tr><td class="text-muted">Employee Code:</td><td class="fw-semibold text-primary"><?= htmlspecialchars($emp['employee_code'] ?? 'EMP-'.$emp['id']) ?></td></tr>
        <tr><td class="text-muted">Department:</td><td><?= htmlspecialchars($emp['dept_title'] ?? ($emp['department'] ?? 'Operations')) ?></td></tr>
        <tr><td class="text-muted">Designation:</td><td><?= htmlspecialchars($emp['desig_title'] ?? ($emp['designation'] ?? 'Team Member')) ?></td></tr>
        <tr><td class="text-muted">Date of Joining:</td><td><?= !empty($emp['joining_date']) ? date('d M Y', strtotime($emp['joining_date'])) : '—' ?></td></tr>
      </table>
    </div>
    <div class="col-6">
      <table class="table info-table mb-0">
        <tr><td class="text-muted" style="width:40%;">Bank Name:</td><td><?= htmlspecialchars($emp['bank_name'] ?? 'State Bank of India') ?></td></tr>
        <tr><td class="text-muted">Account No:</td><td><?= !empty($emp['bank_acc_no']) ? htmlspecialchars($emp['bank_acc_no']) : 'On File' ?></td></tr>
        <tr><td class="text-muted">IFSC Code:</td><td><?= htmlspecialchars($emp['bank_ifsc'] ?? 'SBIN0000000') ?></td></tr>
        <tr><td class="text-muted">PAN / ID:</td><td><?= htmlspecialchars($emp['pan'] ?? '—') ?></td></tr>
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
          <td class="text-end text-success">₹<?= number_format($grossEarnings, 2) ?></td>
          <td>Total Deductions</td>
          <td class="text-end text-danger">₹<?= number_format($totalDeductions, 2) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Net Payable Box -->
  <div class="p-3 bg-light rounded-3 border mb-4 d-flex justify-content-between align-items-center">
    <div>
      <div class="text-muted small">NET AMOUNT PAYABLE:</div>
      <div class="fw-bold fs-4 text-success">₹<?= number_format($netSalary, 2) ?></div>
      <div class="text-muted small" style="font-size:0.75rem;"><?= htmlspecialchars($words) ?></div>
    </div>
    <div class="text-end">
      <div class="badge bg-success px-3 py-2 fs-6">STATUS: <?= strtoupper($payroll['status'] ?? 'PROCESSED') ?></div>
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
      <div style="border-top:1px solid #cbd5e1; width:200px; margin:auto; padding-top:8px;">
        <span class="small fw-semibold text-dark">VibeSoft Solutions</span><br>
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
