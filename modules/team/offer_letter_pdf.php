<?php
require_once '../../includes/functions.php';
require_login();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid Employee ID");

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) die("Employee record not found");

// Authorization: Admin, Manager, or owning Employee only
$isOwner = (is_employee() && get_logged_employee_id() === $id);
if (!can_access_admin_panel() && !$isOwner) {
    abort_forbidden("Access Denied: You are not authorized to view this offer letter.");
}

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
$companySignature = $settings['signature'] ?? '';
$companySigBase64 = '';
if (!empty($companySignature)) {
    $cleanSig = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $companySignature), DIRECTORY_SEPARATOR);
    $fullSigPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $cleanSig;
    if (file_exists($fullSigPath) && is_file($fullSigPath)) {
        $sigMime = mime_content_type($fullSigPath) ?: 'image/png';
        $companySigBase64 = 'data:' . $sigMime . ';base64,' . base64_encode(file_get_contents($fullSigPath));
    }
}

$monthlySalary = (float)$emp['base_salary'];
$annualCTC     = $monthlySalary * 12;
$joiningDate   = !empty($emp['joining_date']) ? date('F d, Y', strtotime($emp['joining_date'])) : date('F d, Y');
$issueDate     = date('F d, Y', strtotime($emp['created_at'] ?? 'now'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Offer Letter - <?= e($emp['full_name']) ?></title>

  <!-- Google Fonts & Bootstrap 5 -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    body {
      background: #f1f5f9;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      color: #1e293b;
      line-height: 1.6;
    }

    .letter-card {
      max-width: 850px;
      margin: 30px auto;
      background: #ffffff;
      padding: 50px;
      border-radius: 16px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.06);
      border: 1px solid #e2e8f0;
      position: relative;
    }

    .company-logo {
      max-height: 60px;
      max-width: 220px;
      object-fit: contain;
    }

    .letter-title {
      font-size: 1.5rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: #0f172a;
      border-bottom: 3px solid #0d6efd;
      display: inline-block;
      padding-bottom: 4px;
    }

    .salary-table th {
      background: #f8fafc;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .sign-space {
      border-top: 1px dashed #cbd5e1;
      margin-top: 50px;
      padding-top: 10px;
    }

    @media print {
      body { background: #ffffff !important; }
      .letter-card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; margin: 0 auto !important; padding: 35px !important; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>

<!-- Top Action Bar -->
<div class="container no-print text-center my-4" style="max-width: 850px;">
  <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded-3 shadow-sm border">
    <a href="employee_profile.php?id=<?= $emp['id'] ?>&tab=documents" class="btn btn-outline-secondary btn-sm fw-semibold">
      <i class="bi bi-arrow-left me-1"></i> Back to Profile
    </a>
    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-file-earmark-text text-primary me-2"></i>Official Employment Offer Letter</h6>
    <button onclick="window.print()" class="btn btn-primary btn-sm fw-semibold shadow-sm">
      <i class="bi bi-printer me-1"></i> Print / Save PDF
    </button>
  </div>
</div>

<!-- Main Letter Document -->
<div class="letter-card">
  
  <!-- Header: Company Brand -->
  <div class="d-flex justify-content-between align-items-start border-bottom pb-4 mb-4">
    <div>
      <?php if (!empty($companyLogo)): ?>
        <img src="<?= BASE_URL . e($companyLogo) ?>" alt="Logo" class="company-logo mb-2">
      <?php else: ?>
        <h4 class="fw-extrabold text-primary mb-1" style="letter-spacing: -0.02em;"><?= e($companyName) ?></h4>
      <?php endif; ?>

      <h5 class="fw-bold text-dark mb-1"><?= e($companyName) ?></h5>
      <p class="text-muted small mb-1" style="max-width: 450px; line-height: 1.4;"><?= nl2br(e($companyAddress)) ?></p>
      <div class="small text-muted">
        <?php if ($companyGstin): ?><span><strong>GSTIN:</strong> <?= e($companyGstin) ?> &bull; </span><?php endif; ?>
        <?php if ($companyEmail): ?><span><strong>Email:</strong> <?= e($companyEmail) ?></span><?php endif; ?>
      </div>
    </div>

    <div class="text-end">
      <div class="text-muted small">Date: <strong><?= $issueDate ?></strong></div>
      <div class="text-muted small">Ref: <strong class="font-monospace">OL/<?= e($emp['employee_code']) ?>/<?= date('Y') ?></strong></div>
    </div>
  </div>

  <div class="text-center mb-4">
    <h4 class="letter-title">LETTER OF OFFER</h4>
  </div>

  <!-- Candidate Details -->
  <div class="mb-4">
    <p class="mb-1 fw-bold text-dark fs-6">To,</p>
    <h5 class="fw-bold text-primary mb-1"><?= e($emp['full_name']) ?></h5>
    <p class="text-muted mb-0 small">Email: <?= e($emp['email']) ?> | Phone: <?= e($emp['phone']) ?></p>
    <?php if (!empty($emp['address'])): ?>
      <p class="text-muted mb-0 small">Address: <?= e($emp['address']) ?></p>
    <?php endif; ?>
  </div>

  <!-- Body Content -->
  <div class="letter-body mb-4">
    <p>Dear <strong><?= e($emp['full_name']) ?></strong>,</p>
    <p>
      Following our recent discussions, we are pleased to offer you the position of <strong><?= e($emp['designation'] ?: 'Team Member') ?></strong> 
      in the <strong><?= e($emp['department'] ?: 'General') ?></strong> department at <strong><?= e($companyName) ?></strong>.
    </p>
    <p>
      Your official joining date will be <strong><?= $joiningDate ?></strong>. You will be reporting to the Department Manager at our office location.
    </p>
  </div>

  <!-- Compensation Breakdown Card -->
  <div class="card border-0 bg-light mb-4" style="border-radius: 12px;">
    <div class="card-header bg-white border-bottom fw-bold text-dark py-2">
      <i class="bi bi-currency-rupee text-success me-1"></i> Remuneration & Compensation Package
    </div>
    <div class="card-body p-0">
      <table class="table table-bordered mb-0 salary-table align-middle">
        <thead>
          <tr>
            <th>Component</th>
            <th>Monthly Amount (₹)</th>
            <th>Annual CTC (₹)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="fw-medium">Monthly Gross Remuneration</td>
            <td class="fw-bold text-dark">₹<?= money($monthlySalary) ?></td>
            <td class="fw-bold text-dark">₹<?= money($annualCTC) ?></td>
          </tr>
          <tr class="table-light fw-bold">
            <td class="text-primary">Total Fixed Cost to Company (CTC)</td>
            <td class="text-success fs-6">₹<?= money($monthlySalary) ?> / month</td>
            <td class="text-success fs-6">₹<?= money($annualCTC) ?> / year</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Terms and Conditions -->
  <div class="mb-4">
    <h6 class="fw-bold text-dark mb-2">Terms & Conditions of Employment:</h6>
    <ol class="small text-muted ps-3 mb-0" style="line-height: 1.7;">
      <li><strong>Probation Period:</strong> Your employment is subject to a standard probation period of 3 months from your joining date.</li>
      <li><strong>Confidentiality:</strong> You will maintain strict confidentiality regarding company data, customer databases, code repositories, and trade secrets.</li>
      <li><strong>Code of Conduct:</strong> You agree to abide by all organizational policies, ethical standards, and professional work hours.</li>
      <li><strong>Notice Period:</strong> Should you wish to resign, a minimum notice period of 30 days or salary in lieu thereof will be required.</li>
    </ol>
  </div>

  <p class="small text-muted mb-5">
    We look forward to welcoming you to our team and building a successful future together. Please sign and return a duplicate copy of this letter as confirmation of your acceptance.
  </p>

  <!-- Signatures -->
  <div class="row pt-4 align-items-end">
    <div class="col-6">
      <div class="sign-space text-center me-auto" style="max-width: 250px;">
        <p class="small fw-bold text-dark mb-0"><?= e($emp['full_name']) ?></p>
        <p class="small text-muted mb-0" style="font-size: 0.75rem;">Candidate Acceptance Signature & Date</p>
      </div>
    </div>
    <div class="col-6 text-end">
      <div class="sign-space text-center ms-auto" style="max-width: 250px;">
        <?php if (!empty($companySigBase64)): ?>
          <div style="height: 48px; margin-bottom: 6px; text-align: center;">
            <img src="<?= $companySigBase64 ?>" style="max-height: 46px; max-width: 160px; object-fit: contain;" alt="Authorized Signature">
          </div>
        <?php endif; ?>
        <p class="small fw-bold text-dark mb-0">For <?= e($companyName) ?></p>
        <p class="small text-muted mb-0" style="font-size: 0.75rem;">Authorized Signatory / HR Stamp</p>
      </div>
    </div>
  </div>

</div>

</body>
</html>
