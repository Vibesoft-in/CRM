<?php
// Crm and backend/api/mobile_profile.php
require_once __DIR__ . '/mobile_helper.php';

$auth = authenticate_mobile();
$emp = $auth['employee'];
$user = $auth['user'];
$empId = $auth['employee_id'];
$userId = (int)$auth['user_id'];

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');
$input = get_json_input();

// ----------------------------------------------------
// ACTION: CHANGE PASSWORD
// ----------------------------------------------------
if ($action === 'change_password') {
    $oldPass  = $input['old_password'] ?? ($_POST['old_password'] ?? '');
    $newPass  = $input['new_password'] ?? ($_POST['new_password'] ?? '');
    $confPass = $input['confirm_password'] ?? ($_POST['confirm_password'] ?? '');

    if (empty($oldPass) || empty($newPass) || empty($confPass)) {
        json_error('All password fields are required', 400);
    }

    $uStmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $dbUser = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dbUser || !password_verify($oldPass, $dbUser['password_hash'])) {
        json_error('Current password does not match our records', 400);
    }

    if (strlen($newPass) < 6) {
        json_error('New password must be at least 6 characters long', 400);
    }

    if ($newPass !== $confPass) {
        json_error('New password and confirmation password do not match', 400);
    }

    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $upd->execute([$newHash, $userId]);

    create_action_notification(
        $pdo,
        (int)$userId,
        'Password Changed',
        'Your account password was changed successfully.',
        '/employee/profile.php',
        'password_changed'
    );

    json_success(null, 'Password updated successfully');
}

// ----------------------------------------------------
// ACTION: UPDATE PROFILE
// ----------------------------------------------------
if ($action === 'update') {
    $firstName  = trim($input['first_name'] ?? '');
    $lastName   = trim($input['last_name'] ?? '');
    $fullName   = trim($firstName . ' ' . $lastName);
    if (empty($fullName)) {
        $fullName = trim($input['full_name'] ?? ($input['name'] ?? ''));
        if (empty($firstName) && !empty($fullName)) {
            $parts = explode(' ', $fullName, 2);
            $firstName = $parts[0];
            $lastName = $parts[1] ?? '';
        }
    }

    $email      = strtolower(trim($input['email'] ?? ''));
    $phone      = trim($input['phone'] ?? '');
    $whatsapp   = trim($input['whatsapp'] ?? '');
    $altPhone   = trim($input['alternate_phone'] ?? '');
    $emergency  = trim($input['emergency_contact'] ?? '');
    $emgPhone   = trim($input['emergency_contact_phone'] ?? '');

    $address    = trim($input['address'] ?? '');
    $city       = trim($input['city'] ?? '');
    $state      = trim($input['state'] ?? '');
    $pincode    = trim($input['pincode'] ?? '');

    $bankName   = trim($input['bank_name'] ?? '');
    $bankAccNo  = trim($input['bank_acc_no'] ?? '');
    $bankIfsc   = strtoupper(trim($input['bank_ifsc'] ?? ''));
    $pan        = strtoupper(trim($input['pan'] ?? ''));
    $aadhaarRef = trim($input['aadhaar_ref'] ?? '');

    if (empty($fullName)) {
        json_error('Full name / First name is required', 400);
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('A valid email address is required', 400);
    }

    // Email uniqueness check
    if ($empId) {
        $chkEmp = $pdo->prepare("SELECT id FROM employees WHERE email = ? AND id != ? LIMIT 1");
        $chkEmp->execute([$email, $empId]);
        if ($chkEmp->fetchColumn()) {
            json_error("The email '$email' is already registered by another employee", 400);
        }
    }

    if ($userId > 0) {
        $chkUsr = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $chkUsr->execute([$email, $userId]);
        if ($chkUsr->fetchColumn()) {
            json_error("The email '$email' is already registered by another account", 400);
        }
    }

    $profilePhotoInput = trim($input['profile_photo'] ?? ($input['photo'] ?? ''));

    // 1. Update Employee Record if linked
    if ($empId) {
        $photoSql = "";
        $photoParams = [];
        if (!empty($profilePhotoInput)) {
            $photoSql = ", profile_photo = ?";
            $photoParams[] = $profilePhotoInput;
        }

        $uStmt = $pdo->prepare("
            UPDATE employees SET 
                first_name = ?, last_name = ?, full_name = ?, email = ?, 
                phone = ?, whatsapp = ?, alternate_phone = ?, emergency_contact = ?, emergency_contact_phone = ?,
                address = ?, city = ?, state = ?, pincode = ?,
                bank_name = ?, bank_acc_no = ?, bank_ifsc = ?, pan = ?, aadhaar_ref = ?
                {$photoSql}
            WHERE id = ?
        ");
        $params = array_merge([
            $firstName, $lastName, $fullName, $email,
            $phone, $whatsapp, $altPhone, $emergency, $emgPhone,
            $address, $city, $state, $pincode,
            $bankName, $bankAccNo, $bankIfsc, $pan, $aadhaarRef,
        ], $photoParams, [$empId]);
        $uStmt->execute($params);
    }

    // 2. Synchronize Users Record
    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")
            ->execute([$fullName, $email, $userId]);
    }

    create_action_notification(
        $pdo,
        (int)$userId,
        'Profile Details Updated',
        'Your profile information and contact details were saved successfully.',
        '/employee/profile.php',
        'profile_updated'
    );

    json_success([
        'name'          => $fullName,
        'first_name'    => $firstName,
        'last_name'     => $lastName,
        'email'         => $email,
        'phone'         => $phone,
        'whatsapp'      => $whatsapp,
        'alternate_phone' => $altPhone,
        'emergency_contact' => $emergency,
        'emergency_contact_phone' => $emgPhone,
        'address'       => $address,
        'city'          => $city,
        'state'         => $state,
        'pincode'       => $pincode,
        'bank_name'     => $bankName,
        'bank_acc_no'   => $bankAccNo,
        'bank_ifsc'     => $bankIfsc,
        'pan'           => $pan,
        'aadhaar_ref'   => $aadhaarRef,
    ], 'Profile updated successfully');
}

// ----------------------------------------------------
// ----------------------------------------------------
// ACTION: UPLOAD AVATAR / PROFILE PHOTO
// ----------------------------------------------------
if ($action === 'upload_avatar') {
    if (!$empId) {
        json_error('No linked employee profile found', 400);
    }

    $uploadDir = __DIR__ . '/../uploads/employee_photos/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $photoUrl = null;
    $base64Data = trim($input['image_base64'] ?? ($_POST['image_base64'] ?? ($input['cropped_profile_photo'] ?? ($_POST['cropped_profile_photo'] ?? ''))));

    if (!empty($base64Data)) {
        $ext = 'jpg';
        if (preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $base64Data, $m)) {
            $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
            $commaPos = strpos($base64Data, ',');
            if ($commaPos !== false) {
                $base64Data = substr($base64Data, $commaPos + 1);
            }
        }
        $decoded = base64_decode($base64Data);
        if ($decoded === false || strlen($decoded) < 20) {
            json_error('Invalid image data provided', 400);
        }
        if (strlen($decoded) > 5 * 1024 * 1024) {
            json_error('Image size cannot exceed 5MB', 400);
        }
        $fileName = 'photo_' . $empId . '_' . time() . '.' . $ext;
        if (file_put_contents($uploadDir . $fileName, $decoded) === false) {
            json_error('Failed to save image file on server', 500);
        }
        $photoUrl = 'uploads/employee_photos/' . $fileName;
    } else {
        $file = $_FILES['avatar'] ?? ($_FILES['profile_photo'] ?? ($_FILES['file'] ?? null));
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            json_error('Please select a valid image file to upload', 400);
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            json_error('Only JPG, JPEG, PNG, and WEBP formats are allowed', 400);
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            json_error('Image size cannot exceed 5MB', 400);
        }

        $fileName = 'photo_' . $empId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            json_error('Failed to save uploaded image on server', 500);
        }
        $photoUrl = 'uploads/employee_photos/' . $fileName;
    }

    // Delete old profile photo from disk
    $prevPhotoStmt = $pdo->prepare("SELECT profile_photo FROM employees WHERE id = ?");
    $prevPhotoStmt->execute([$empId]);
    $oldPhoto = $prevPhotoStmt->fetchColumn();
    if (!empty($oldPhoto) && $oldPhoto !== $photoUrl) {
        $oldFile = __DIR__ . '/../' . ltrim($oldPhoto, '/');
        if (file_exists($oldFile)) {
            @unlink($oldFile);
        }
    }

    $pdo->prepare("UPDATE employees SET profile_photo = ? WHERE id = ?")->execute([$photoUrl, $empId]);

    create_action_notification(
        $pdo,
        (int)$userId,
        'Profile Photo Updated',
        'Your profile photo was updated successfully.',
        '/employee/profile.php',
        'profile_photo_updated'
    );

    json_success([
        'profile_photo' => $photoUrl,
        'full_url'      => resolve_media_url($photoUrl),
        'url'           => resolve_media_url($photoUrl),
        'photo_url'     => resolve_media_url($photoUrl)
    ], 'Profile photo updated successfully');
}

// ----------------------------------------------------
// ACTION: DELETE PROFILE PHOTO
// ----------------------------------------------------
if ($action === 'delete_photo' || $action === 'remove_photo') {
    if (!$empId) {
        json_error('No linked employee profile found', 400);
    }
    $prevPhotoStmt = $pdo->prepare("SELECT profile_photo FROM employees WHERE id = ?");
    $prevPhotoStmt->execute([$empId]);
    $oldPhoto = $prevPhotoStmt->fetchColumn();
    if (!empty($oldPhoto)) {
        $oldFile = __DIR__ . '/../' . ltrim($oldPhoto, '/');
        if (file_exists($oldFile)) {
            @unlink($oldFile);
        }
    }
    $pdo->prepare("UPDATE employees SET profile_photo = NULL WHERE id = ?")->execute([$empId]);
    json_success([
        'profile_photo' => null,
        'full_url'      => resolve_media_url(null, $user['name'] ?? 'User'),
        'url'           => resolve_media_url(null, $user['name'] ?? 'User'),
        'photo_url'     => resolve_media_url(null, $user['name'] ?? 'User')
    ], 'Profile photo removed successfully');
}

// ----------------------------------------------------
// ACTION: UPLOAD DOCUMENT (RESUME / GOVT ID)
// ----------------------------------------------------
if ($action === 'upload_document') {
    if (!$empId) {
        json_error('No linked employee profile found', 400);
    }

    $docType = strtolower(trim($input['document_type'] ?? ($_POST['document_type'] ?? 'resume')));
    $isResume = ($docType === 'resume' || $docType === 'cv');
    $column = $isResume ? 'resume_file' : 'aadhaar_file';
    $label = $isResume ? 'Resume / CV' : 'Govt ID';

    $uploadDir = __DIR__ . '/../uploads/employee_docs/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $docUrl = null;
    $base64Data = trim($input['file_base64'] ?? ($_POST['file_base64'] ?? ''));

    if (!empty($base64Data)) {
        $rawFileName = trim($input['file_name'] ?? ($_POST['file_name'] ?? ''));
        $ext = strtolower(pathinfo($rawFileName, PATHINFO_EXTENSION));
        if (empty($ext)) $ext = 'pdf';

        if (preg_match('#^data:([a-zA-Z0-9/.\-]+);base64,#i', $base64Data)) {
            $commaPos = strpos($base64Data, ',');
            if ($commaPos !== false) {
                $base64Data = substr($base64Data, $commaPos + 1);
            }
        }

        $decoded = base64_decode($base64Data);
        if ($decoded === false || strlen($decoded) < 10) {
            json_error('Invalid document data provided', 400);
        }
        if (strlen($decoded) > 25 * 1024 * 1024) {
            json_error('Document size cannot exceed 25MB', 400);
        }

        $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'heic', 'heif', 'zip', 'rar'];
        if (!in_array($ext, $allowedExts)) {
            json_error('File format not supported. Supported: PDF, DOC, DOCX, XLS, XLSX, PPT, TXT, Images, etc.', 400);
        }

        $prefix = $isResume ? 'resume_' : 'govtid_';
        $fileName = $prefix . $empId . '_' . time() . '.' . $ext;
        if (file_put_contents($uploadDir . $fileName, $decoded) === false) {
            json_error('Failed to save document on server', 500);
        }
        $docUrl = 'uploads/employee_docs/' . $fileName;
    } else {
        $file = $_FILES['document'] ?? ($_FILES['file'] ?? ($_FILES['resume_file'] ?? ($_FILES['aadhaar_file'] ?? null)));
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            json_error('Please select a valid document file to upload', 400);
        }

        $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'heic', 'heif', 'zip', 'rar'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            json_error('File format not supported. Supported: PDF, DOC, DOCX, XLS, XLSX, PPT, TXT, Images, etc.', 400);
        }

        if ($file['size'] > 25 * 1024 * 1024) {
            json_error('Document size cannot exceed 25MB', 400);
        }

        $prefix = $isResume ? 'resume_' : 'govtid_';
        $fileName = $prefix . $empId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            json_error('Failed to save uploaded document on server', 500);
        }
        $docUrl = 'uploads/employee_docs/' . $fileName;
    }

    // Permanently remove previous document files from disk and keep single record
    $dtParam = $isResume ? 'Resume' : 'ID Proof';
    sync_replace_employee_document($pdo, $empId, $dtParam, $docUrl, (int)$userId, $label);

    create_action_notification(
        $pdo,
        (int)$userId,
        "$label Updated",
        "Your $label document was uploaded successfully.",
        '/employee/profile.php',
        'document_updated'
    );

    json_success([
        'document_type' => $docType,
        'document_path' => $docUrl,
        'full_url'      => resolve_media_url($docUrl)
    ], "$label uploaded successfully");
}

// ----------------------------------------------------
// ACTION: GET PROFILE
// ----------------------------------------------------
// Refresh employee details from DB
if ($empId) {
    // Auto-sync employee_documents if employees column is empty
    $sR = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ? AND doc_type = 'Resume' ORDER BY id DESC LIMIT 1");
    $sR->execute([$empId]);
    if ($fR = $sR->fetchColumn()) {
        $pdo->prepare("UPDATE employees SET resume_file = ? WHERE id = ? AND (resume_file IS NULL OR resume_file = '')")->execute([$fR, $empId]);
    }

    $sA = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ? AND doc_type = 'ID Proof' ORDER BY id DESC LIMIT 1");
    $sA->execute([$empId]);
    if ($fA = $sA->fetchColumn()) {
        $pdo->prepare("UPDATE employees SET aadhaar_file = ? WHERE id = ? AND (aadhaar_file IS NULL OR aadhaar_file = '')")->execute([$fA, $empId]);
    }

    $sO = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ? AND doc_type = 'Offer Letter' ORDER BY id DESC LIMIT 1");
    $sO->execute([$empId]);
    if ($fO = $sO->fetchColumn()) {
        $pdo->prepare("UPDATE employees SET offer_letter_file = ?, offer_letter_status = 'Sent' WHERE id = ? AND (offer_letter_file IS NULL OR offer_letter_file = '')")->execute([$fO, $empId]);
    }

    $eStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
    $eStmt->execute([$empId]);
    $emp = $eStmt->fetch(PDO::FETCH_ASSOC) ?: $emp;
}

// Refresh user details from DB
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$uStmt->execute([$userId]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC) ?: $user;

$name = $emp['full_name'] ?? $user['name'];
$firstName = $emp['first_name'] ?? '';
$lastName = $emp['last_name'] ?? '';
if (empty($firstName)) {
    $parts = explode(' ', trim($name), 2);
    $firstName = $parts[0];
    $lastName = $parts[1] ?? '';
}

$empCode = $emp['employee_code'] ?? ('EMP-' . ($empId ?: $user['id']));
$designation = $emp['designation'] ?? ($user['role'] ?? 'Staff');
$department = $emp['department'] ?? 'General';
$joinedDate = !empty($emp['joining_date']) ? date('M d, Y', strtotime($emp['joining_date'])) : null;
$workMode = $emp['employee_type'] ?? 'Full Time';

// Manager resolution
$managerName = 'Administrator';
if (!empty($emp['manager_id'])) {
    $mStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $mStmt->execute([$emp['manager_id']]);
    $managerName = $mStmt->fetchColumn() ?: 'Administrator';
}

// Bank account masking
$accRaw = $emp['bank_acc_no'] ?? '';
$accMasked = !empty($accRaw) ? ('•••• •••• ' . substr(str_replace(' ', '', $accRaw), -4)) : 'Not Provided';

$ifscRaw = $emp['bank_ifsc'] ?? '';
$ifscMasked = !empty($ifscRaw) ? $ifscRaw : 'Not Provided';

require_once __DIR__ . '/../employee/profile_handler.php';
$profileScore = calculate_profile_completion($emp);

json_success([
    'profile' => [
        'id'                    => $empId,
        'user_id'               => $userId,
        'role'                  => $user['role'] ?? 'Employee',
        'name'                  => $name,
        'first_name'            => $firstName,
        'last_name'             => $lastName,
        'employee_code'         => $empCode,
        'designation'           => $designation,
        'work_mode'             => $workMode,
        'department'            => $department,
        'joined_date'           => $joinedDate,
        'manager'               => $managerName,
        'profile_photo'         => resolve_media_url($emp['profile_photo'] ?? null, $name),
        'resume_file'           => resolve_media_url($emp['resume_file'] ?? null),
        'aadhaar_file'          => resolve_media_url($emp['aadhaar_file'] ?? null),
        'offer_letter_file'     => resolve_media_url($emp['offer_letter_file'] ?? null),
        'profile_photo_raw'     => $emp['profile_photo'] ?? null,
        'resume_file_raw'       => $emp['resume_file'] ?? null,
        'aadhaar_file_raw'      => $emp['aadhaar_file'] ?? null,
        'offer_letter_raw'      => $emp['offer_letter_file'] ?? null,
        'offer_letter_status'   => $emp['offer_letter_status'] ?? 'Not Generated',
        'offer_letter_date'     => $emp['offer_letter_date'] ?? null,
        'company_name'          => 'VibeSoft',
        'company_website'       => 'vibesoft.in',
        'completion_percentage' => $profileScore['percentage'],
        'is_complete'           => $profileScore['is_complete'],
        'missing_fields'        => $profileScore['missing'],
        
        // Editable fields (raw values)
        'first_name_raw'  => $firstName,
        'last_name_raw'   => $lastName,
        'email_raw'       => $emp['email'] ?? $user['email'],
        'phone_raw'       => $emp['phone'] ?? '',
        'whatsapp_raw'    => $emp['whatsapp'] ?? '',
        'alternate_phone_raw' => $emp['alternate_phone'] ?? '',
        'address_raw'     => $emp['address'] ?? '',
        'city_raw'        => $emp['city'] ?? '',
        'state_raw'       => $emp['state'] ?? '',
        'pincode_raw'     => $emp['pincode'] ?? '',
        'emergency_contact_raw' => $emp['emergency_contact'] ?? '',
        'emergency_contact_phone_raw' => $emp['emergency_contact_phone'] ?? '',
        'bank_name_raw'   => $emp['bank_name'] ?? '',
        'bank_acc_no_raw' => $accRaw,
        'bank_ifsc_raw'   => $ifscRaw,
        'pan_raw'         => $emp['pan'] ?? '',
        'aadhaar_ref_raw' => $emp['aadhaar_ref'] ?? '',

        'contact_info'  => [
            'email'           => $emp['email'] ?? $user['email'],
            'phone'           => $emp['phone'] ?? '',
            'whatsapp'        => $emp['whatsapp'] ?? '',
            'alternate_phone' => $emp['alternate_phone'] ?? '',
            'address'         => $emp['address'] ?? '',
            'city'            => $emp['city'] ?? '',
            'state'           => $emp['state'] ?? '',
            'pincode'         => $emp['pincode'] ?? '',
        ],
        'emergency_contact' => [
            'name'         => $emp['emergency_contact'] ?? '',
            'relationship' => 'Contact',
            'phone'        => $emp['emergency_contact_phone'] ?? ''
        ],
        'bank_info' => [
            'bank_name'   => $emp['bank_name'] ?? 'Not Specified',
            'account_no'  => $accMasked,
            'routing_no'  => $ifscMasked,
            'pan'         => $emp['pan'] ?? 'Not Specified',
            'aadhaar_ref' => $emp['aadhaar_ref'] ?? 'Not Specified',
        ]
    ]
], 'Profile loaded successfully');
