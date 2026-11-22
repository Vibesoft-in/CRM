<?php
// employee/profile_handler.php — Unified profile and documents update processor for Employee Portal

function calculate_profile_completion(array $emp): array {
    $fields = [
        'profile_photo' => ['weight' => 15, 'label' => 'Profile Photo', 'icon' => 'bi-person-circle'],
        'phone'         => ['weight' => 15, 'label' => 'Phone Number', 'icon' => 'bi-telephone'],
        'email'         => ['weight' => 15, 'label' => 'Email Address', 'icon' => 'bi-envelope'],
        'address'       => ['weight' => 10, 'label' => 'Residential Address', 'icon' => 'bi-geo-alt'],
        'bank_acc_no'   => ['weight' => 15, 'label' => 'Bank Account Details', 'icon' => 'bi-bank'],
        'resume_file'   => ['weight' => 15, 'label' => 'Resume / CV', 'icon' => 'bi-file-earmark-person'],
        'aadhaar_file'  => ['weight' => 15, 'label' => 'Govtment ID (Aadhaar / PAN)', 'icon' => 'bi-card-heading']
    ];

    $score = 0;
    $missing = [];

    foreach ($fields as $col => $info) {
        if (!empty($emp[$col])) {
            $score += $info['weight'];
        } else {
            $missing[] = $info;
        }
    }

    return [
        'percentage' => min(100, $score),
        'missing' => $missing,
        'is_complete' => $score >= 100
    ];
}

function handle_employee_profile_update(PDO $pdo, int $empId, int $userId): array {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        return ['success' => false, 'error' => 'Security token invalid. Please refresh the page and try again.'];
    }

    // Fetch existing employee record first
    $eStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $eStmt->execute([$empId]);
    $existing = $eStmt->fetch();
    if (!$existing) {
        return ['success' => false, 'error' => 'Employee profile not found.'];
    }

    $firstName  = isset($_POST['first_name']) ? trim($_POST['first_name']) : ($existing['first_name'] ?? '');
    $lastName   = isset($_POST['last_name']) ? trim($_POST['last_name']) : ($existing['last_name'] ?? '');
    $fullName   = trim($firstName . ' ' . $lastName);
    if (empty($fullName)) {
        $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : ($existing['full_name'] ?? '');
    }

    // Strict lowercase email enforcement
    $email      = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : strtolower($existing['email'] ?? '');
    $phone      = isset($_POST['phone']) ? trim($_POST['phone']) : ($existing['phone'] ?? '');
    $whatsapp   = isset($_POST['whatsapp']) ? trim($_POST['whatsapp']) : ($existing['whatsapp'] ?? '');
    $altPhone   = isset($_POST['alternate_phone']) ? trim($_POST['alternate_phone']) : ($existing['alternate_phone'] ?? '');
    $emergency  = isset($_POST['emergency_contact']) ? trim($_POST['emergency_contact']) : ($existing['emergency_contact'] ?? '');
    $emgPhone   = isset($_POST['emergency_contact_phone']) ? trim($_POST['emergency_contact_phone']) : ($existing['emergency_contact_phone'] ?? '');

    $address    = isset($_POST['address']) ? trim($_POST['address']) : ($existing['address'] ?? '');
    $city       = isset($_POST['city']) ? trim($_POST['city']) : ($existing['city'] ?? '');
    $state      = isset($_POST['state']) ? trim($_POST['state']) : ($existing['state'] ?? '');
    $pincode    = isset($_POST['pincode']) ? trim($_POST['pincode']) : ($existing['pincode'] ?? '');

    $bankName   = isset($_POST['bank_name']) ? trim($_POST['bank_name']) : ($existing['bank_name'] ?? '');
    $bankAccNo  = isset($_POST['bank_acc_no']) ? trim($_POST['bank_acc_no']) : ($existing['bank_acc_no'] ?? '');
    $bankIfsc   = isset($_POST['bank_ifsc']) ? strtoupper(trim($_POST['bank_ifsc'])) : ($existing['bank_ifsc'] ?? '');
    $pan        = isset($_POST['pan']) ? strtoupper(trim($_POST['pan'])) : ($existing['pan'] ?? '');
    $aadhaarRef = isset($_POST['aadhaar_ref']) ? trim($_POST['aadhaar_ref']) : ($existing['aadhaar_ref'] ?? '');

    if (empty($fullName)) {
        return ['success' => false, 'error' => 'Full Name is required.'];
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please provide a valid email address.'];
    }

    // Check email uniqueness across employees
    $chkEmp = $pdo->prepare("SELECT id FROM employees WHERE email = ? AND id != ? LIMIT 1");
    $chkEmp->execute([$email, $empId]);
    if ($chkEmp->fetchColumn()) {
        return ['success' => false, 'error' => "The email '{$email}' is already registered by another employee."];
    }

    // Check email uniqueness across users
    if ($userId > 0) {
        $chkUsr = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $chkUsr->execute([$email, $userId]);
        if ($chkUsr->fetchColumn()) {
            return ['success' => false, 'error' => "The email '{$email}' is already in use by another user account."];
        }
    }


    $photoDbPath   = $existing['profile_photo'] ?? null;
    $resumeDbPath  = $existing['resume_file'] ?? null;
    $aadhaarDbPath = $existing['aadhaar_file'] ?? null;

    // 1. Profile Photo Upload (Supports Cropped Base64 or Direct File Upload)
    $croppedBase64 = trim($_POST['cropped_profile_photo'] ?? '');
    if (!empty($croppedBase64) && preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/i', $croppedBase64, $imgMatches)) {
        $photoDir = __DIR__ . '/../uploads/employee_photos/';
        if (!is_dir($photoDir)) @mkdir($photoDir, 0777, true);
        $dataParts = explode(',', $croppedBase64, 2);
        $decoded = base64_decode($dataParts[1] ?? '');
        if ($decoded === false || strlen($decoded) < 50) {
            return ['success' => false, 'error' => 'Invalid cropped image format.'];
        }
        if (strlen($decoded) > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Cropped profile photo file size cannot exceed 5MB.'];
        }
        $subExt = strtolower($imgMatches[1]) === 'png' ? 'png' : (strtolower($imgMatches[1]) === 'webp' ? 'webp' : 'jpg');
        $pFileName = 'photo_' . $empId . '_' . time() . '.' . $subExt;
        if (file_put_contents($photoDir . $pFileName, $decoded) !== false) {
            $photoDbPath = 'uploads/employee_photos/' . $pFileName;
        } else {
            return ['success' => false, 'error' => 'Could not save cropped profile photo to directory.'];
        }
    } elseif (!empty($_FILES['profile_photo']['name'])) {
        if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['profile_photo']['error'];
            if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                return ['success' => false, 'error' => 'Profile photo exceeds maximum allowed upload size.'];
            }
            return ['success' => false, 'error' => 'Profile photo upload failed (Error: ' . $errCode . ').'];
        }
        $photoDir = __DIR__ . '/../uploads/employee_photos/';
        if (!is_dir($photoDir)) @mkdir($photoDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        $allowedPhotoExts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedPhotoExts)) {
            return ['success' => false, 'error' => 'Profile photo must be a JPG, PNG, or WEBP image.'];
        }
        if ($_FILES['profile_photo']['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Profile photo file size cannot exceed 5MB.'];
        }
        $pFileName = 'photo_' . $empId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $photoDir . $pFileName)) {
            $photoDbPath = 'uploads/employee_photos/' . $pFileName;
        } else {
            return ['success' => false, 'error' => 'Could not save profile photo to server directory. Please check permissions.'];
        }
    }

    // 2. Resume / CV File Upload (Allowed: all document formats)
    if (!empty($_FILES['resume_file']['name'])) {
        if ($_FILES['resume_file']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['resume_file']['error'];
            if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                return ['success' => false, 'error' => 'Resume file exceeds maximum allowed upload size.'];
            }
            return ['success' => false, 'error' => 'Resume upload failed (Error: ' . $errCode . ').'];
        }
        $docDir = __DIR__ . '/../uploads/employee_docs/';
        if (!is_dir($docDir)) @mkdir($docDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['resume_file']['name'], PATHINFO_EXTENSION));
        $allowedDocExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'heic', 'heif', 'zip', 'rar'];
        if (!in_array($ext, $allowedDocExts)) {
            return ['success' => false, 'error' => 'Resume / CV file format not supported.'];
        }
        if ($_FILES['resume_file']['size'] > 25 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Resume file size cannot exceed 25MB.'];
        }
        $rFileName = 'resume_' . $empId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['resume_file']['tmp_name'], $docDir . $rFileName)) {
            $resumeDbPath = 'uploads/employee_docs/' . $rFileName;
            // Permanently remove previous resume files from disk and update DB
            sync_replace_employee_document($pdo, $empId, 'Resume', $resumeDbPath, $userId, 'Resume / Curriculum Vitae');
        } else {
            return ['success' => false, 'error' => 'Could not save Resume file to server directory. Please check permissions.'];
        }
    }

    // 3. Govtment ID (Aadhaar Card / PAN Card) File Upload
    if (!empty($_FILES['aadhaar_file']['name'])) {
        if ($_FILES['aadhaar_file']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['aadhaar_file']['error'];
            if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                return ['success' => false, 'error' => 'Govtment ID file exceeds maximum allowed upload size.'];
            }
            return ['success' => false, 'error' => 'Govtment ID upload failed (Error: ' . $errCode . ').'];
        }
        $docDir = __DIR__ . '/../uploads/employee_docs/';
        if (!is_dir($docDir)) @mkdir($docDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['aadhaar_file']['name'], PATHINFO_EXTENSION));
        $allowedGovtIdExts = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'webp', 'heic', 'gif', 'bmp', 'svg'];
        if (!in_array($ext, $allowedGovtIdExts)) {
            return ['success' => false, 'error' => 'Govtment ID file format not supported.'];
        }
        if ($_FILES['aadhaar_file']['size'] > 25 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Govtment ID file size cannot exceed 25MB.'];
        }
        $aFileName = 'govtid_' . $empId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['aadhaar_file']['tmp_name'], $docDir . $aFileName)) {
            $aadhaarDbPath = 'uploads/employee_docs/' . $aFileName;
            // Permanently remove previous ID Proof files from disk and update DB
            sync_replace_employee_document($pdo, $empId, 'ID Proof', $aadhaarDbPath, $userId, 'Govtment ID (Aadhaar / PAN)');
        } else {
            return ['success' => false, 'error' => 'Could not save Govtment ID file to server directory. Please check permissions.'];
        }
    }

    // Support explicit photo deletion
    if (!empty($_POST['remove_photo'])) {
        if (!empty($existing['profile_photo'])) {
            $oldP = __DIR__ . '/../' . ltrim($existing['profile_photo'], '/');
            if (file_exists($oldP)) @unlink($oldP);
        }
        $photoDbPath = null;
    }

    // Unlink old photo file if replaced with new one
    if (!empty($existing['profile_photo']) && $photoDbPath !== $existing['profile_photo']) {
        $oldP = __DIR__ . '/../' . ltrim($existing['profile_photo'], '/');
        if (file_exists($oldP)) @unlink($oldP);
    }


    // 4. Update Employees Record
    $updateStmt = $pdo->prepare("
        UPDATE employees SET 
            first_name = ?, last_name = ?, full_name = ?, email = ?, 
            phone = ?, whatsapp = ?, alternate_phone = ?, emergency_contact = ?, emergency_contact_phone = ?,
            address = ?, city = ?, state = ?, pincode = ?,
            bank_name = ?, bank_acc_no = ?, bank_ifsc = ?, pan = ?, aadhaar_ref = ?,
            profile_photo = ?, resume_file = ?, aadhaar_file = ?
        WHERE id = ?
    ");
    $updateStmt->execute([
        $firstName, $lastName, $fullName, $email,
        $phone, $whatsapp, $altPhone, $emergency, $emgPhone,
        $address, $city, $state, $pincode,
        $bankName, $bankAccNo, $bankIfsc, $pan, $aadhaarRef,
        $photoDbPath, $resumeDbPath, $aadhaarDbPath,
        $empId
    ]);

    // 5. Synchronize Users Record
    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")
            ->execute([$fullName, $email, $userId]);
        $_SESSION['user_name'] = $fullName;
        $_SESSION['user_email'] = $email;

        $hasDocUpload = (!empty($_FILES['resume_file']['name']) || !empty($_FILES['aadhaar_file']['name']));
        if ($hasDocUpload) {
            create_action_notification($pdo, $userId, 'Document Uploaded', 'Your profile document(s) have been uploaded and saved successfully.', '/employee/profile.php', 'doc_uploaded');
        }
        if (!empty($_FILES['profile_photo']['name'])) {
            create_action_notification($pdo, $userId, 'Profile Photo Updated', 'Your profile photo has been updated successfully.', '/employee/profile.php', 'profile_photo_updated');
        }
        create_action_notification($pdo, $userId, 'Profile Details Updated', 'Your profile information and contact details were saved successfully.', '/employee/profile.php', 'profile_updated');
    }

    return ['success' => true, 'message' => 'Profile details, bank information, and documents saved successfully!'];
}
