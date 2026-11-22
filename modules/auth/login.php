<?php
require_once __DIR__ . '/../../includes/functions.php';

if (is_logged_in()) {
    if (is_employee()) {
        header('Location: ' . BASE_URL . 'employee/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . 'index.php');
    }
    exit;
}

$err = '';
$showOtpModal = false;

// Handle AJAX Verification requests
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // AJAX OTP Verification
    if ($action === 'verify_admin_pin') {
        header('Content-Type: application/json');
        if (!verify_csrf($_POST['csrf'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Security session mismatch. Please reload page.']);
            exit;
        }

        $userId = (int)($_SESSION['2fa_pending_user_id'] ?? 0);
        if (!$userId) {
            echo json_encode(['success' => false, 'error' => 'Login session expired. Please enter credentials again.']);
            exit;
        }

        $pin = trim($_POST['pin'] ?? '');
        if ($pin === '935989') {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if ($user && strtolower($user['status'] ?? 'active') === 'active') {
                unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time']);
                login_user($user);
                echo json_encode(['success' => true, 'redirect' => BASE_URL . 'index.php']);
                exit;
            } else {
                echo json_encode(['success' => false, 'error' => 'Account is deactivated.']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid OTP. Please enter the valid OTP.']);
            exit;
        }
    }

    // Standard POST / AJAX Credential Check
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token. Please refresh and try again.";
    } else {
        $loginInput = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';

        // Rate Limiter Check
        if (\App\Security\RateLimiter::isLocked($loginInput)) {
            $remSec = \App\Security\RateLimiter::getRemainingLockoutSeconds($loginInput);
            $remMin = ceil($remSec / 60);
            $err = "Too many failed login attempts. Account temporarily locked for $remMin minute(s).";
        } else {
            // Search user by email, name, linked employee_code, phone or employee email
            $stmt = $pdo->prepare("
                SELECT u.* 
                FROM users u
                LEFT JOIN employees e ON (e.user_id = u.id OR e.id = u.employee_id)
                WHERE u.email = ? OR u.name = ? OR e.employee_code = ? OR e.phone = ? OR e.email = ?
                LIMIT 1
            ");
            $stmt->execute([$loginInput, $loginInput, $loginInput, $loginInput, $loginInput]);
            $user = $stmt->fetch();

            if ($user && password_verify($pass, $user['password_hash'])) {
                // Verify account is active
                if (strtolower($user['status'] ?? 'active') !== 'active') {
                    \App\Security\AuditLogger::securityEvent('SECURITY_LOGIN_INACTIVE_USER', [
                        'user_id' => $user['id'],
                        'status' => $user['status']
                    ]);
                    $err = "Your account is deactivated. Please contact administration.";
                } else {
                    \App\Security\RateLimiter::clear($loginInput);

                    // If employee role, direct login to Employee Portal
                    if (strtolower($user['role'] ?? '') === 'employee') {
                        login_user($user);
                        if (!empty($_POST['is_ajax'])) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'redirect' => BASE_URL . 'employee/dashboard.php']);
                            exit;
                        }
                        set_flash("Welcome back, " . e($user['name']) . "!", "success");
                        header('Location: ' . BASE_URL . 'employee/dashboard.php');
                        exit;
                    }

                    // For Admin / Manager: Require Fake OTP Popup with fixed PIN 935989
                    $_SESSION['2fa_pending_user_id'] = (int)$user['id'];
                    $_SESSION['2fa_pending_time']    = time();

                    if (!empty($_POST['is_ajax'])) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true, 
                            'require_otp' => true, 
                            'message' => 'OTP has been sent to your registered mobile and email.'
                        ]);
                        exit;
                    }

                    $showOtpModal = true;
                }
            } else {
                $attempts = \App\Security\RateLimiter::hit($loginInput);
                \App\Security\AuditLogger::securityEvent('SECURITY_LOGIN_FAILED', [
                    'identifier' => $loginInput,
                    'attempts' => $attempts
                ]);
                $remaining = max(0, 5 - $attempts);
                if ($remaining > 0) {
                    $err = "Invalid credentials. ($remaining attempts remaining)";
                } else {
                    $err = "Too many failed attempts. Account temporarily locked for 15 minutes.";
                }

                if (!empty($_POST['is_ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => $err]);
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login — VibeSoft CMS</title>
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/images/logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #f8fafc;
      min-height: 100vh;
    }
    .login-card {
      border-radius: 20px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    }
    .otp-input {
      letter-spacing: 8px;
      font-size: 1.5rem;
      text-align: center;
      font-weight: 700;
    }
  </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">

<div class="col-12 col-sm-9 col-md-6 col-lg-4" style="max-width: 420px;">
  <div class="card login-card border-0 bg-white">
    <div class="card-body p-4 p-sm-5">
      <div class="text-center mb-4">
        <img src="<?= get_company_logo() ?>" alt="VibeSoft" height="46" class="mb-3" style="max-width: 180px; object-fit: contain;">
        <h4 class="fw-bold text-dark mb-1">VibeSoft CMS</h4>
        <p class="text-muted small mb-0">Centralized Business & Employee Management Portal</p>
      </div>

      <div id="loginAlertBox">
        <?php if ($err): ?>
          <div class="alert alert-danger py-2 small rounded-3"><?= e($err) ?></div>
        <?php elseif (!empty($_GET['expired'])): ?>
          <div class="alert alert-warning py-2 small rounded-3 d-flex align-items-center gap-2">
            <i class="bi bi-clock-history fs-6"></i>
            <span>Your session has expired. Please login again.</span>
          </div>
        <?php endif; ?>
      </div>

      <form id="loginForm" method="post">
        <input type="hidden" name="csrf" id="csrfToken" value="<?= csrf_token() ?>">
        <input type="hidden" name="is_ajax" value="1">

        <div class="mb-3">
          <label class="form-label small fw-semibold text-secondary">Email or Employee Code</label>
          <input name="email" id="loginEmail" type="text" class="form-control py-2" placeholder="e.g. contact.vibesoft@gmail.com or EMP101" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="mb-4">
          <label class="form-label small fw-semibold text-secondary">Password</label>
          <input name="password" id="loginPass" type="password" class="form-control py-2" placeholder="Enter your password" required>
        </div>

        <button type="submit" id="loginSubmitBtn" class="btn btn-primary w-100 fw-semibold py-2 shadow-sm d-flex align-items-center justify-content-center gap-2" style="border-radius: 10px;">
          <span>Sign In</span>
          <span id="loginSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
        </button>
      </form>

      <div class="mt-4 text-center text-muted" style="font-size:0.75rem;">
        <div>VibeSoft CMS · ERP & HR System</div>
        <div class="text-secondary fw-bold mt-1" style="font-size:0.75rem; letter-spacing:0.5px;">
          <?= htmlspecialchars(APP_VERSION) ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Fake OTP Modal Popup for Admin Login -->
<div class="modal fade" id="otpModal" tabindex="-1" aria-labelledby="otpModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
      <div class="modal-header border-0 pb-0 pt-4 px-4 justify-content-center">
        <div class="text-center">
          <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center mb-2" style="width: 56px; height: 56px;">
            <i class="bi bi-shield-lock-fill fs-3"></i>
          </div>
          <h5 class="modal-title fw-bold text-dark" id="otpModalLabel">Two-Step Verification</h5>
        </div>
      </div>
      <div class="modal-body p-4 pt-2">
        <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success-emphasis py-2 px-3 small rounded-3 mb-3 text-center">
          <i class="bi bi-check-circle-fill me-1"></i>
          <strong>OTP Sent!</strong> Verification code has been dispatched to your registered mobile and email.
        </div>

        <div id="otpAlertBox"></div>

        <form id="otpForm">
          <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary d-block text-center mb-2">Enter 6-Digit OTP</label>
            <input type="text" id="otpPinInput" class="form-control otp-input py-2" maxlength="6" placeholder="••••••" autocomplete="one-time-code" required>
            <small class="text-muted d-block text-center mt-1" style="font-size:0.75rem;">Enter the verification code to complete login</small>
          </div>

          <button type="submit" id="otpSubmitBtn" class="btn btn-primary w-100 fw-semibold py-2 shadow-sm d-flex align-items-center justify-content-center gap-2 mb-2" style="border-radius: 10px;">
            <span>Verify & Proceed</span>
            <span id="otpSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
          </button>

          <button type="button" id="cancelOtpBtn" class="btn btn-link w-100 text-decoration-none text-muted small py-1" data-bs-dismiss="modal">
            Back to Sign In
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const loginForm = document.getElementById('loginForm');
  const loginBtn = document.getElementById('loginSubmitBtn');
  const loginSpinner = document.getElementById('loginSpinner');
  const loginAlert = document.getElementById('loginAlertBox');

  const otpModalEl = document.getElementById('otpModal');
  const otpModal = new bootstrap.Modal(otpModalEl);
  const otpForm = document.getElementById('otpForm');
  const otpInput = document.getElementById('otpPinInput');
  const otpBtn = document.getElementById('otpSubmitBtn');
  const otpSpinner = document.getElementById('otpSpinner');
  const otpAlert = document.getElementById('otpAlertBox');

  // Handle Login Form Submit
  loginForm.addEventListener('submit', function(e) {
    e.preventDefault();
    loginAlert.innerHTML = '';
    loginBtn.disabled = true;
    loginSpinner.classList.remove('d-none');

    const formData = new FormData(loginForm);

    fetch('login.php', {
      method: 'POST',
      body: formData
    })
    .then(r => r.json())
    .then(data => {
      loginBtn.disabled = false;
      loginSpinner.classList.add('d-none');

      if (data.require_otp) {
        // Admin password valid -> Show OTP Modal Popup!
        otpAlert.innerHTML = '';
        otpInput.value = '';
        otpModal.show();
        setTimeout(() => otpInput.focus(), 400);
      } else if (data.success && data.redirect) {
        // Employee direct login
        window.location.href = data.redirect;
      } else {
        // Error
        loginAlert.innerHTML = `<div class="alert alert-danger py-2 small rounded-3">${data.error || 'Invalid login credentials.'}</div>`;
      }
    })
    .catch(() => {
      loginBtn.disabled = false;
      loginSpinner.classList.add('d-none');
      loginAlert.innerHTML = `<div class="alert alert-danger py-2 small rounded-3">Connection error. Please try again.</div>`;
    });
  });

  // Handle OTP Form Submit
  otpForm.addEventListener('submit', function(e) {
    e.preventDefault();
    otpAlert.innerHTML = '';
    const pin = otpInput.value.trim();

    if (!pin) {
      otpAlert.innerHTML = '<div class="alert alert-danger py-2 small rounded-3">Please enter the 6-digit OTP.</div>';
      return;
    }

    otpBtn.disabled = true;
    otpSpinner.classList.remove('d-none');

    const params = new URLSearchParams();
    params.append('action', 'verify_admin_pin');
    params.append('pin', pin);
    params.append('csrf', document.getElementById('csrfToken').value);

    fetch('login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
      otpBtn.disabled = false;
      otpSpinner.classList.add('d-none');

      if (data.success && data.redirect) {
        otpAlert.innerHTML = '<div class="alert alert-success py-2 small rounded-3">OTP verified! Redirecting...</div>';
        setTimeout(() => {
          window.location.href = data.redirect;
        }, 500);
      } else {
        otpAlert.innerHTML = `<div class="alert alert-danger py-2 small rounded-3">${data.error || 'Invalid OTP. Please enter the valid OTP.'}</div>`;
        otpInput.focus();
        otpInput.select();
      }
    })
    .catch(() => {
      otpBtn.disabled = false;
      otpSpinner.classList.add('d-none');
      otpAlert.innerHTML = '<div class="alert alert-danger py-2 small rounded-3">Verification failed. Please try again.</div>';
    });
  });

  // Auto-submit when 6-digit PIN is filled
  function checkAndAutoSubmitOtp() {
    // Keep only numeric characters and limit to 6 digits
    const cleaned = otpInput.value.replace(/\D/g, '').slice(0, 6);
    if (otpInput.value !== cleaned) {
      otpInput.value = cleaned;
    }
    if (cleaned.length === 6 && !otpBtn.disabled) {
      // Trigger submission automatically
      if (typeof otpForm.requestSubmit === 'function') {
        otpForm.requestSubmit();
      } else {
        otpBtn.click();
      }
    }
  }

  otpInput.addEventListener('input', checkAndAutoSubmitOtp);
  otpInput.addEventListener('paste', function() {
    setTimeout(checkAndAutoSubmitOtp, 50);
  });

  <?php if ($showOtpModal): ?>
    otpModal.show();
    setTimeout(() => otpInput.focus(), 400);
  <?php endif; ?>
});
</script>
</body>
</html>
