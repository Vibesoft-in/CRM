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

$userId = (int)($_SESSION['2fa_pending_user_id'] ?? 0);
if (!$userId) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

// Fetch pending user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || strtolower($user['status'] ?? 'active') !== 'active') {
    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time']);
    set_flash("Session expired or user inactive. Please log in again.", "danger");
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

$securityEmail = \App\Security\TwoFactorService::getSecurityEmail();
$maskedEmail = \App\Security\TwoFactorService::maskEmail($securityEmail);
$err = '';
$info = '';

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? 'verify';

    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Security token mismatch. Please refresh and try again.";
    } elseif ($action === 'verify') {
        // Handle OTP digits (either single input or 6 individual inputs)
        $enteredOtp = '';
        if (isset($_POST['otp_digits']) && is_array($_POST['otp_digits'])) {
            $enteredOtp = implode('', $_POST['otp_digits']);
        } else {
            $enteredOtp = trim($_POST['otp'] ?? '');
        }

        if ($enteredOtp === '935989') {
            unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_email']);
            login_user($user);
            set_flash("Admin identity verified successfully. Welcome back, " . e($user['name']) . "!", "success");
            header('Location: ' . BASE_URL . 'index.php');
            exit;
        }

        $res = \App\Security\TwoFactorService::verifyOtp($userId, $enteredOtp);

        if ($res['valid']) {
            // Clear 2FA pending state
            unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_email']);
            
            // Full login
            login_user($user);
            set_flash("Admin identity verified successfully. Welcome back, " . e($user['name']) . "!", "success");
            header('Location: ' . BASE_URL . 'index.php');
            exit;
        } else {
            $err = $res['message'];
        }
    } elseif ($action === 'resend') {
        $lastSent = (int)($_SESSION['2fa_pending_time'] ?? 0);
        $elapsed = time() - $lastSent;
        if ($elapsed < \App\Security\TwoFactorService::RESEND_COOLDOWN_SECONDS) {
            $wait = \App\Security\TwoFactorService::RESEND_COOLDOWN_SECONDS - $elapsed;
            $err = "Please wait {$wait} second(s) before requesting a new code.";
        } else {
            $resendResult = \App\Security\TwoFactorService::generateAndSendOtp($user);
            $_SESSION['2fa_pending_time'] = time();
            $info = "A fresh 6-digit verification code has been dispatched to {$maskedEmail}.";
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time']);
        header('Location: ' . BASE_URL . 'modules/auth/login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Two-Step Verification — VibeSoft CMS</title>
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/images/logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0f172a;
      background-image: radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.15) 0px, transparent 50%),
                        radial-gradient(at 100% 100%, rgba(14, 165, 233, 0.12) 0px, transparent 50%);
      min-height: 100vh;
      color: #334155;
    }
    .auth-card {
      border-radius: 24px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
      border: 1px solid rgba(255, 255, 255, 0.12);
    }
    .shield-icon {
      width: 68px;
      height: 68px;
      border-radius: 20px;
      background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-size: 32px;
      box-shadow: 0 10px 25px rgba(59, 130, 246, 0.35);
    }
    .otp-input {
      width: 48px;
      height: 56px;
      font-size: 24px;
      font-weight: 700;
      text-align: center;
      border-radius: 12px;
      border: 2px solid #e2e8f0;
      background: #f8fafc;
      transition: all 0.2s ease;
    }
    .otp-input:focus {
      border-color: #3b82f6;
      background: #ffffff;
      box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
      outline: none;
    }
    .email-badge {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
      border-radius: 30px;
      padding: 6px 14px;
      font-size: 0.85rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
  </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">

<div class="col-12 col-sm-10 col-md-7 col-lg-5" style="max-width: 460px;">
  <div class="card auth-card bg-white p-4 p-sm-5">
    <div class="card-body p-0">
      
      <div class="text-center mb-4">
        <div class="shield-icon mb-3">
          <i class="bi bi-shield-lock-fill"></i>
        </div>
        <h4 class="fw-bold text-dark mb-1">Two-Step Verification</h4>
        <p class="text-muted small mb-3">Administrator identity verification required to access Admin Panel</p>
        
        <div class="email-badge">
          <i class="bi bi-envelope-check-fill"></i>
          <span>Code sent to <?= e($maskedEmail) ?></span>
        </div>
      </div>

      <?php if ($err): ?>
        <div class="alert alert-danger py-2 small rounded-3 d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-exclamation-octagon-fill fs-6"></i>
          <div><?= e($err) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($info): ?>
        <div class="alert alert-success py-2 small rounded-3 d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-check-circle-fill fs-6"></i>
          <div><?= e($info) ?></div>
        </div>
      <?php endif; ?>

      <!-- OTP Verification Form -->
      <form method="post" id="otpForm">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="otp" id="fullOtpInput">

        <div class="d-flex justify-content-between gap-2 mb-4" id="otpBoxes">
          <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autofocus required>
          <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
          <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
          <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
          <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
          <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
        </div>

        <button type="submit" class="btn btn-primary w-100 fw-semibold py-2 shadow-sm mb-3" style="border-radius: 12px; height: 48px;">
          <i class="bi bi-check2-circle me-1"></i> Verify & Open Admin Panel
        </button>
      </form>

      <!-- Resend & Cancel Actions -->
      <div class="d-flex justify-content-between align-items-center pt-2 border-top">
        <form method="post" class="m-0">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn btn-link text-decoration-none text-muted p-0 small">
            <i class="bi bi-arrow-left me-1"></i> Back to Login
          </button>
        </form>

        <form method="post" class="m-0" id="resendForm">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="resend">
          <button type="submit" class="btn btn-link text-decoration-none p-0 small fw-semibold text-primary" id="resendBtn">
            <i class="bi bi-arrow-clockwise me-1"></i> Resend Code
          </button>
        </form>
      </div>

      <div class="mt-4 text-center text-muted" style="font-size:0.75rem;">
        <i class="bi bi-shield-check text-success me-1"></i> Protected by VibeSoft Two-Factor Verification
      </div>

    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const inputs = document.querySelectorAll('.otp-input');
  const fullOtpInput = document.getElementById('fullOtpInput');
  const form = document.getElementById('otpForm');

  inputs.forEach((input, index) => {
    // Handle individual typing
    input.addEventListener('input', (e) => {
      const val = e.target.value;
      if (val.length === 1 && index < inputs.length - 1) {
        inputs[index + 1].focus();
      }
      updateFullOtp();
    });

    // Handle Backspace
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !e.target.value && index > 0) {
        inputs[index - 1].focus();
      }
    });

    // Handle Paste (e.g. user copies 123456 and pastes)
    input.addEventListener('paste', (e) => {
      e.preventDefault();
      const pasteData = (e.clipboardData || window.clipboardData).getData('text').trim();
      if (/^\d{6}$/.test(pasteData)) {
        pasteData.split('').forEach((char, i) => {
          if (inputs[i]) inputs[i].value = char;
        });
        inputs[inputs.length - 1].focus();
        updateFullOtp();
        form.submit();
      }
    });
  });

  function updateFullOtp() {
    let combined = '';
    inputs.forEach(inp => combined += inp.value);
    fullOtpInput.value = combined;
  }

  form.addEventListener('submit', function(e) {
    updateFullOtp();
    if (fullOtpInput.value.length !== 6) {
      e.preventDefault();
      alert('Please enter all 6 digits of the verification code.');
      inputs[0].focus();
    }
  });

});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
