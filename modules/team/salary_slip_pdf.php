<?php
require_once '../../includes/functions.php';
require_login();
\App\Security\SecurityHeaders::applyNoCache();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    abort_forbidden("Invalid Payroll ID.");
}

// Strict Server-Side Verification: Employee can ONLY view their own salary slip!
$verifiedPayroll = authorize_salary_slip($id);

$stmt = $pdo->prepare("
    SELECT p.*, emp.full_name, emp.employee_code, emp.designation, emp.department, emp.joining_date, emp.bank_name, emp.bank_acc_no, emp.bank_ifsc, emp.pan
    FROM payroll p
    JOIN employees emp ON emp.id = p.employee_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$slip = $stmt->fetch();
if (!$slip) abort_forbidden("Salary slip record not found.");

// Company Settings
$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch() ?: [];
$companyName    = $settings['company_name'] ?? 'Vibesoft ERP';
$companyAddress = $settings['address'] ?? '';
$companyPhone   = $settings['phone'] ?? '';
$companyEmail   = $settings['email'] ?? '';
$companyWebsite = $settings['website'] ?? '';
$companyGstin   = $settings['gstin'] ?? '';
$companyPan     = $settings['pan'] ?? '';
$companyLogo    = $settings['logo'] ?? '';

$grossEarnings   = (float)$slip['base_salary'] + (float)$slip['allowances'] + (float)$slip['bonus'];
$totalDeductions = (float)$slip['advance_deducted'] + (float)$slip['late_deduction'] + (float)$slip['other_deduction'];
$netSalary       = (float)$slip['net_salary'];
$monthYearStr    = date("F Y", mktime(0, 0, 0, $slip['payroll_month'], 1, $slip['payroll_year']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payslip - <?= e($slip['full_name']) ?> (<?= $monthYearStr ?>)</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    :root {
      --primary: #0d6efd;
      --dark: #1e293b;
      --light-bg: #f8fafc;
      --border-color: #e2e8f0;
    }

    body {
      background: #f1f5f9;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      color: var(--dark);
    }

    .slip-card {
      max-width: 850px;
      margin: 30px auto;
      background: #ffffff;
      padding: 40px;
      border-radius: 16px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.06);
      border: 1px solid var(--border-color);
    }

    .company-logo {
      max-height: 55px;
      max-width: 220px;
      object-fit: contain;
    }

    .payslip-badge {
      background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
      color: #ffffff;
      padding: 6px 16px;
      border-radius: 50rem;
      font-size: 0.85rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .info-table td {
      padding: 6px 12px;
      font-size: 0.875rem;
    }

    .info-label {
      color: #64748b;
      font-weight: 500;
      width: 140px;
    }

    .info-value {
      color: #0f172a;
      font-weight: 600;
    }

    .component-table {
      width: 100%;
      border-collapse: collapse;
    }

    .component-table th {
      background: #f8fafc;
      padding: 10px 16px;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #475569;
      border-bottom: 2px solid var(--border-color);
    }

    .component-table td {
      padding: 10px 16px;
      font-size: 0.875rem;
      border-bottom: 1px solid #f1f5f9;
    }

    .net-box {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      color: #ffffff;
      border-radius: 12px;
      padding: 20px 24px;
    }

    .sign-box {
      border-top: 1px dashed #cbd5e1;
      margin-top: 50px;
      padding-top: 12px;
    }

    @media print {
      body { background: #ffffff !important; }
      .slip-card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; margin: 0 auto !important; padding: 25px !important; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>

<!-- Printable Top Action Bar -->
<div class="container no-print text-center my-4" style="max-width: 850px;">
  <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded-3 shadow-sm border">
    <a href="payroll.php" class="btn btn-outline-secondary btn-sm fw-semibold">
      <i class="bi bi-arrow-left me-1"></i> Back to Payroll
    </a>
    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-file-earmark-pdf text-danger me-2"></i>Official Salary Payslip</h6>
    <button onclick="window.print()" class="btn btn-primary btn-sm fw-semibold shadow-sm">
      <i class="bi bi-printer me-1"></i> Print / Save PDF
    </button>
  </div>
</div>

<!-- Main Salary Slip Document -->
<div class="slip-card">
  
  <!-- Header: Company Brand + Payslip Title -->
  <div class="d-flex justify-content-between align-items-start border-bottom pb-4 mb-4">
    <div>
      <?php if (!empty($companyLogo)): ?>
        <img src="<?= BASE_URL . e($companyLogo) ?>" alt="Logo" class="company-logo mb-2">
      <?php else: ?>
        <h4 class="fw-extrabold text-primary mb-1" style="letter-spacing: -0.02em;"><?= e($companyName) ?></h4>
      <?php endif; ?>

      <h5 class="fw-bold text-dark mb-1"><?= e($companyName) ?></h5>
      <p class="text-muted small mb-1" style="max-width: 450px; line-height: 1.4;"><?= nl2br(e($companyAddress)) ?></p>
      
      <div class="d-flex flex-wrap gap-3 small text-muted mt-2">
        <?php if ($companyGstin): ?>
          <span><strong class="text-dark">GSTIN:</strong> <?= e($companyGstin) ?></span>
        <?php endif; ?>
        <?php if ($companyPan): ?>
          <span><strong class="text-dark">PAN:</strong> <?= e($companyPan) ?></span>
        <?php endif; ?>
        <?php if ($companyPhone): ?>
          <span><strong class="text-dark">Phone:</strong> <?= e($companyPhone) ?></span>
        <?php endif; ?>
        <?php if ($companyEmail): ?>
          <span><strong class="text-dark">Email:</strong> <?= e($companyEmail) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="text-end">
      <span class="payslip-badge d-inline-block mb-2">PAYSLIP</span>
      <h6 class="fw-bold text-dark mb-0"><?= $monthYearStr ?></h6>
      <small class="text-muted d-block">Generated: <?= date('d M Y', strtotime($slip['created_at'] ?? 'now')) ?></small>
    </div>
  </div>

  <!-- Employee Info Grid -->
  <div class="card border-0 bg-light mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <div class="row g-2">
        <div class="col-md-6">
          <table class="info-table w-100">
            <tr>
              <td class="info-label">Employee Name:</td>
              <td class="info-value text-primary fs-6"><?= e($slip['full_name']) ?></td>
            </tr>
            <tr>
              <td class="info-label">Employee ID:</td>
              <td class="info-value font-monospace"><?= e($slip['employee_code']) ?></td>
            </tr>
            <tr>
              <td class="info-label">Designation:</td>
              <td class="info-value"><?= e($slip['designation'] ?: 'Team Member') ?></td>
            </tr>
            <tr>
              <td class="info-label">Department:</td>
              <td class="info-value"><?= e($slip['department'] ?: 'General') ?></td>
            </tr>
          </table>
        </div>

        <div class="col-md-6">
          <table class="info-table w-100">
            <tr>
              <td class="info-label">Joining Date:</td>
              <td class="info-value"><?= !empty($slip['joining_date']) ? date('d M Y', strtotime($slip['joining_date'])) : 'N/A' ?></td>
            </tr>
            <tr>
              <td class="info-label">Bank Name:</td>
              <td class="info-value"><?= e($slip['bank_name'] ?: 'N/A') ?></td>
            </tr>
            <tr>
              <td class="info-label">Bank Account:</td>
              <td class="info-value font-monospace"><?= e($slip['bank_acc_no'] ?: 'N/A') ?></td>
            </tr>
            <tr>
              <td class="info-label">IFSC / PAN:</td>
              <td class="info-value"><?= e($slip['bank_ifsc'] ?: 'N/A') ?> <?= $slip['pan'] ? ' / '.e($slip['pan']) : '' ?></td>
            </tr>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Earnings & Deductions Breakdown Table -->
  <div class="row g-0 border rounded-3 overflow-hidden mb-4">
    <!-- EARNINGS -->
    <div class="col-6 border-end">
      <div class="bg-light px-3 py-2 fw-bold text-dark border-bottom d-flex justify-content-between align-items-center">
        <span><i class="bi bi-arrow-up-right-circle text-success me-1"></i> EARNINGS</span>
        <small class="text-muted">AMOUNT (₹)</small>
      </div>
      <table class="component-table">
        <tbody>
          <tr>
            <td>Basic Salary</td>
            <td class="text-end fw-semibold">₹<?= money($slip['base_salary']) ?></td>
          </tr>
          <tr>
            <td>Special Allowances</td>
            <td class="text-end fw-semibold">₹<?= money($slip['allowances']) ?></td>
          </tr>
          <tr>
            <td>Performance Bonus</td>
            <td class="text-end fw-semibold">₹<?= money($slip['bonus']) ?></td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="bg-light fw-bold">
            <td class="text-dark">GROSS EARNINGS</td>
            <td class="text-end text-success fs-6">₹<?= money($grossEarnings) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- DEDUCTIONS -->
    <div class="col-6">
      <div class="bg-light px-3 py-2 fw-bold text-dark border-bottom d-flex justify-content-between align-items-center">
        <span><i class="bi bi-arrow-down-left-circle text-danger me-1"></i> DEDUCTIONS</span>
        <small class="text-muted">AMOUNT (₹)</small>
      </div>
      <table class="component-table">
        <tbody>
          <tr>
            <td>Salary Advance Recovery</td>
            <td class="text-end text-danger fw-semibold">₹<?= money($slip['advance_deducted']) ?></td>
          </tr>
          <tr>
            <td>Late / Absence Deduction</td>
            <td class="text-end text-danger fw-semibold">₹<?= money($slip['late_deduction']) ?></td>
          </tr>
          <tr>
            <td>Other Deductions</td>
            <td class="text-end text-danger fw-semibold">₹<?= money($slip['other_deduction']) ?></td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="bg-light fw-bold">
            <td class="text-dark">TOTAL DEDUCTIONS</td>
            <td class="text-end text-danger fs-6">₹<?= money($totalDeductions) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- Net Salary Disbursed Banner -->
  <div class="net-box mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div>
        <span class="small text-uppercase opacity-75 fw-semibold d-block mb-1" style="letter-spacing: 0.05em;">NET SALARY DISBURSED</span>
        <h2 class="fw-bold mb-0 text-white">₹<?= money($netSalary) ?></h2>
        <small class="opacity-75 italic mt-1 d-block">
          Amount in words: <em><?= e(amount_in_words($netSalary)) ?></em>
        </small>
      </div>
      
      <div class="text-end border-start border-secondary ps-4">
        <div class="small opacity-75 mb-1">Payment Method: <strong class="text-white"><?= e($slip['payment_method']) ?></strong></div>
        <div class="small opacity-75 mb-1">Disbursement Date: <strong class="text-white"><?= !empty($slip['payment_date']) ? date('d M Y', strtotime($slip['payment_date'])) : 'N/A' ?></strong></div>
        <div class="small opacity-75">Ref / UTR: <strong class="text-white font-monospace"><?= e($slip['transaction_ref'] ?: 'N/A') ?></strong></div>
      </div>
    </div>
  </div>

  <!-- Note -->
  <p class="text-muted small mb-5">
    <i class="bi bi-info-circle me-1"></i> This payslip is a computer-generated document and contains official remuneration details for the period indicated above.
  </p>

  <!-- Signatures Footer -->
  <div class="row pt-4 align-items-end">
    <div class="col-6">
      <div class="sign-box text-center">
        <p class="small fw-semibold text-muted mb-0">Employee Signature</p>
      </div>
    </div>
    <div class="col-6 text-end">
      <div class="sign-box text-center ms-auto" style="max-width: 250px;">
        <p class="small fw-bold text-dark mb-0">For <?= e($companyName) ?></p>
        <p class="small text-muted mb-0" style="font-size: 0.75rem;">Authorized Signatory / Stamp</p>
      </div>
    </div>
  </div>

</div>

</body>
</html>
