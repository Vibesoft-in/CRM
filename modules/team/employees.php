<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$err = '';

// Handle Add Employee POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_employee') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $employee_code       = trim($_POST['employee_code']);
        $full_name           = trim($_POST['full_name']);
        $phone               = trim($_POST['phone']);
        $email               = trim($_POST['email']);
        $joining_date        = $_POST['joining_date'] ?: date('Y-m-d');
        $department          = trim($_POST['department']);
        $designation         = trim($_POST['designation']);
        $employee_type       = $_POST['employee_type'] ?? 'Full Time';
        $salary_type         = $_POST['salary_type'] ?? 'Monthly';
        $base_salary         = (float)($_POST['base_salary'] ?? 0);
        $bank_name           = trim($_POST['bank_name']);
        $bank_acc_no         = trim($_POST['bank_acc_no']);
        $bank_ifsc           = trim($_POST['bank_ifsc']);
        $pan                 = trim($_POST['pan']);
        
        if (empty($employee_code) || empty($full_name) || empty($email) || empty($phone)) {
            $err = 'Employee Code, Full Name, Email, and Phone are required.';
        } else {
            // Check code uniqueness
            $chk = $pdo->prepare("SELECT id FROM employees WHERE employee_code = ? OR email = ?");
            $chk->execute([$employee_code, $email]);
            if ($chk->fetch()) {
                $err = 'Employee Code or Email already exists.';
            } else {
                // Handle Profile Photo Upload
                $profilePhotoPath = null;
                if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $photoUploadDir = __DIR__ . '/../../uploads/employee_photos/';
                    if (!is_dir($photoUploadDir)) {
                        @mkdir($photoUploadDir, 0777, true);
                    }
                    $pExt = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                    if (in_array($pExt, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'avif'])) {
                        $pFileName = 'emp_' . time() . '_' . rand(1000, 9999) . '.' . $pExt;
                        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $photoUploadDir . $pFileName)) {
                            $profilePhotoPath = 'uploads/employee_photos/' . $pFileName;
                        }
                    }
                }

                // 1. Create or link user login account
                $loginPassword = trim($_POST['login_password'] ?? '') ?: 'password123';
                $loginRole     = in_array($_POST['login_role'] ?? '', ['Employee', 'Manager']) ? $_POST['login_role'] : 'Employee';
                $passwordHash  = password_hash($loginPassword, PASSWORD_BCRYPT);

                $uChk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $uChk->execute([$email]);
                $userId = $uChk->fetchColumn();

                if (!$userId) {
                    $uIns = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, 'active')");
                    $uIns->execute([$full_name, $email, $passwordHash, $loginRole]);
                    $userId = $pdo->lastInsertId();
                }

                // 2. Insert into employees with user_id linkage
                $ins = $pdo->prepare("INSERT INTO employees (user_id, employee_code, full_name, phone, email, joining_date, department, designation, employee_type, salary_type, base_salary, bank_name, bank_acc_no, bank_ifsc, pan, profile_photo, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                $ins->execute([$userId, $employee_code, $full_name, $phone, $email, $joining_date, $department, $designation, $employee_type, $salary_type, $base_salary, $bank_name, $bank_acc_no, $bank_ifsc, $pan, $profilePhotoPath]);
                
                set_flash("Employee added successfully! Login created with password: $loginPassword", 'success');
                header('Location: employees.php');
                exit;
            }
        }
    }
}

// Handle Edit Employee / Monthly Base Salary POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_employee') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $employee_id         = (int)$_POST['employee_id'];
        $employee_code       = trim($_POST['employee_code']);
        $full_name           = trim($_POST['full_name']);
        $phone               = trim($_POST['phone']);
        $email               = trim($_POST['email']);
        $joining_date        = $_POST['joining_date'] ?: date('Y-m-d');
        $department          = trim($_POST['department']);
        $designation         = trim($_POST['designation']);
        $employee_type       = $_POST['employee_type'] ?? 'Full Time';
        $salary_type         = $_POST['salary_type'] ?? 'Monthly';
        $base_salary         = (float)($_POST['base_salary'] ?? 0);
        $bank_name           = trim($_POST['bank_name'] ?? '');
        $bank_acc_no         = trim($_POST['bank_acc_no'] ?? '');
        $bank_ifsc           = trim($_POST['bank_ifsc'] ?? '');
        $pan                 = trim($_POST['pan'] ?? '');

        if ($employee_id <= 0 || empty($full_name)) {
            $err = 'Employee ID and Full Name are required.';
        } else {
            $upd = $pdo->prepare("UPDATE employees SET 
                employee_code = ?, full_name = ?, phone = ?, email = ?, joining_date = ?, 
                department = ?, designation = ?, employee_type = ?, salary_type = ?, 
                base_salary = ?, bank_name = ?, bank_acc_no = ?, bank_ifsc = ?, pan = ? 
                WHERE id = ?");
            $upd->execute([
                $employee_code, $full_name, $phone, $email, $joining_date,
                $department, $designation, $employee_type, $salary_type,
                $base_salary, $bank_name, $bank_acc_no, $bank_ifsc, $pan, $employee_id
            ]);

            // Handle Profile Photo Upload
            if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $photoUploadDir = __DIR__ . '/../../uploads/employee_photos/';
                if (!is_dir($photoUploadDir)) {
                    @mkdir($photoUploadDir, 0777, true);
                }
                $pExt = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($pExt, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'avif'])) {
                    $pFileName = 'emp_' . $employee_id . '_' . time() . '.' . $pExt;
                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $photoUploadDir . $pFileName)) {
                        $photoDbPath = 'uploads/employee_photos/' . $pFileName;
                        $oldP = $pdo->query("SELECT profile_photo FROM employees WHERE id = " . intval($employee_id))->fetchColumn();
                        if (!empty($oldP) && $oldP !== $photoDbPath && file_exists(__DIR__ . '/../../' . $oldP)) {
                            @unlink(__DIR__ . '/../../' . $oldP);
                        }
                        $pdo->prepare("UPDATE employees SET profile_photo = ? WHERE id = ?")->execute([$photoDbPath, $employee_id]);
                    }
                }
            }

            set_flash('Employee details and profile photo updated successfully!', 'success');
            header('Location: employees.php');
            exit;
        }
    }
}

// Handle Password Reset / Regeneration POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $new_password = trim($_POST['new_password'] ?? '');
        $send_email = !empty($_POST['send_email']);

        if ($employee_id <= 0 || empty($new_password)) {
            $err = 'Please select an employee and specify a valid new password.';
        } else {
            $res = reset_employee_password($pdo, $employee_id, $new_password, $send_email);
            if ($res['success']) {
                $_SESSION['last_pwd_reset'] = [
                    'name'       => $res['employee_name'],
                    'code'       => $res['employee_code'],
                    'email'      => $res['email'],
                    'password'   => $new_password,
                    'email_sent' => $res['email_sent']
                ];
                $notice = "Password for <strong>" . htmlspecialchars($res['employee_name']) . "</strong> has been reset to: <code class='fs-6 fw-bold'>" . htmlspecialchars($new_password) . "</code>";
                if ($res['email_sent']) {
                    $notice .= " (Notification email sent to " . htmlspecialchars($res['email']) . ")";
                } elseif (!empty($res['email_error'])) {
                    $notice .= " <small class='text-warning'>(Note: Email notice could not be sent: " . htmlspecialchars($res['email_error']) . ")</small>";
                }
                set_flash($notice, 'success');
                header('Location: employees.php');
                exit;
            } else {
                $err = $res['error'] ?? 'Failed to reset password.';
            }
        }
    }
}

// Fetch team members
$search = trim($_GET['q'] ?? '');
$deptFilter = $_GET['dept'] ?? '';

$where = ["1=1"];
$params = [];

if ($deptFilter) {
    $where[] = "department = ?";
    $params[] = $deptFilter;
}
if ($search !== '') {
    $where[] = "(full_name LIKE ? OR employee_code LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}

$whereSql = implode(" AND ", $where);
$stmt = $pdo->prepare("SELECT * FROM employees WHERE $whereSql ORDER BY id DESC");
$stmt->execute($params);
$employees = $stmt->fetchAll();

$departments = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != ''")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-person-vcard text-primary me-2"></i>Team Directory</h4>
      <p class="text-muted small mb-0">Manage staff members, employment terms, base salaries, documents, and skills.</p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary shadow-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
        <i class="bi bi-person-plus me-1"></i> Add Team Member
      </button>
      <a href="payroll.php" class="btn btn-outline-secondary shadow-sm">
        <i class="bi bi-bank me-1"></i> Payroll Processing
      </a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
      <?= $flash['message'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (isset($_SESSION['last_pwd_reset'])): ?>
    <?php $lpr = $_SESSION['last_pwd_reset']; unset($_SESSION['last_pwd_reset']); ?>
    <div class="card border-0 shadow-sm mb-4 bg-success bg-opacity-10 border-start border-4 border-success" style="border-radius: 12px;">
      <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <div class="fw-bold text-success fs-6"><i class="bi bi-shield-check me-1"></i> New Password Generated for <?= htmlspecialchars($lpr['name']) ?> (<?= htmlspecialchars($lpr['code']) ?>)</div>
          <div class="small text-muted">Portal Login Email: <code><?= htmlspecialchars($lpr['email']) ?></code></div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div class="input-group input-group-sm" style="width: 250px;">
            <input type="text" id="copiedPwdField" class="form-control font-monospace fw-bold bg-white text-dark" value="<?= htmlspecialchars($lpr['password']) ?>" readonly>
            <button class="btn btn-primary" type="button" onclick="copyPasswordToClipboard('copiedPwdField')"><i class="bi bi-clipboard me-1"></i> Copy</button>
          </div>
          <a href="https://api.whatsapp.com/send?text=<?= urlencode("Hello " . $lpr['name'] . ",\nYour login credentials for VibeSoft CMS have been updated.\nEmail: " . $lpr['email'] . "\nPassword: " . $lpr['password'] . "\nLogin URL: " . (defined('BASE_URL') ? BASE_URL : '') . "modules/auth/login.php") ?>" target="_blank" class="btn btn-sm btn-success text-white">
            <i class="bi bi-whatsapp me-1"></i> Share
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Search Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <form method="get" class="row g-2 align-items-center">
        <div class="col-md-6">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Search employee name, code, email..." value="<?= e($search) ?>">
          </div>
        </div>
        <div class="col-md-4">
          <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= e($d) ?>" <?= $deptFilter==$d?'selected':'' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
          <button class="btn btn-sm btn-primary w-100"><i class="bi bi-filter me-1"></i> Filter</button>
          <a href="employees.php" class="btn btn-sm btn-light border"><i class="bi bi-x-circle"></i></a>
        </div>
      </form>
    </div>
  </div>

  <!-- Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Code / Employee</th>
            <th>Department & Role</th>
            <th>Type</th>
            <th>Contact</th>
            <th>Joining Date</th>
            <th class="text-end">Base Salary</th>
            <th class="text-center pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($employees): ?>
            <?php foreach ($employees as $emp): ?>
              <tr>
                <td class="ps-3">
                  <div class="d-flex align-items-center">
                    <?= get_employee_avatar_html($emp, 40) ?>

                    <div>
                      <a href="employee_profile.php?id=<?= $emp['id'] ?>" class="fw-bold text-dark text-decoration-none d-block">
                        <?= e($emp['full_name']) ?>
                      </a>
                      <small class="text-muted font-monospace"><?= e($emp['employee_code']) ?></small>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="fw-semibold text-dark d-block"><?= e($emp['designation'] ?: 'Team Member') ?></span>
                  <small class="text-muted"><?= e($emp['department'] ?: 'General') ?></small>
                </td>
                <td>
                  <span class="badge bg-<?= $emp['employee_type']=='Full Time'?'primary':'info' ?>">
                    <?= e($emp['employee_type']) ?>
                  </span>
                </td>
                <td>
                  <small class="d-block"><i class="bi bi-telephone text-muted me-1"></i><?= e($emp['phone']) ?></small>
                  <small class="d-block text-muted"><i class="bi bi-envelope me-1"></i><?= e($emp['email']) ?></small>
                </td>
                <td><?= e($emp['joining_date']) ?></td>
                <td class="text-end fw-bold text-success">
                  ₹<?= money($emp['base_salary']) ?>/mo
                </td>
                <td class="text-center pe-3">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i> Actions
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <a href="employee_profile.php?id=<?= $emp['id'] ?>&tab=attendance_reports" class="dropdown-item">
                          <i class="bi bi-calendar2-check text-primary me-2"></i> Attendance & Reports
                        </a>
                      </li>
                      <li>
                        <a href="employee_profile.php?id=<?= $emp['id'] ?>&tab=salary_history" class="dropdown-item">
                          <i class="bi bi-graph-up-arrow text-warning me-2"></i> Salary Hike History
                        </a>
                      </li>
                      <li>
                        <a href="employee_profile.php?id=<?= $emp['id'] ?>&tab=payroll" class="dropdown-item">
                          <i class="bi bi-cash-coin text-success me-2"></i> Pay Salary & Slips
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <button type="button" class="dropdown-item edit-emp-btn" 
                                data-emp='<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                          <i class="bi bi-pencil text-secondary me-2"></i> Edit Profile
                        </button>
                      </li>
                      <li>
                        <button type="button" class="dropdown-item reset-pwd-btn" 
                                data-emp-id="<?= $emp['id'] ?>"
                                data-emp-name="<?= e($emp['full_name']) ?>"
                                data-emp-code="<?= e($emp['employee_code']) ?>"
                                data-emp-email="<?= e($emp['email']) ?>">
                          <i class="bi bi-key-fill text-info me-2"></i> Reset Password
                        </button>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form method="post" action="delete_employee.php" class="m-0" onsubmit="return confirm('Are you sure you want to delete employee <?= e(addslashes($emp['full_name'])) ?>? This action cannot be undone.');">
                          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                          <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                          <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-trash me-2"></i> Delete Employee
                          </button>
                        </form>
                      </li>
                    </ul>
                  </div>
                </td>

              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">No team members found in directory.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Add Employee -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_employee">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-primary me-2"></i>Add New Team Member</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-medium"><i class="bi bi-image text-primary me-1"></i>Employee Profile Photo / Image</label>
              <input type="file" name="profile_photo" class="form-control" accept="image/*">
              <small class="text-muted" style="font-size:0.75rem;">Upload PNG, JPG, WEBP or SVG photo for employee profile.</small>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Employee Code <span class="text-danger">*</span></label>
              <input type="text" name="employee_code" class="form-control" placeholder="e.g. EMP-101" required>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control" placeholder="Rahul Sharma" required>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" placeholder="rahul@company.com" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Phone Number <span class="text-danger">*</span></label>
              <input type="text" name="phone" class="form-control" placeholder="+91 9876543210" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Department</label>
              <input type="text" name="department" class="form-control" placeholder="Development, Marketing...">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Designation</label>
              <input type="text" name="designation" class="form-control" placeholder="Fullstack Developer">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Joining Date</label>
              <input type="date" name="joining_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Employee Type</label>
              <select name="employee_type" class="form-select">
                <option value="Full Time" selected>Full Time</option>
                <option value="Part Time">Part Time</option>
                <option value="Intern">Intern</option>
                <option value="Freelancer">Freelancer</option>
                <option value="Contract">Contract</option>
                <option value="Consultant">Consultant</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Salary Type</label>
              <select name="salary_type" class="form-select">
                <option value="Monthly" selected>Monthly</option>
                <option value="Daily">Daily</option>
                <option value="Hourly">Hourly</option>
                <option value="Project Based">Project Based</option>
                <option value="Stipend">Stipend</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Monthly Base Salary (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="base_salary" class="form-control fw-bold text-success" placeholder="30000.00" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Bank Name</label>
              <input type="text" name="bank_name" class="form-control" placeholder="HDFC Bank">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Account Number</label>
              <input type="text" name="bank_acc_no" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">IFSC Code</label>
              <input type="text" name="bank_ifsc" class="form-control" placeholder="HDFC0001234">
            </div>

            <!-- Portal Login Credentials -->
            <div class="col-12 mt-2 pt-2 border-top">
              <h6 class="fw-bold text-dark mb-1"><i class="bi bi-shield-lock text-primary me-1"></i>Portal Login Account</h6>
              <small class="text-muted d-block mb-2">Login credentials will be automatically generated for the employee.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Initial Login Password</label>
              <input type="text" name="login_password" class="form-control font-monospace" value="password123" placeholder="password123">
              <small class="text-muted" style="font-size:0.72rem;">Default: <code>password123</code>. Employee can change it anytime.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Portal Access Role</label>
              <select name="login_role" class="form-select">
                <option value="Employee" selected>Employee (Self Attendance, Report, Salary)</option>
                <option value="Manager">Manager (Can review team reports)</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Save Team Member</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Edit Employee & Monthly Base Salary -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit_employee">
        <input type="hidden" name="employee_id" id="edit_emp_id">
        
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Employee & Monthly Salary</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-medium"><i class="bi bi-image text-primary me-1"></i>Update Profile Photo / Image</label>
              <input type="file" name="profile_photo" class="form-control" accept="image/*">
              <small class="text-muted" style="font-size:0.75rem;">Upload new PNG, JPG, WEBP or SVG photo to update employee picture.</small>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Employee Code <span class="text-danger">*</span></label>
              <input type="text" name="employee_code" id="edit_emp_code" class="form-control" required>
            </div>

            <div class="col-md-8">
              <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Phone Number <span class="text-danger">*</span></label>
              <input type="text" name="phone" id="edit_phone" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Department</label>
              <input type="text" name="department" id="edit_department" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Designation</label>
              <input type="text" name="designation" id="edit_designation" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Joining Date</label>
              <input type="date" name="joining_date" id="edit_joining_date" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Employee Type</label>
              <select name="employee_type" id="edit_employee_type" class="form-select">
                <option value="Full Time">Full Time</option>
                <option value="Part Time">Part Time</option>
                <option value="Intern">Intern</option>
                <option value="Freelancer">Freelancer</option>
                <option value="Contract">Contract</option>
                <option value="Consultant">Consultant</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Salary Type</label>
              <select name="salary_type" id="edit_salary_type" class="form-select">
                <option value="Monthly">Monthly</option>
                <option value="Daily">Daily</option>
                <option value="Hourly">Hourly</option>
                <option value="Project Based">Project Based</option>
                <option value="Stipend">Stipend</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold text-success">Monthly Base Salary (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="base_salary" id="edit_base_salary" class="form-control fw-bold text-success fs-6" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Bank Name</label>
              <input type="text" name="bank_name" id="edit_bank_name" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Account Number</label>
              <input type="text" name="bank_acc_no" id="edit_bank_acc_no" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">IFSC Code</label>
              <input type="text" name="bank_ifsc" id="edit_bank_ifsc" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Update Base Salary & Profile</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Reset / Regenerate Password -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post" action="">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="employee_id" id="reset_emp_id" value="">

        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-key-fill text-warning me-2"></i>Reset Employee Password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body p-4">
          <!-- Employee Details Card -->
          <div class="bg-light p-3 rounded-3 mb-3 border">
            <div class="fw-bold text-dark fs-6" id="reset_emp_name">Employee Name</div>
            <div class="text-muted small" id="reset_emp_meta">Code · Email</div>
          </div>

          <!-- Password Field with Generator Button -->
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label class="form-label small fw-semibold mb-0">New Password <span class="text-danger">*</span></label>
              <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small fw-semibold" onclick="generateRandomPassword('reset_new_password')">
                <i class="bi bi-magic me-1 text-primary"></i>Auto-Generate
              </button>
            </div>
            <div class="input-group">
              <input type="text" name="new_password" id="reset_new_password" class="form-control font-monospace fw-bold" placeholder="Type or click Auto-Generate" required>
              <button class="btn btn-outline-secondary" type="button" onclick="generateRandomPassword('reset_new_password')" title="Generate another password">
                <i class="bi bi-arrow-repeat"></i>
              </button>
            </div>
            <div class="form-text small" style="font-size:0.72rem;">Admin can manually enter any password or click <em>Auto-Generate</em> for a secure random password.</div>
          </div>

          <!-- Send Email Checkbox -->
          <div class="form-check bg-light p-3 rounded-2 border">
            <input class="form-check-input" type="checkbox" name="send_email" value="1" id="reset_send_email" checked>
            <label class="form-check-label small fw-semibold" for="reset_send_email">
              <i class="bi bi-envelope-check text-primary me-1"></i> Send new credentials to employee via Email
            </label>
            <div class="text-muted small ms-4" style="font-size:0.72rem;">If enabled and SMTP is configured, the employee will receive an official reset notice with their new login details.</div>
          </div>

          <!-- Previous Passwords & Device Logs Accordion -->
          <div class="accordion mt-3" id="pwdHistoryAccordion">
            <div class="accordion-item border rounded-2">
              <h2 class="accordion-header" id="headingHistory">
                <button class="accordion-button collapsed py-2 px-3 small fw-semibold bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#collapseHistory" aria-expanded="false" aria-controls="collapseHistory">
                  <i class="bi bi-clock-history text-primary me-2"></i> View Previous Passwords & Device Logs (<span id="historyLogCount">0</span>)
                </button>
              </h2>
              <div id="collapseHistory" class="accordion-collapse collapse" aria-labelledby="headingHistory" data-bs-parent="#pwdHistoryAccordion">
                <div class="accordion-body p-2" style="max-height: 220px; overflow-y: auto;">
                  <div id="historyLoading" class="text-center py-2 text-muted small"><span class="spinner-border spinner-border-sm me-1"></span> Loading history...</div>
                  <div class="table-responsive" id="historyTableContainer" style="display: none;">
                    <table class="table table-sm table-striped align-middle mb-0" style="font-size: 0.74rem;">
                      <thead class="table-light">
                        <tr>
                          <th>Date & Time</th>
                          <th>Old Password</th>
                          <th>Device & Browser</th>
                          <th>Changed By</th>
                        </tr>
                      </thead>
                      <tbody id="historyTableBody"></tbody>
                    </table>
                  </div>
                  <div id="historyEmpty" class="text-center py-2 text-muted small" style="display: none;">No previous password logs found for this employee.</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer border-top-0 pt-0 d-flex justify-content-between">
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm fw-semibold px-3 shadow-sm">
            <i class="bi bi-shield-lock-fill me-1"></i> Update Password
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.edit-emp-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const emp = JSON.parse(this.getAttribute('data-emp'));
    document.getElementById('edit_emp_id').value = emp.id;
    document.getElementById('edit_emp_code').value = emp.employee_code || '';
    document.getElementById('edit_full_name').value = emp.full_name || '';
    document.getElementById('edit_email').value = emp.email || '';
    document.getElementById('edit_phone').value = emp.phone || '';
    document.getElementById('edit_department').value = emp.department || '';
    document.getElementById('edit_designation').value = emp.designation || '';
    document.getElementById('edit_joining_date').value = emp.joining_date || '';
    document.getElementById('edit_employee_type').value = emp.employee_type || 'Full Time';
    document.getElementById('edit_salary_type').value = emp.salary_type || 'Monthly';
    document.getElementById('edit_base_salary').value = emp.base_salary || 0;
    document.getElementById('edit_bank_name').value = emp.bank_name || '';
    document.getElementById('edit_bank_acc_no').value = emp.bank_acc_no || '';
    document.getElementById('edit_bank_ifsc').value = emp.bank_ifsc || '';

    const editModal = new bootstrap.Modal(document.getElementById('editEmployeeModal'));
    editModal.show();
  });
});

// Reset Password Modal Trigger
document.querySelectorAll('.reset-pwd-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const empId = this.getAttribute('data-emp-id');
    const empName = this.getAttribute('data-emp-name');
    const empCode = this.getAttribute('data-emp-code');
    const empEmail = this.getAttribute('data-emp-email');

    document.getElementById('reset_emp_id').value = empId;
    document.getElementById('reset_emp_name').textContent = empName;
    document.getElementById('reset_emp_meta').textContent = (empCode ? empCode + ' · ' : '') + (empEmail || 'No email provided');
    
    // Auto generate an initial strong password
    generateRandomPassword('reset_new_password');

    // Fetch previous password logs and devices
    loadPasswordHistory(empId);

    const resetModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
    resetModal.show();
  });
});

function loadPasswordHistory(empId) {
  const loading = document.getElementById('historyLoading');
  const tableContainer = document.getElementById('historyTableContainer');
  const tbody = document.getElementById('historyTableBody');
  const empty = document.getElementById('historyEmpty');
  const countSpan = document.getElementById('historyLogCount');

  loading.style.display = 'block';
  tableContainer.style.display = 'none';
  empty.style.display = 'none';
  tbody.innerHTML = '';
  countSpan.textContent = '...';

  fetch('password_logs_api.php?employee_id=' + empId)
    .then(r => r.json())
    .then(res => {
      loading.style.display = 'none';
      if (res.success && res.logs && res.logs.length > 0) {
        countSpan.textContent = res.logs.length;
        res.logs.forEach(log => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td><span class="text-muted">${escapeHtml(log.date_formatted)}</span></td>
            <td><code class="fw-bold text-dark">${escapeHtml(log.password_plain)}</code></td>
            <td><span class="badge bg-light text-dark border"><i class="bi bi-laptop me-1"></i>${escapeHtml(log.device_name)}</span></td>
            <td><small class="text-muted">${escapeHtml(log.changed_by_name)}</small></td>
          `;
          tbody.appendChild(tr);
        });
        tableContainer.style.display = 'block';
      } else {
        countSpan.textContent = '0';
        empty.style.display = 'block';
      }
    })
    .catch(() => {
      loading.style.display = 'none';
      empty.textContent = 'Failed to load history';
      empty.style.display = 'block';
    });
}

function escapeHtml(str) {
  if (!str) return '';
  const div = document.createElement('div');
  div.innerText = str;
  return div.innerHTML;
}

function generateRandomPassword(elementId) {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$';
  let pwd = '';
  // e.g. Emp@7k9X!2
  const prefixes = ['Emp', 'Pass', 'Vibe', 'User', 'Work'];
  const pfx = prefixes[Math.floor(Math.random() * prefixes.length)];
  const num = Math.floor(1000 + Math.random() * 9000);
  const special = ['@', '#', '$', '!'][Math.floor(Math.random() * 4)];
  pwd = pfx + special + num;
  document.getElementById(elementId).value = pwd;
}

function copyPasswordToClipboard(elementId) {
  const el = document.getElementById(elementId);
  if (!el) return;
  el.select();
  el.setSelectionRange(0, 99999);
  navigator.clipboard.writeText(el.value).then(() => {
    alert('Password copied to clipboard: ' + el.value);
  }).catch(() => {
    document.execCommand('copy');
    alert('Password copied: ' + el.value);
  });
}
</script>

<?php include '../../includes/footer.php'; ?>
