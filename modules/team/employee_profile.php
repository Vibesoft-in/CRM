<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid Employee ID");

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) die("Employee not found");

$err = '';

// Handle Edit Employee Profile POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_profile') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $employee_code = trim($_POST['employee_code']);
        $full_name     = trim($_POST['full_name']);
        $phone         = trim($_POST['phone']);
        $email         = trim($_POST['email']);
        $joining_date  = $_POST['joining_date'] ?: date('Y-m-d');
        $department    = trim($_POST['department']);
        $designation   = trim($_POST['designation']);
        $employee_type = $_POST['employee_type'] ?? 'Full Time';
        $salary_type   = $_POST['salary_type'] ?? 'Monthly';
        $base_salary   = (float)($_POST['base_salary'] ?? 0);
        $bank_name     = trim($_POST['bank_name'] ?? '');
        $bank_acc_no   = trim($_POST['bank_acc_no'] ?? '');
        $bank_ifsc     = trim($_POST['bank_ifsc'] ?? '');
        $pan           = trim($_POST['pan'] ?? '');
        $address       = trim($_POST['address'] ?? '');
        $status        = $_POST['status'] ?? 'active';

        if (empty($full_name) || empty($email) || empty($phone)) {
            $err = 'Full Name, Email, and Phone are required.';
        } else {
            $upd = $pdo->prepare("UPDATE employees SET 
                employee_code = ?, full_name = ?, phone = ?, email = ?, joining_date = ?, 
                department = ?, designation = ?, employee_type = ?, salary_type = ?, 
                base_salary = ?, bank_name = ?, bank_acc_no = ?, bank_ifsc = ?, pan = ?, address = ?, status = ? 
                WHERE id = ?");
            $upd->execute([
                $employee_code, $full_name, $phone, $email, $joining_date,
                $department, $designation, $employee_type, $salary_type,
                $base_salary, $bank_name, $bank_acc_no, $bank_ifsc, $pan, $address, $status, $id
            ]);

            // Handle Profile Photo Upload
            if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $photoUploadDir = __DIR__ . '/../../uploads/employee_photos/';
                if (!is_dir($photoUploadDir)) {
                    @mkdir($photoUploadDir, 0777, true);
                }
                $pExt = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($pExt, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'avif'])) {
                    $pFileName = 'emp_' . $id . '_' . time() . '.' . $pExt;
                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $photoUploadDir . $pFileName)) {
                        $photoDbPath = 'uploads/employee_photos/' . $pFileName;
                        $oldP = $pdo->query("SELECT profile_photo FROM employees WHERE id = " . intval($id))->fetchColumn();
                        if (!empty($oldP) && $oldP !== $photoDbPath && file_exists(__DIR__ . '/../../' . $oldP)) {
                            @unlink(__DIR__ . '/../../' . $oldP);
                        }
                        $pdo->prepare("UPDATE employees SET profile_photo = ? WHERE id = ?")->execute([$photoDbPath, $id]);
                    }
                }
            }

            set_flash('Employee details and profile photo updated successfully!', 'success');
            header("Location: employee_profile.php?id=$id&tab=" . ($_GET['tab'] ?? 'overview'));
            exit;
        }
    }
}


// Handle Add Skill POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_skill') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $skill_name  = trim($_POST['skill_name']);
        $skill_level = $_POST['skill_level'] ?? 'Intermediate';
        if (!empty($skill_name)) {
            $ins = $pdo->prepare("INSERT INTO employee_skills (employee_id, skill_name, skill_level) VALUES (?, ?, ?)");
            $ins->execute([$id, $skill_name, $skill_level]);
            set_flash('Skill added!', 'success');
            header("Location: employee_profile.php?id=$id&tab=skills");
            exit;
        }
    }
}

// Handle Document / Resume Upload POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $doc_name = trim($_POST['doc_name']);
        $doc_type = $_POST['doc_type'] ?? 'Resume';

        if (empty($doc_name) || empty($_FILES['doc_file']['name'])) {
            $err = 'Document name and file are required.';
        } else {
            $uploadDir = '../../uploads/employee_docs/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'heic', 'heif', 'zip', 'rar'];

            if (!in_array($ext, $allowedExts)) {
                $err = 'File format not supported. Allowed: PDF, DOC, DOCX, XLS, XLSX, PPT, TXT, Images, etc.';
            } else {
                $newFileName = 'EMP_' . $id . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                $targetFile = $uploadDir . $newFileName;
                $dbPath = 'uploads/employee_docs/' . $newFileName;

                if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $targetFile)) {
                    // Permanently remove older document files and sync records
                    sync_replace_employee_document($pdo, (int)$id, $doc_type, $dbPath, (int)($_SESSION['user_id'] ?? 1), $doc_name);

                    // Notify employee if offer letter
                    if ($doc_type === 'Offer Letter') {
                        try {
                            $empUserId = get_user_id_by_employee_id($pdo, (int)$id);
                            if ($empUserId) {
                                create_action_notification(
                                    $pdo,
                                    (int)$empUserId,
                                    'Official Offer Letter Issued',
                                    'Your official offer letter has been uploaded and issued by management. View your profile documents to review.',
                                    '/employee/profile.php',
                                    'offer_letter_issued'
                                );
                            }
                        } catch (\Throwable $t) {}
                    }

                    set_flash('Document uploaded successfully!', 'success');
                    header("Location: employee_profile.php?id=$id&tab=documents");
                    exit;
                } else {
                    $err = 'Failed to upload document file.';
                }
            }
        }
    }
}

// Handle Delete Document POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_document') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $doc_id = intval($_POST['doc_id']);
        sync_delete_employee_document($pdo, (int)$id, $doc_id);
        set_flash('Document deleted successfully.', 'info');
    }

    header("Location: employee_profile.php?id=$id&tab=documents");
    exit;
}

// Handle Increase Salary / Hike POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'increase_salary') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $old_salary     = (float)$emp['base_salary'];
        $new_salary     = (float)($_POST['new_salary'] ?? 0);
        $effective_date = $_POST['effective_date'] ?: date('Y-m-d');
        $reason_notes   = trim($_POST['reason_notes'] ?? '');

        if ($new_salary <= 0) {
            $err = 'Please enter a valid new base salary.';
        } elseif ($new_salary <= $old_salary && empty($_POST['confirm_decrease'])) {
            $err = 'New salary is less than or equal to current salary. Check confirm checkbox if decreasing.';
        } else {
            $increment_amount = $new_salary - $old_salary;
            $percentage_increase = ($old_salary > 0) ? round(($increment_amount / $old_salary) * 100, 2) : 0;

            $pdo->beginTransaction();
            try {
                // Log increment history
                $insH = $pdo->prepare("INSERT INTO employee_salary_history (
                    employee_id, old_salary, new_salary, increment_amount, percentage_increase, effective_date, reason_notes, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $insH->execute([$id, $old_salary, $new_salary, $increment_amount, $percentage_increase, $effective_date, $reason_notes, $_SESSION['user_id'] ?? 1]);

                // Update current employee base salary
                $updE = $pdo->prepare("UPDATE employees SET base_salary = ? WHERE id = ?");
                $updE->execute([$new_salary, $id]);

                $pdo->commit();
                set_flash('Salary increased successfully! New Base Salary: ₹' . money($new_salary), 'success');
                header("Location: employee_profile.php?id=$id&tab=salary_history");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $err = 'Failed to record salary hike: ' . $e->getMessage();
            }
        }
    }
}

// Handle Delete Salary Increment Log POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_salary_increment') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $inc_id = intval($_POST['increment_id']);
        $incStmt = $pdo->prepare("SELECT * FROM employee_salary_history WHERE id = ? AND employee_id = ?");
        $incStmt->execute([$inc_id, $id]);
        $incRec = $incStmt->fetch();
        if ($incRec) {
            $pdo->prepare("DELETE FROM employee_salary_history WHERE id = ?")->execute([$inc_id]);
            
            // Revert base salary to previous recorded value if needed
            $latestHist = $pdo->prepare("SELECT new_salary FROM employee_salary_history WHERE employee_id = ? ORDER BY effective_date DESC, id DESC LIMIT 1");
            $latestHist->execute([$id]);
            $revertSalary = $latestHist->fetchColumn();
            if ($revertSalary !== false) {
                $pdo->prepare("UPDATE employees SET base_salary = ? WHERE id = ?")->execute([$revertSalary, $id]);
            }
            set_flash('Salary increment log deleted.', 'info');
        }
    }
    header("Location: employee_profile.php?id=$id&tab=salary_history");
    exit;
}

// Handle Quick Process Salary POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_quick_payroll') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $payroll_month    = (int)$_POST['payroll_month'];
        $payroll_year     = (int)$_POST['payroll_year'];
        $base_salary      = (float)$_POST['base_salary'];
        $allowances       = (float)($_POST['allowances'] ?? 0);
        $bonus            = (float)($_POST['bonus'] ?? 0);
        $advance_deducted = (float)($_POST['advance_deducted'] ?? 0);
        $late_deduction   = (float)($_POST['late_deduction'] ?? 0);
        $other_deduction  = (float)($_POST['other_deduction'] ?? 0);

        $gross = $base_salary + $allowances + $bonus;
        $totalDeductions = $advance_deducted + $late_deduction + $other_deduction;
        $netSalary = max(0, $gross - $totalDeductions);

        $payment_method  = $_POST['payment_method'] ?? 'Bank Transfer';
        $transaction_ref = trim($_POST['transaction_ref'] ?? '');
        $payment_date    = $_POST['payment_date'] ?: date('Y-m-d');

        $insP = $pdo->prepare("INSERT INTO payroll (
            employee_id, payroll_month, payroll_year, base_salary, allowances, bonus, 
            advance_deducted, late_deduction, other_deduction, net_salary, amount_paid, amount_pending, 
            status, payment_date, payment_method, transaction_ref, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Paid', ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            base_salary=VALUES(base_salary), allowances=VALUES(allowances), bonus=VALUES(bonus), 
            advance_deducted=VALUES(advance_deducted), late_deduction=VALUES(late_deduction), other_deduction=VALUES(other_deduction),
            net_salary=VALUES(net_salary), amount_paid=VALUES(amount_paid), status='Paid', 
            payment_date=VALUES(payment_date), payment_method=VALUES(payment_method), transaction_ref=VALUES(transaction_ref)");

        $insP->execute([
            $id, $payroll_month, $payroll_year, $base_salary, $allowances, $bonus,
            $advance_deducted, $late_deduction, $other_deduction, $netSalary, $netSalary,
            $payment_date, $payment_method, $transaction_ref
        ]);

        if ($advance_deducted > 0) {
            $updAdv = $pdo->prepare("UPDATE salary_advances SET status = 'Deducted', payroll_month = ?, payroll_year = ? WHERE employee_id = ? AND status = 'Paid'");
            $updAdv->execute([$payroll_month, $payroll_year, $id]);
        }

        set_flash('Salary paid successfully and payslip generated!', 'success');
        header("Location: employee_profile.php?id=$id&tab=payroll");
        exit;
    }
}


// Handle Offer Letter Status Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_offer_letter_status') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $ol_status = $_POST['offer_letter_status'];
        $ol_date   = $_POST['offer_letter_date'] ?: date('Y-m-d');
        $updOL = $pdo->prepare("UPDATE employees SET offer_letter_status = ?, offer_letter_date = ? WHERE id = ?");
        $updOL->execute([$ol_status, $ol_date, $id]);

        // Notify employee via in-app & mobile push
        try {
            $empUserId = get_user_id_by_employee_id($pdo, (int)$id);
            if ($empUserId) {
                create_action_notification(
                    $pdo,
                    (int)$empUserId,
                    'Offer Letter Status: ' . $ol_status,
                    'Your official offer letter status has been updated to: ' . $ol_status,
                    '/employee/profile.php',
                    'offer_letter_issued'
                );
            }
        } catch (\Throwable $t) {}

        set_flash('Offer letter status updated to ' . e($ol_status), 'success');
    }
    header("Location: employee_profile.php?id=$id&tab=documents");
    exit;
}

// Handle Reset Password POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $new_password = trim($_POST['new_password'] ?? '');
        $send_email = !empty($_POST['send_email']);

        if (empty($new_password)) {
            $err = 'Please provide a valid new password.';
        } else {
            $res = reset_employee_password($pdo, $id, $new_password, $send_email);
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
                header("Location: employee_profile.php?id=$id");
                exit;
            } else {
                $err = $res['error'] ?? 'Failed to reset password.';
            }
        }
    }
}

// Handle Review Daily Work Report POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'review_daily_report') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $repId   = intval($_POST['report_id'] ?? 0);
        $rating  = intval($_POST['rating'] ?? 0);
        $comment = trim($_POST['review_note'] ?? '');
        $userId  = $_SESSION['user_id'] ?? null;

        if ($repId > 0) {
            $upd = $pdo->prepare("UPDATE daily_work_reports SET rating = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND employee_id = ?");
            $upd->execute([$rating ?: null, $comment, $userId, $repId, $id]);

            // Sync with work_reports table if present
            try {
                $pdo->prepare("UPDATE work_reports SET status = 'reviewed', review_comment = ?, reviewed_by = ?, reviewed_at = NOW() WHERE employee_id = ? AND report_date = (SELECT report_date FROM daily_work_reports WHERE id = ?)")
                    ->execute([$comment, $userId, $id, $repId]);
            } catch (Exception $e) {}

            // Send notification to employee & push to mobile!
            try {
                $empUserId = get_user_id_by_employee_id($pdo, (int)$id);
                if ($empUserId) {
                    $ratingText = $rating > 0 ? " ({$rating}★)" : "";
                    $msg = "Admin has reviewed your daily work report" . $ratingText . (!empty($comment) ? ": \"{$comment}\"" : ".");
                    create_action_notification(
                        $pdo,
                        (int)$empUserId,
                        'Daily Report Reviewed',
                        $msg,
                        '/employee/work-reports.php',
                        'work_report_reviewed',
                        ['report_id' => $repId, 'rating' => $rating, 'review_note' => $comment]
                    );
                }
            } catch (\Throwable $nt) {}

            set_flash('Daily work report reviewed & rated successfully!', 'success');
        }
        $m = intval($_POST['month'] ?? date('n'));
        $y = intval($_POST['year'] ?? date('Y'));
        header("Location: employee_profile.php?id=$id&tab=attendance_reports&month=$m&year=$y");
        exit;
    }
}

// Re-fetch employee data
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();

// Sub datasets
$skills = $pdo->prepare("SELECT * FROM employee_skills WHERE employee_id = ?");
$skills->execute([$id]);
$skills = $skills->fetchAll();

$documents = $pdo->prepare("SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY created_at DESC");
$documents->execute([$id]);
$documents = $documents->fetchAll();

$advances = $pdo->prepare("SELECT * FROM salary_advances WHERE employee_id = ? ORDER BY request_date DESC");
$advances->execute([$id]);
$advances = $advances->fetchAll();

$payrolls = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? ORDER BY payroll_year DESC, payroll_month DESC");
$payrolls->execute([$id]);
$payrolls = $payrolls->fetchAll();

$salaryHistory = $pdo->prepare("SELECT * FROM employee_salary_history WHERE employee_id = ? ORDER BY effective_date DESC, id DESC");
$salaryHistory->execute([$id]);
$salaryHistory = $salaryHistory->fetchAll();

$stmtPendingAdv = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM salary_advances WHERE employee_id = ? AND status = 'Paid'");
$stmtPendingAdv->execute([$id]);
$empPendingAdv = (float)$stmtPendingAdv->fetchColumn();

$pwdLogs = get_employee_password_logs($pdo, $id, 50);

$activeTab = $_GET['tab'] ?? 'overview';

// ============================================================
// Attendance & Daily Reports (A & R) Data Processing
// ============================================================
$arMonth = intval($_GET['month'] ?? date('n'));
$arYear  = intval($_GET['year'] ?? date('Y'));
if ($arMonth < 1 || $arMonth > 12) $arMonth = (int)date('n');
if ($arYear < 2020 || $arYear > 2035) $arYear = (int)date('Y');

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $arMonth, $arYear);
$monthStart  = sprintf('%04d-%02d-01', $arYear, $arMonth);
$monthEnd    = sprintf('%04d-%02d-%02d', $arYear, $arMonth, $daysInMonth);
$monthName   = date('F Y', strtotime($monthStart));

$prevMonthTime = strtotime("-1 month", strtotime($monthStart));
$prevMonth = (int)date('n', $prevMonthTime);
$prevYear  = (int)date('Y', $prevMonthTime);

$nextMonthTime = strtotime("+1 month", strtotime($monthStart));
$nextMonth = (int)date('n', $nextMonthTime);
$nextYear  = (int)date('Y', $nextMonthTime);

$holidaysMap  = get_active_holidays_map($pdo, $arYear);
$overridesMap = get_holiday_overrides_map($pdo, $arYear);

// Fetch attendance records for this employee in selected month
$attStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date ASC");
$attStmt->execute([$id, $monthStart, $monthEnd]);
$attRecordsRaw = $attStmt->fetchAll(PDO::FETCH_ASSOC);
$attByDate = [];
foreach ($attRecordsRaw as $row) {
    $attByDate[$row['attendance_date']] = $row;
}

// Fetch breaks / lunch sessions for this employee in selected month
$brkStmt = $pdo->prepare("SELECT * FROM lunch_sessions WHERE employee_id = ? AND session_date BETWEEN ? AND ? ORDER BY session_date ASC, id ASC");
$brkStmt->execute([$id, $monthStart, $monthEnd]);
$breaksByDate = [];
foreach ($brkStmt->fetchAll(PDO::FETCH_ASSOC) as $bs) {
    $bDate = $bs['session_date'];
    if (!isset($breaksByDate[$bDate])) {
        $breaksByDate[$bDate] = [
            'sessions' => [],
            'total_seconds' => 0
        ];
    }
    $durSec = (int)($bs['duration_seconds'] ?? 0);
    if ($bs['status'] === 'active' && !empty($bs['start_time'])) {
        $startTs = strtotime($bs['session_date'] . ' ' . $bs['start_time']);
        $durSec = max(0, time() - $startTs);
    }
    $breaksByDate[$bDate]['total_seconds'] += $durSec;
    $breaksByDate[$bDate]['sessions'][] = $bs;
}

// Fetch daily work reports
$repStmt = $pdo->prepare("
    SELECT dwr.*, wr.summary AS wr_summary, wr.tasks_completed AS wr_tasks_completed, wr.challenges AS wr_challenges, wr.tomorrow_plan AS wr_tomorrow_plan
    FROM daily_work_reports dwr
    LEFT JOIN work_reports wr ON wr.employee_id = dwr.employee_id AND wr.report_date = dwr.report_date
    WHERE dwr.employee_id = ? AND dwr.report_date BETWEEN ? AND ?
    ORDER BY dwr.report_date DESC
");
$repStmt->execute([$id, $monthStart, $monthEnd]);
$reportsRaw = $repStmt->fetchAll(PDO::FETCH_ASSOC);
$reportsByDate = [];
foreach ($reportsRaw as $r) {
    $reportsByDate[$r['report_date']] = $r;
}

// Fallback: check work_reports table for any dates not in daily_work_reports
try {
    $wrExtraStmt = $pdo->prepare("
        SELECT wr.*, wr.report_date, wr.summary, wr.tasks_completed, wr.challenges, wr.tomorrow_plan, wr.status AS wr_status
        FROM work_reports wr
        WHERE wr.employee_id = ? AND wr.report_date BETWEEN ? AND ?
    ");
    $wrExtraStmt->execute([$id, $monthStart, $monthEnd]);
    foreach ($wrExtraStmt->fetchAll(PDO::FETCH_ASSOC) as $wr) {
        $rDate = $wr['report_date'];
        if (!isset($reportsByDate[$rDate])) {
            $reportsByDate[$rDate] = [
                'id' => $wr['id'],
                'employee_id' => $wr['employee_id'],
                'report_date' => $rDate,
                'morning_tasks' => $wr['summary'] ?? '',
                'evening_tasks' => $wr['tasks_completed'] ?? '',
                'projects_worked' => $wr['projects_worked'] ?? '',
                'clients_worked' => '',
                'hours_worked' => $wr['hours_worked'] ?? 0,
                'blockers' => $wr['challenges'] ?? '',
                'plan_for_tomorrow' => $wr['tomorrow_plan'] ?? '',
                'mood' => 'good',
                'rating' => null,
                'review_note' => $wr['review_comment'] ?? null,
                'reviewed_by' => $wr['reviewed_by'] ?? null,
                'reviewed_at' => $wr['reviewed_at'] ?? null,
                'submitted_at' => $wr['created_at'] ?? null,
                'updated_at' => $wr['updated_at'] ?? null,
            ];
        }
    }
} catch (Exception $e) {}

// Daily Loop & Statistics Calculation
$today = date('Y-m-d');
$joiningDate = !empty($emp['joining_date']) ? $emp['joining_date'] : '2000-01-01';

$totalWorkingDaysInMonth = 0;
$totalHolidaysInMonth = 0;

$presentDays = 0;
$absentDays = 0;
$leaveDays = 0;
$halfDays = 0;
$lateDays = 0;
$totalLateMinutes = 0;
$totalWorkHours = 0;
$reportsSubmittedCount = 0;

$dailyRows = [];

for ($d = 1; $d <= $daysInMonth; $d++) {
    $dStr = sprintf('%04d-%02d-%02d', $arYear, $arMonth, $d);
    $dTs  = strtotime($dStr);
    $isPastOrToday = ($dStr <= $today);
    $isJoined = ($dStr >= $joiningDate);

    // Holiday & Sunday check
    $dayCheck = is_date_holiday_or_sunday($dStr, $holidaysMap, $overridesMap);
    $isNonWorking = (bool)$dayCheck['is_non_working'];

    if ($isNonWorking) {
        $totalHolidaysInMonth++;
    } else {
        $totalWorkingDaysInMonth++;
    }

    // Daily attendance resolution using the system's central engine
    $attRow = $attByDate[$dStr] ?? null;
    $res = resolve_daily_attendance_status($pdo, $id, $dStr, $attRow, $holidaysMap);

    // Corresponding report
    $reportRow = $reportsByDate[$dStr] ?? null;
    if ($reportRow) {
        $reportsSubmittedCount++;
    }

    // Break data
    $breakInfo = $breaksByDate[$dStr] ?? null;
    $breakSec = $breakInfo ? $breakInfo['total_seconds'] : 0;
    $breakMin = (int)round($breakSec / 60);

    // Count statistics
    $statusRaw = $res['status_raw'];

    if ($isJoined) {
        if ($isNonWorking) {
            // Strictly Sunday Weekly Off or Holiday - never count towards present or absent
        } elseif ($statusRaw === 'present') {
            $presentDays++;
        } elseif ($statusRaw === 'late') {
            $presentDays++;
            $lateDays++;
        } elseif ($statusRaw === 'half_day') {
            $halfDays++;
            $presentDays += 0.5;
        } elseif ($statusRaw === 'absent') {
            if ($isPastOrToday) {
                $absentDays++;
            }
        } elseif ($statusRaw === 'on_leave') {
            $leaveDays++;
        }
    }

    if ($attRow && !empty($attRow['late_minutes'])) {
        $totalLateMinutes += (int)$attRow['late_minutes'];
    }
    if (!empty($res['total_hours'])) {
        $totalWorkHours += (float)$res['total_hours'];
    }

    $dailyRows[] = [
        'date' => $dStr,
        'day_num' => $d,
        'day_name' => date('D', $dTs),
        'full_day' => date('l', $dTs),
        'formatted' => date('d M Y', $dTs),
        'is_today' => ($dStr === $today),
        'is_future' => ($dStr > $today),
        'is_joined' => $isJoined,
        'is_non_working' => $isNonWorking,
        'day_check' => $dayCheck,
        'attendance' => $attRow,
        'resolution' => $res,
        'report' => $reportRow,
        'break_min' => $breakMin,
        'break_info' => $breakInfo,
    ];
}

$moodIcons = [
    'great'    => '😄',
    'good'     => '🙂',
    'okay'     => '😐',
    'tired'    => '😴',
    'stressed' => '😰',
];
?>


<div class="container-fluid">
  
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
            <input type="text" id="copiedProfilePwdField" class="form-control font-monospace fw-bold bg-white text-dark" value="<?= htmlspecialchars($lpr['password']) ?>" readonly>
            <button class="btn btn-primary" type="button" onclick="copyPasswordToClipboard('copiedProfilePwdField')"><i class="bi bi-clipboard me-1"></i> Copy</button>
          </div>
          <a href="https://api.whatsapp.com/send?text=<?= urlencode("Hello " . $lpr['name'] . ",\nYour login credentials for VibeSoft CMS have been updated.\nEmail: " . $lpr['email'] . "\nPassword: " . $lpr['password'] . "\nLogin URL: " . (defined('BASE_URL') ? BASE_URL : '') . "modules/auth/login.php") ?>" target="_blank" class="btn btn-sm btn-success text-white">
            <i class="bi bi-whatsapp me-1"></i> Share
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Profile Header -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: #ffffff;">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center">
          <?= get_employee_avatar_html($emp, 64) ?>

          <div>
            <div class="d-flex align-items-center gap-2">
              <h3 class="fw-bold text-dark mb-0"><?= e($emp['full_name']) ?></h3>
              <span class="badge bg-primary font-monospace"><?= e($emp['employee_code']) ?></span>
              <span class="badge bg-<?= $emp['status']=='active'?'success':'secondary' ?>"><?= ucfirst(e($emp['status'])) ?></span>
            </div>
            <p class="text-muted mb-0 mt-1">
              <i class="bi bi-briefcase me-1"></i><?= e($emp['designation'] ?: 'Team Member') ?> &bull; 
              <i class="bi bi-building me-1"></i><?= e($emp['department'] ?: 'General') ?> &bull; 
              <i class="bi bi-telephone me-1"></i><?= e($emp['phone']) ?>
            </p>
          </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
          <div class="bg-light p-2 px-3 rounded-3 text-center border">
            <small class="text-muted d-block uppercase" style="font-size: 0.68rem; font-weight: 700;">CURRENT BASE SALARY</small>
            <span class="fw-bold fs-5 text-success">₹<?= money($emp['base_salary']) ?></span>
          </div>

          <button class="btn btn-warning fw-semibold py-2 px-3 shadow-sm text-dark" data-bs-toggle="modal" data-bs-target="#increaseSalaryModal">
            <i class="bi bi-graph-up-arrow me-1"></i> Increase Salary
          </button>

          <button class="btn btn-success fw-semibold py-2 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#paySalaryModal">
            <i class="bi bi-cash-coin me-1"></i> Pay Salary
          </button>

          <button class="btn btn-outline-info fw-semibold py-2 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" title="Regenerate / Reset Password">
            <i class="bi bi-key-fill text-warning me-1"></i> Reset Password
          </button>

          <button class="btn btn-outline-primary fw-semibold py-2 px-3" data-bs-toggle="modal" data-bs-target="#sendOfferLetterModal">
            <i class="bi bi-send me-1"></i> Send Offer Letter
          </button>

          <button class="btn btn-primary fw-semibold py-2 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#editProfileModal">
            <i class="bi bi-pencil-square me-1"></i> Edit Profile
          </button>

          <form method="post" action="delete_employee.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete employee <?= e(addslashes($emp['full_name'])) ?>? This action cannot be undone.');">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $emp['id'] ?>">
            <button type="submit" class="btn btn-outline-danger fw-semibold py-2 px-3 shadow-sm" title="Delete Employee">
              <i class="bi bi-trash me-1"></i> Delete
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Profile Nav Tabs -->
  <ul class="nav nav-pills mb-4 bg-white p-2 rounded-3 shadow-sm border" id="employeeTabs">
    <li class="nav-item"><a class="nav-link <?= $activeTab==='overview'?'active':'' ?>" href="?id=<?= $id ?>&tab=overview"><i class="bi bi-person me-1"></i> Overview</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='attendance_reports'?'active':'' ?>" href="?id=<?= $id ?>&tab=attendance_reports"><i class="bi bi-calendar2-check me-1"></i> A & R (Attendance & Reports)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='password_history'?'active':'' ?>" href="?id=<?= $id ?>&tab=password_history"><i class="bi bi-shield-lock me-1"></i> Passwords & Devices (<?= count($pwdLogs) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='salary_history'?'active':'' ?>" href="?id=<?= $id ?>&tab=salary_history"><i class="bi bi-graph-up-arrow me-1"></i> Salary Hike History (<?= count($salaryHistory) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='payroll'?'active':'' ?>" href="?id=<?= $id ?>&tab=payroll"><i class="bi bi-bank me-1"></i> Payroll & Slips (<?= count($payrolls) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='documents'?'active':'' ?>" href="?id=<?= $id ?>&tab=documents"><i class="bi bi-file-earmark-pdf me-1"></i> Documents & Offer Letter (<?= count($documents) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='skills'?'active':'' ?>" href="?id=<?= $id ?>&tab=skills"><i class="bi bi-star me-1"></i> Skills (<?= count($skills) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='advances'?'active':'' ?>" href="?id=<?= $id ?>&tab=advances"><i class="bi bi-cash-stack me-1"></i> Advances (<?= count($advances) ?>)</a></li>
  </ul>


  <!-- Tab Content -->
  <?php if ($activeTab === 'overview'): ?>
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm" style="border-radius: 12px;">
          <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0">Personal & Contact Info</h6>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="bi bi-pencil"></i> Edit</button>
          </div>
          <div class="card-body">
            <table class="table table-borderless align-middle mb-0">
              <tr><td class="text-muted" style="width: 140px;">Email:</td><td class="fw-semibold"><?= e($emp['email']) ?></td></tr>
              <tr><td class="text-muted">Phone:</td><td class="fw-semibold"><?= e($emp['phone']) ?></td></tr>
              <tr><td class="text-muted">Joining Date:</td><td><?= !empty($emp['joining_date']) ? date('d M Y', strtotime($emp['joining_date'])) : 'N/A' ?></td></tr>
              <tr><td class="text-muted">Employee Type:</td><td><span class="badge bg-light text-dark border"><?= e($emp['employee_type']) ?></span></td></tr>
              <tr><td class="text-muted">Address:</td><td><?= e($emp['address'] ?: 'N/A') ?></td></tr>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card border-0 shadow-sm" style="border-radius: 12px;">
          <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0">Bank & Salary Details</h6>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="bi bi-pencil"></i> Edit</button>
          </div>
          <div class="card-body">
            <table class="table table-borderless align-middle mb-0">
              <tr><td class="text-muted" style="width: 140px;">Salary Type:</td><td class="fw-semibold"><?= e($emp['salary_type']) ?></td></tr>
              <tr><td class="text-muted">Monthly Base Salary:</td><td class="fw-bold text-success fs-6">₹<?= money($emp['base_salary']) ?> / month</td></tr>
              <tr><td class="text-muted">Bank Name:</td><td><?= e($emp['bank_name'] ?: 'N/A') ?></td></tr>
              <tr><td class="text-muted">Account No:</td><td class="font-monospace"><?= e($emp['bank_acc_no'] ?: 'N/A') ?></td></tr>
              <tr><td class="text-muted">IFSC Code:</td><td class="font-monospace"><?= e($emp['bank_ifsc'] ?: 'N/A') ?></td></tr>
              <tr><td class="text-muted">PAN Number:</td><td><?= e($emp['pan'] ?: 'N/A') ?></td></tr>
            </table>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($activeTab === 'attendance_reports'): ?>
    <!-- A & R (Attendance & Reports) Container -->
    <div class="mb-4">
      
      <!-- Top Bar: Month & Year Navigator -->
      <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
          <div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fs-6">
                <i class="bi bi-calendar2-check me-1"></i> A & R
              </span>
              <h5 class="fw-bold mb-0 text-dark">Attendance & Daily Work Reports</h5>
            </div>
            <p class="text-muted small mb-0 mt-1">
              Monthly detailed record for <strong><?= e($emp['full_name']) ?></strong> (<?= e($emp['employee_code']) ?>) &bull; Period: <span class="fw-bold text-dark"><?= $monthName ?></span>
            </p>
          </div>

          <!-- Month-Year Quick Navigator -->
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="?id=<?= $id ?>&tab=attendance_reports&month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-outline-secondary btn-sm shadow-none" title="Previous Month">
              <i class="bi bi-chevron-left"></i> Prev
            </a>

            <form method="get" class="d-inline-flex gap-2 align-items-center mb-0">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="tab" value="attendance_reports">
              
              <select name="month" class="form-select form-select-sm fw-semibold shadow-none" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                  <option value="<?= $m ?>" <?= $m == $arMonth ? 'selected' : '' ?>>
                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                  </option>
                <?php endfor; ?>
              </select>

              <select name="year" class="form-select form-select-sm fw-semibold shadow-none" onchange="this.form.submit()">
                <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                  <option value="<?= $y ?>" <?= $y == $arYear ? 'selected' : '' ?>>
                    <?= $y ?>
                  </option>
                <?php endfor; ?>
              </select>
            </form>

            <a href="?id=<?= $id ?>&tab=attendance_reports&month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-outline-secondary btn-sm shadow-none" title="Next Month">
              Next <i class="bi bi-chevron-right"></i>
            </a>

            <?php if ($arMonth != date('n') || $arYear != date('Y')): ?>
              <a href="?id=<?= $id ?>&tab=attendance_reports&month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-primary btn-sm shadow-none">
                <i class="bi bi-calendar-event me-1"></i> Current Month
              </a>
            <?php endif; ?>

            <button type="button" class="btn btn-outline-dark btn-sm shadow-none" onclick="window.print()" title="Print Monthly Sheet">
              <i class="bi bi-printer"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Header KPI Summary Cards (The exact requirements requested by user) -->
      <div class="row g-3 mb-4">
        <!-- 1. Total Working Days in Month -->
        <div class="col-xl-2 col-md-4 col-sm-6">
          <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: linear-gradient(135deg, #f0f7ff 0%, #e0effe 100%); border-left: 4px solid #0284c7!important;">
            <div class="card-body p-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Working Days</span>
                <div class="bg-white rounded-circle p-2 shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                  <i class="bi bi-calendar2-range text-primary fs-6"></i>
                </div>
              </div>
              <div class="fs-4 fw-bold text-dark"><?= $totalWorkingDaysInMonth ?> <span class="fs-6 fw-normal text-muted">Days</span></div>
              <div class="small text-muted" style="font-size: 0.75rem;">Total work days in <?= date('M Y', strtotime($monthStart)) ?></div>
            </div>
          </div>
        </div>

        <!-- 2. Present Days (Out of Working Days) -->
        <div class="col-xl-2 col-md-4 col-sm-6">
          <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-left: 4px solid #16a34a!important;">
            <div class="card-body p-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-success fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Present Days</span>
                <div class="bg-white rounded-circle p-2 shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                  <i class="bi bi-check-circle-fill text-success fs-6"></i>
                </div>
              </div>
              <div class="fs-4 fw-bold text-success">
                <?= $presentDays ?> <span class="fs-6 text-muted fw-normal">/ <?= $totalWorkingDaysInMonth ?></span>
              </div>
              <div class="small text-success-emphasis d-flex align-items-center justify-content-between" style="font-size: 0.75rem;">
                <span><?= round(($presentDays / max(1, $totalWorkingDaysInMonth)) * 100) ?>% Attendance</span>
                <?php if ($halfDays > 0): ?><span class="badge bg-info-subtle text-info border"><?= $halfDays ?> Half Day</span><?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- 3. Absent Days -->
        <div class="col-xl-2 col-md-4 col-sm-6">
          <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); border-left: 4px solid #dc2626!important;">
            <div class="card-body p-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-danger fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Absent Days</span>
                <div class="bg-white rounded-circle p-2 shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                  <i class="bi bi-x-circle-fill text-danger fs-6"></i>
                </div>
              </div>
              <div class="fs-4 fw-bold text-danger"><?= $absentDays ?> <span class="fs-6 fw-normal text-muted">Days</span></div>
              <div class="small text-danger-emphasis" style="font-size: 0.75rem;">Unmarked / no punch</div>
            </div>
          </div>
        </div>

        <!-- 4. Leave Days -->
        <div class="col-xl-2 col-md-4 col-sm-6">
          <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%); border-left: 4px solid #9333ea!important;">
            <div class="card-body p-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-purple fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px; color: #7e22ce;">On Leave</span>
                <div class="bg-white rounded-circle p-2 shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                  <i class="bi bi-airplane-fill fs-6" style="color: #9333ea;"></i>
                </div>
              </div>
              <div class="fs-4 fw-bold" style="color: #9333ea;"><?= $leaveDays ?> <span class="fs-6 fw-normal text-muted">Days</span></div>
              <div class="small" style="font-size: 0.75rem; color: #7e22ce;">Approved leaves taken</div>
            </div>
          </div>
        </div>

        <!-- 5. Holidays & Sundays in Month -->
        <div class="col-xl-2 col-md-4 col-sm-6">
          <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 4px solid #d97706!important;">
            <div class="card-body p-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-warning-emphasis fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Holidays & Offs</span>
                <div class="bg-white rounded-circle p-2 shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                  <i class="bi bi-cup-hot-fill text-warning fs-6"></i>
                </div>
              </div>
              <div class="fs-4 fw-bold text-warning-emphasis"><?= $totalHolidaysInMonth ?> <span class="fs-6 fw-normal text-muted">Days</span></div>
              <div class="small text-warning-emphasis" style="font-size: 0.75rem;">Sundays & official holidays</div>
            </div>
          </div>
        </div>

        <!-- 6. Work Reports Submitted -->
        <div class="col-xl-2 col-md-4 col-sm-6">
          <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: linear-gradient(135deg, #f0fdfa 0%, #ccfbf1 100%); border-left: 4px solid #0d9488!important;">
            <div class="card-body p-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-teal fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px; color: #0f766e;">Work Reports</span>
                <div class="bg-white rounded-circle p-2 shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                  <i class="bi bi-journal-check fs-6" style="color: #0d9488;"></i>
                </div>
              </div>
              <div class="fs-4 fw-bold" style="color: #0f766e;">
                <?= $reportsSubmittedCount ?> <span class="fs-6 text-muted fw-normal">/ <?= ceil($presentDays) ?></span>
              </div>
              <div class="small" style="font-size: 0.75rem; color: #0f766e;">
                <?= ceil($presentDays) > 0 ? round(($reportsSubmittedCount / max(1, ceil($presentDays))) * 100) : 0 ?>% submission rate
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Controls Toolbar: View Toggle & Filters -->
      <div class="card border-0 shadow-sm mb-3" style="border-radius: 12px;">
        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
          <!-- View switcher buttons -->
          <div class="btn-group shadow-none" role="group">
            <button type="button" class="btn btn-sm btn-primary active ar-view-toggle" data-view="all" onclick="setArView('all', this)">
              <i class="bi bi-calendar3 me-1"></i> All-in-One Daily Log
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary ar-view-toggle" data-view="att" onclick="setArView('att', this)">
              <i class="bi bi-clock-history me-1"></i> Attendance Only
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary ar-view-toggle" data-view="rep" onclick="setArView('rep', this)">
              <i class="bi bi-file-earmark-text me-1"></i> Work Reports (<?= $reportsSubmittedCount ?>)
            </button>
          </div>

          <!-- Quick Filters & Search -->
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <select id="arStatusFilter" class="form-select form-select-sm" style="width: 170px;" onchange="filterArTable()">
              <option value="all">All Days (<?= count($dailyRows) ?>)</option>
              <option value="present">Present Only</option>
              <option value="late">Late Arrivals</option>
              <option value="half_day">Half Days</option>
              <option value="absent">Absent Only</option>
              <option value="on_leave">Leaves Only</option>
              <option value="holiday">Holidays / Sundays</option>
              <option value="has_report">Report Submitted</option>
              <option value="missing_report">Missing Report</option>
            </select>

            <div class="input-group input-group-sm" style="width: 220px;">
              <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
              <input type="text" id="arSearchInput" class="form-control" placeholder="Search tasks, date..." onkeyup="filterArTable()">
            </div>
          </div>
        </div>
      </div>

      <!-- VIEW 1: All-in-One Daily Log Table -->
      <div id="arViewAllContainer" class="card border-0 shadow-sm mb-4" style="border-radius: 14px; overflow: hidden;">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <div>
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-table text-primary me-2"></i>Daily Attendance & Work Reports Ledger</h6>
            <small class="text-muted">Day-by-day punch timings, hours worked, and corresponding evening work reports for <?= $monthName ?></small>
          </div>
          <span class="badge bg-light text-dark border px-2 py-1"><?= count($dailyRows) ?> Days</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="arTable">
            <thead class="table-light text-muted small text-uppercase">
              <tr>
                <th class="ps-3" style="width: 150px;">Date & Day</th>
                <th style="width: 130px;">Attendance</th>
                <th style="width: 170px;">Punch Timings</th>
                <th style="width: 140px;">Work & Breaks</th>
                <th style="width: 100px;">Late</th>
                <th>Daily Work Report Summary</th>
                <th class="text-end pe-3" style="width: 110px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dailyRows as $row): 
                $res = $row['resolution'];
                $att = $row['attendance'];
                $rep = $row['report'];
                $stRaw = $res['status_raw'];
                $hasRep = !empty($rep);
                $isMissRep = ($row['is_past_or_today'] ?? ($row['date'] <= $today)) && !$row['is_non_working'] && in_array($stRaw, ['present', 'late', 'half_day']) && !$hasRep;
                
                // Row background highlight
                $rowClass = '';
                if ($row['is_today']) $rowClass = 'table-primary bg-opacity-25';
                elseif ($row['is_non_working']) $rowClass = 'table-light bg-opacity-50';
                elseif ($stRaw === 'absent' && $row['date'] < $today) $rowClass = 'table-danger bg-opacity-10';
              ?>
                <tr class="<?= $rowClass ?> ar-row" 
                    data-status="<?= $stRaw ?>" 
                    data-has-report="<?= $hasRep ? '1' : '0' ?>"
                    data-missing-report="<?= $isMissRep ? '1' : '0' ?>"
                    data-search="<?= strtolower(e($row['formatted'] . ' ' . $row['day_name'] . ' ' . $res['status_label'] . ' ' . ($rep['morning_tasks'] ?? '') . ' ' . ($rep['evening_tasks'] ?? '') . ' ' . ($rep['projects_worked'] ?? ''))) ?>">
                  
                  <!-- Date & Day -->
                  <td class="ps-3">
                    <div class="d-flex align-items-center gap-2">
                      <div class="text-center rounded px-2 py-1 <?= $row['is_today'] ? 'bg-primary text-white fw-bold' : ($row['is_non_working'] ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light border text-dark') ?>" style="min-width: 44px;">
                        <span class="d-block lh-1 fw-bold fs-6"><?= $row['day_num'] ?></span>
                        <span class="d-block lh-1 text-uppercase" style="font-size: 0.65rem;"><?= $row['day_name'] ?></span>
                      </div>
                      <div>
                        <div class="fw-semibold text-dark small"><?= date('d M Y', strtotime($row['date'])) ?></div>
                        <?php if ($row['is_today']): ?>
                          <span class="badge bg-primary text-white" style="font-size: 0.62rem;">Today</span>
                        <?php elseif ($row['is_non_working']): ?>
                          <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.62rem;"><?= e($res['holiday_name'] ?: 'Off') ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>

                  <!-- Attendance Status -->
                  <td>
                    <?php if ($stRaw === 'present'): ?>
                      <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                        <i class="bi bi-check-circle me-1"></i>Present
                      </span>
                    <?php elseif ($stRaw === 'late'): ?>
                      <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
                        <i class="bi bi-clock-history me-1"></i>Late
                      </span>
                    <?php elseif ($stRaw === 'half_day'): ?>
                      <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1">
                        <i class="bi bi-pie-chart me-1"></i>Half Day
                      </span>
                    <?php elseif ($stRaw === 'absent'): ?>
                      <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                        <i class="bi bi-x-circle me-1"></i>Absent
                      </span>
                    <?php elseif ($stRaw === 'on_leave'): ?>
                      <span class="badge px-2 py-1" style="background: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe;">
                        <i class="bi bi-airplane me-1"></i>On Leave
                      </span>
                    <?php elseif ($stRaw === 'holiday'): ?>
                      <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
                        <i class="bi bi-cup-hot me-1"></i><?= e($res['holiday_name'] ?: 'Holiday') ?>
                      </span>
                    <?php elseif ($stRaw === 'pending'): ?>
                      <span class="badge bg-secondary-subtle text-secondary px-2 py-1">Pending</span>
                    <?php else: ?>
                      <span class="badge bg-light text-muted border px-2 py-1">Upcoming</span>
                    <?php endif; ?>
                  </td>

                  <!-- Punch Timings -->
                  <td>
                    <?php if (!empty($res['check_in'])): ?>
                      <div class="small">
                        <span class="text-success fw-semibold"><i class="bi bi-box-arrow-in-right me-1"></i><?= $res['check_in_formatted'] ?: $res['check_in'] ?></span>
                        <?php if (!empty($res['check_out'])): ?>
                          <span class="text-muted mx-1">&rarr;</span>
                          <span class="text-primary fw-semibold"><i class="bi bi-box-arrow-right me-1"></i><?= $res['check_out_formatted'] ?: $res['check_out'] ?></span>
                        <?php else: ?>
                          <span class="badge bg-warning-subtle text-warning-emphasis ms-1" style="font-size: 0.65rem;">Punch In Only</span>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($stRaw === 'absent'): ?>
                      <span class="text-muted small">No punch recorded</span>
                    <?php elseif ($row['is_non_working']): ?>
                      <span class="text-muted small">Off Day</span>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>

                  <!-- Work & Breaks -->
                  <td>
                    <?php if (!empty($res['check_in'])): ?>
                      <div class="fw-semibold text-dark small">
                        <i class="bi bi-stopwatch me-1 text-primary"></i><?= $res['hours_formatted'] ?: (number_format((float)$res['total_hours'], 2) . ' hrs') ?>
                      </div>
                      <?php if ($row['break_min'] > 0): ?>
                        <small class="text-muted d-block" style="font-size: 0.72rem;">
                          <i class="bi bi-cup-straw text-secondary me-1"></i>Break: <?= $row['break_min'] ?>m
                        </small>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>

                  <!-- Late -->
                  <td>
                    <?php if (!empty($res['late_minutes']) && (int)$res['late_minutes'] > 0): ?>
                      <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-1">
                        +<?= $res['late_minutes'] ?>m
                      </span>
                    <?php elseif (!empty($res['check_in'])): ?>
                      <span class="text-success small"><i class="bi bi-check2"></i> On Time</span>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>

                  <!-- Daily Work Report Summary -->
                  <td>
                    <?php if ($hasRep): ?>
                      <div class="d-flex align-items-center gap-2">
                        <span class="fs-6" title="Mood: <?= e($rep['mood']) ?>"><?= $moodIcons[$rep['mood']] ?? '📝' ?></span>
                        <div class="flex-grow-1 text-truncate" style="max-width: 380px;">
                          <div class="small fw-semibold text-dark text-truncate">
                            <?= e($rep['evening_tasks'] ?: $rep['morning_tasks'] ?: 'Report Submitted') ?>
                          </div>
                          <?php if (!empty($rep['projects_worked'])): ?>
                            <small class="text-muted text-truncate d-block" style="font-size: 0.72rem;">
                              <i class="bi bi-briefcase me-1"></i><?= e($rep['projects_worked']) ?>
                            </small>
                          <?php endif; ?>
                        </div>
                        <?php if (!empty($rep['rating'])): ?>
                          <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle py-1" title="Rating: <?= $rep['rating'] ?>/5">
                            ★ <?= $rep['rating'] ?>.0
                          </span>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($isMissRep): ?>
                      <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
                        <i class="bi bi-exclamation-triangle me-1"></i> Missing Work Report
                      </span>
                    <?php elseif ($stRaw === 'absent'): ?>
                      <span class="text-muted small">Employee Absent</span>
                    <?php elseif ($row['is_non_working']): ?>
                      <span class="text-muted small">Weekly Off / Holiday</span>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>

                  <!-- Action -->
                  <td class="text-end pe-3">
                    <?php if ($hasRep): ?>
                      <button type="button" class="btn btn-sm btn-primary py-1 px-2 shadow-sm"
                              data-bs-toggle="modal" data-bs-target="#arReportModal"
                              onclick="openReportModal('<?= $row['date'] ?>')"
                              title="View Full Report & Review">
                        <i class="bi bi-eye me-1"></i> Report
                      </button>
                    <?php elseif ($att): ?>
                      <span class="badge bg-light text-muted border py-1 px-2" title="No report submitted">Punched</span>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- VIEW 2: Attendance Only View (Punch Log, GPS, Breaks) -->
      <div id="arViewAttContainer" class="card border-0 shadow-sm mb-4 d-none" style="border-radius: 14px; overflow: hidden;">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <div>
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-fingerprint text-primary me-2"></i>Detailed Punch & GPS Verification Log</h6>
            <small class="text-muted">In/out timestamps, GPS geofencing logs, break sessions, and overtime for <?= $monthName ?></small>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light text-muted small text-uppercase">
              <tr>
                <th class="ps-3">Date</th>
                <th>Status</th>
                <th>Check In</th>
                <th>Check Out</th>
                <th>Net Hours</th>
                <th>Lunch / Breaks</th>
                <th>Late (Mins)</th>
                <th>GPS & Verification Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dailyRows as $row): 
                $res = $row['resolution'];
                $att = $row['attendance'];
              ?>
                <tr>
                  <td class="ps-3 fw-semibold small text-dark">
                    <?= $row['formatted'] ?> <span class="text-muted fw-normal">(<?= $row['day_name'] ?>)</span>
                  </td>
                  <td>
                    <span class="badge bg-<?= in_array($res['status_raw'], ['present','late']) ? 'success' : ($res['status_raw']=='absent'?'danger':($res['status_raw']=='half_day'?'info':'secondary')) ?>-subtle text-dark border px-2 py-1">
                      <?= e($res['status_label']) ?>
                    </span>
                  </td>
                  <td class="font-monospace small"><?= !empty($res['check_in']) ? $res['check_in_formatted'] ?: $res['check_in'] : '—' ?></td>
                  <td class="font-monospace small"><?= !empty($res['check_out']) ? $res['check_out_formatted'] ?: $res['check_out'] : '—' ?></td>
                  <td class="fw-bold small"><?= $res['hours_formatted'] ?: (number_format((float)$res['total_hours'], 2) . ' hrs') ?></td>
                  <td class="small">
                    <?php if ($row['break_min'] > 0): ?>
                      <span class="badge bg-light text-dark border"><i class="bi bi-cup-hot me-1 text-warning"></i><?= $row['break_min'] ?> mins</span>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="small">
                    <?= (!empty($res['late_minutes']) && (int)$res['late_minutes'] > 0) ? '<span class="text-danger fw-bold">+' . $res['late_minutes'] . 'm</span>' : '<span class="text-muted">0m</span>' ?>
                  </td>
                  <td class="small text-muted">
                    <?php if ($att && !empty($att['punch_latitude'])): ?>
                      <span class="badge bg-light text-dark border me-1"><i class="bi bi-geo-alt text-primary"></i> <?= round((float)$att['punch_distance_meters']) ?>m away</span>
                    <?php endif; ?>
                    <?= e($att['notes'] ?? $res['notes'] ?? '—') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- VIEW 3: Work Reports Dedicated Feed / Cards -->
      <div id="arViewRepContainer" class="d-none">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-file-earmark-text text-primary me-2"></i>Daily Work Reports for <?= $monthName ?> (<?= count($reportsRaw) ?> Submitted)
          </h6>
        </div>

        <?php if (!empty($reportsRaw)): ?>
          <div class="row g-3">
            <?php foreach ($reportsRaw as $r): 
              $rDate = $r['report_date'];
            ?>
              <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100" style="border-radius: 14px; background: #ffffff;">
                  <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom-0 pb-0">
                    <div class="d-flex align-items-center gap-2">
                      <span class="fs-4"><?= $moodIcons[$r['mood']] ?? '📝' ?></span>
                      <div>
                        <h6 class="fw-bold mb-0 text-dark"><?= date('d M Y, l', strtotime($rDate)) ?></h6>
                        <small class="text-muted font-monospace" style="font-size: 0.72rem;">
                          Submitted: <?= !empty($r['submitted_at']) ? date('d M Y, h:i A', strtotime($r['submitted_at'])) : 'Recorded' ?>
                        </small>
                      </div>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                      <?php if (!empty($r['hours_worked'])): ?>
                        <span class="badge bg-light text-dark border px-2 py-1"><i class="bi bi-clock me-1 text-primary"></i><?= $r['hours_worked'] ?> hrs</span>
                      <?php endif; ?>
                      <button type="button" class="btn btn-sm btn-outline-primary"
                              data-bs-toggle="modal" data-bs-target="#arReportModal"
                              onclick="openReportModal('<?= $rDate ?>')">
                        <i class="bi bi-pencil-square me-1"></i> Review
                      </button>
                    </div>
                  </div>

                  <div class="card-body p-3 pt-2">
                    <!-- Morning Plan -->
                    <?php if (!empty($r['morning_tasks'])): ?>
                      <div class="mb-2 p-2 rounded-2 bg-light border-start border-3 border-info">
                        <small class="text-muted fw-bold d-block text-uppercase" style="font-size: 0.65rem;">Morning Plan</small>
                        <div class="small text-dark"><?= nl2br(e($r['morning_tasks'])) ?></div>
                      </div>
                    <?php endif; ?>

                    <!-- Evening Tasks Accomplished -->
                    <div class="mb-2 p-2 rounded-2 bg-light border-start border-3 border-success">
                      <small class="text-muted fw-bold d-block text-uppercase" style="font-size: 0.65rem;">Evening Accomplishments</small>
                      <div class="small text-dark"><?= nl2br(e($r['evening_tasks'] ?: 'Tasks completed')) ?></div>
                    </div>

                    <!-- Projects & Blockers Grid -->
                    <div class="row g-2 small mb-2">
                      <?php if (!empty($r['projects_worked'])): ?>
                        <div class="col-sm-6">
                          <span class="text-muted d-block" style="font-size: 0.7rem;">Projects:</span>
                          <span class="fw-semibold text-dark"><?= e($r['projects_worked']) ?></span>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($r['blockers'])): ?>
                        <div class="col-sm-6">
                          <span class="text-danger d-block fw-semibold" style="font-size: 0.7rem;">Blockers / Challenges:</span>
                          <span class="text-danger small"><?= e($r['blockers']) ?></span>
                        </div>
                      <?php endif; ?>
                    </div>

                    <!-- Plan for tomorrow -->
                    <?php if (!empty($r['plan_for_tomorrow'])): ?>
                      <div class="small text-muted" style="font-size: 0.75rem;">
                        <strong>Tomorrow's Plan:</strong> <?= e($r['plan_for_tomorrow']) ?>
                      </div>
                    <?php endif; ?>

                    <!-- Manager Review Box -->
                    <?php if (!empty($r['rating']) || !empty($r['review_note'])): ?>
                      <div class="mt-3 p-2 rounded-2 bg-warning-subtle border border-warning-subtle small">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                          <strong class="text-warning-emphasis"><i class="bi bi-star-fill me-1"></i>Manager Review (<?= $r['rating'] ?>/5)</strong>
                          <span class="text-warning-emphasis"><?= str_repeat('★', (int)$r['rating']) ?></span>
                        </div>
                        <?php if (!empty($r['review_note'])): ?>
                          <div class="text-dark"><?= e($r['review_note']) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="card border-0 shadow-sm p-5 text-center" style="border-radius: 14px;">
            <i class="bi bi-file-earmark-x fs-1 text-muted mb-2"></i>
            <h5 class="fw-bold text-dark">No Daily Work Reports Submitted</h5>
            <p class="text-muted small mb-0">No work reports have been recorded for <?= e($emp['full_name']) ?> in <?= $monthName ?>.</p>
          </div>
        <?php endif; ?>
      </div>

    </div>

  <?php elseif ($activeTab === 'salary_history'): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
      <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-graph-up-arrow me-2 text-warning"></i>Salary Hike & Increment History</h6>
          <small class="text-muted">Track all salary increases, appraisal dates, previous base salaries, and hike percentages over time.</small>
        </div>
        <button class="btn btn-warning btn-sm fw-semibold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#increaseSalaryModal">
          <i class="bi bi-plus-circle me-1"></i> Give Salary Hike / Increase Base
        </button>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light small">
            <tr>
              <th class="ps-3">Effective Date</th>
              <th>Previous Salary</th>
              <th>New Base Salary</th>
              <th>Hike Amount</th>
              <th>% Increase</th>
              <th>Appraisal Notes / Reason</th>
              <th class="text-end pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($salaryHistory): ?>
              <?php foreach ($salaryHistory as $sh): ?>
                <tr>
                  <td class="ps-3 fw-bold text-dark">
                    <i class="bi bi-calendar-event text-primary me-1"></i><?= date('d M Y', strtotime($sh['effective_date'])) ?>
                  </td>
                  <td class="text-muted">₹<?= money($sh['old_salary']) ?></td>
                  <td class="fw-bold text-success fs-6">₹<?= money($sh['new_salary']) ?></td>
                  <td class="fw-bold text-<?= $sh['increment_amount']>=0?'success':'danger' ?>">
                    <?= $sh['increment_amount']>=0?'+':'' ?>₹<?= money($sh['increment_amount']) ?>
                  </td>
                  <td>
                    <span class="badge bg-<?= $sh['percentage_increase']>=0?'success':'danger' ?>-subtle text-<?= $sh['percentage_increase']>=0?'success':'danger' ?> border border-<?= $sh['percentage_increase']>=0?'success':'danger' ?>-subtle px-2 py-1">
                      <i class="bi bi-arrow-up-right me-1"></i><?= $sh['percentage_increase'] ?>%
                    </span>
                  </td>
                  <td>
                    <small class="text-dark"><?= e($sh['reason_notes'] ?: 'Annual Performance Appraisal') ?></small>
                  </td>
                  <td class="text-end pe-3">
                    <form method="post" onsubmit="return confirm('Delete this salary hike record? If this was the latest increment, base salary will revert to previous amount.');" class="d-inline">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="delete_salary_increment">
                      <input type="hidden" name="increment_id" value="<?= $sh['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Log"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="text-center py-5 text-muted">
                  <i class="bi bi-graph-up fs-2 d-block mb-2 text-muted"></i>
                  No salary hikes recorded yet. Current base salary is <strong>₹<?= money($emp['base_salary']) ?></strong>.
                  <button class="btn btn-sm btn-outline-warning ms-2" data-bs-toggle="modal" data-bs-target="#increaseSalaryModal">Log Salary Increase</button>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($activeTab === 'documents'): ?>

    <div class="row g-4">
      <!-- Upload Document Form -->
      <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
          <div class="card-header bg-white py-3">
            <h6 class="fw-bold mb-0"><i class="bi bi-cloud-upload text-primary me-2"></i>Upload Offer Letter / Resume</h6>
          </div>
          <div class="card-body">
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="upload_document">
              
              <div class="mb-3">
                <label class="form-label fw-medium">Document Category <span class="text-danger">*</span></label>
                <select name="doc_type" id="uploadDocType" class="form-select" required>
                  <option value="Offer Letter" selected>📄 Offer Letter</option>
                  <option value="Resume">📑 Resume / CV</option>
                  <option value="Joining Letter">✉️ Joining Letter</option>
                  <option value="ID Proof">🪪 ID Proof (Aadhaar / PAN)</option>
                  <option value="Address Proof">🏠 Address Proof</option>
                  <option value="Certificates">🎓 Certificates</option>
                  <option value="Experience Letter">💼 Experience Letter</option>
                  <option value="Bank Document">🏦 Bank Document</option>
                  <option value="Other">📎 Other Document</option>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label fw-medium">Document Title <span class="text-danger">*</span></label>
                <input type="text" name="doc_name" id="uploadDocTitle" class="form-control" placeholder="e.g. Signed Offer Letter 2026" required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-medium">Select File (PDF, DOC, Image) <span class="text-danger">*</span></label>
                <input type="file" name="doc_file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                <small class="text-muted">Supported formats: PDF, DOC, DOCX, JPG, PNG (Max 10MB)</small>
              </div>

              <button type="submit" class="btn btn-primary w-100 fw-semibold shadow-sm">
                <i class="bi bi-upload me-1"></i> Upload File & Save
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- Document List & Previews -->
      <div class="col-lg-8">
        <!-- Electronic Generated Offer Letter Card -->
        <div class="card border-0 shadow-sm mb-4 bg-primary-subtle border-primary-subtle" style="border-radius: 12px;">
          <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
              <h6 class="fw-bold text-primary mb-1"><i class="bi bi-file-earmark-check me-2"></i>System-Generated Offer Letter</h6>
              <p class="text-muted small mb-0">Official electronic offer letter auto-generated with candidate details, CTC, terms, and signature seal.</p>
            </div>
            <a href="offer_letter_pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-primary fw-semibold shadow-sm">
              <i class="bi bi-printer me-1"></i> View System Offer Letter
            </a>
          </div>
        </div>

        <!-- Uploaded Documents Table -->
        <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
          <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0"><i class="bi bi-folder2-open text-primary me-2"></i>Uploaded Documents & Resumes</h6>
            <small class="text-muted"><?= count($documents) ?> File(s) Saved</small>
          </div>
          <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light small">
                <tr>
                  <th class="ps-3">Document Title</th>
                  <th>Category</th>
                  <th>Uploaded Date</th>
                  <th class="text-end pe-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($documents): ?>
                  <?php foreach ($documents as $doc): 
                    $fileUrl = BASE_URL . e($doc['file_path']);
                    $downloadUrl = BASE_URL . 'download.php?id=' . (int)$doc['id'];
                    $ext = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                  ?>
                    <tr>
                      <td class="ps-3">
                        <span class="fw-bold text-dark d-block"><?= e($doc['doc_name']) ?></span>
                        <small class="text-muted font-monospace"><?= strtoupper($ext) ?> File</small>

                      </td>
                      <td>
                        <span class="badge bg-<?= $doc['doc_type']=='Resume'?'success':($doc['doc_type']=='Offer Letter'?'primary':'info') ?>">
                          <?= e($doc['doc_type']) ?>
                        </span>
                      </td>
                      <td class="small text-muted"><?= date('d M Y, h:i A', strtotime($doc['created_at'])) ?></td>
                      <td class="text-end pe-3">
                        <div class="dropdown">
                          <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-three-dots-vertical"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                              <button type="button" class="dropdown-item" 
                                      onclick="openAdminDocPreview('<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($doc['doc_name']), ENT_QUOTES) ?>', '<?= $ext ?>', '<?= htmlspecialchars($downloadUrl, ENT_QUOTES) ?>')">
                                <i class="bi bi-eye text-primary me-2"></i> Preview Document
                              </button>
                            </li>
                            <li>
                              <a href="<?= $downloadUrl ?>" class="dropdown-item">
                                <i class="bi bi-download text-success me-2"></i> Download File
                              </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                              <form method="post" onsubmit="return confirm('Delete this uploaded document?');" class="m-0">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="delete_document">
                                <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                <button type="submit" class="dropdown-item text-danger">
                                  <i class="bi bi-trash me-2"></i> Delete Document
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
                    <td colspan="4" class="text-center py-5 text-muted">
                      <i class="bi bi-folder-x fs-2 text-muted d-block mb-2"></i>
                      No Offer Letters or Resumes uploaded yet. Use the upload box on the left to add candidate files.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($activeTab === 'skills'): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Skills Matrix</h6>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addSkillModal"><i class="bi bi-plus-circle me-1"></i> Add Skill</button>
      </div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($skills as $sk): ?>
            <span class="badge bg-light text-dark border p-2 fs-6">
              <?= e($sk['skill_name']) ?> &bull; <small class="text-primary"><?= e($sk['skill_level']) ?></small>
            </span>
          <?php endforeach; ?>
          <?php if (!$skills): ?><p class="text-muted mb-0">No skills logged yet.</p><?php endif; ?>
        </div>
      </div>
    </div>

  <?php elseif ($activeTab === 'advances'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Salary Advances Logged</h6>
        <a href="salary_advances.php?employee_id=<?= $id ?>" class="btn btn-sm btn-warning"><i class="bi bi-plus-circle me-1"></i> Request Advance</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Date</th><th>Amount</th><th>Reason</th><th>Payment Method</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($advances as $adv): ?>
              <tr>
                <td><?= e($adv['request_date']) ?></td>
                <td class="fw-bold text-danger">₹<?= money($adv['amount']) ?></td>
                <td><?= e($adv['reason']) ?></td>
                <td><?= e($adv['payment_method']) ?></td>
                <td><span class="badge bg-<?= $adv['status']=='Deducted'?'secondary':($adv['status']=='Paid'?'success':'warning') ?>"><?= e($adv['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$advances): ?><tr><td colspan="5" class="text-center py-4 text-muted">No salary advances requested.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($activeTab === 'payroll'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Month</th><th>Base Salary</th><th>Advance Deducted</th><th>Other Deductions</th><th>Net Paid</th><th>Status</th><th>Slip</th></tr>
          </thead>
          <tbody>
            <?php foreach ($payrolls as $p): ?>
              <tr>
                <td class="fw-bold"><?= date("F Y", mktime(0,0,0,$p['payroll_month'],1,$p['payroll_year'])) ?></td>
                <td>₹<?= money($p['base_salary']) ?></td>
                <td class="text-danger">₹<?= money($p['advance_deducted']) ?></td>
                <td class="text-danger">₹<?= money($p['late_deduction'] + $p['other_deduction']) ?></td>
                <td class="fw-bold text-success">₹<?= money($p['net_salary']) ?></td>
                <td><span class="badge bg-<?= $p['status']=='Paid'?'success':'warning' ?>"><?= e($p['status']) ?></span></td>
                <td>
                  <a href="salary_slip_pdf.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-pdf"></i> Slip</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$payrolls): ?><tr><td colspan="7" class="text-center py-4 text-muted">No payroll statements generated yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- Tab: Password Change History & Devices -->
  <?php if ($activeTab === 'password_history'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-lock-fill text-primary me-2"></i>Password Change History & Device Logs</h6>
          <small class="text-muted">Audit trail of all previous passwords generated or changed, including timestamp and client device name.</small>
        </div>
        <button class="btn btn-sm btn-primary fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#resetPasswordModal">
          <i class="bi bi-key-fill me-1"></i> Reset / Regenerate Password
        </button>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light text-muted small text-uppercase">
              <tr>
                <th class="ps-3">Date & Time</th>
                <th>Password</th>
                <th>Device & Operating System</th>
                <th>IP Address</th>
                <th>Changed By</th>
                <th>Action Type</th>
                <th class="text-end pe-3">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($pwdLogs)): ?>
                <?php foreach ($pwdLogs as $idx => $pl): ?>
                  <tr>
                    <td class="ps-3 fw-semibold text-dark">
                      <?= date('d M Y, h:i A', strtotime($pl['created_at'])) ?>
                      <?php if ($idx === 0): ?>
                        <span class="badge bg-success ms-1">Active / Latest</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <code class="fw-bold fs-6 text-dark font-monospace"><?= htmlspecialchars($pl['password_plain']) ?></code>
                    </td>
                    <td>
                      <span class="badge bg-light text-dark border px-2 py-1">
                        <i class="bi bi-laptop me-1 text-primary"></i><?= htmlspecialchars($pl['device_name'] ?: 'Unknown') ?>
                      </span>
                    </td>
                    <td>
                      <small class="text-muted font-monospace"><?= htmlspecialchars($pl['ip_address'] ?: '—') ?></small>
                    </td>
                    <td>
                      <span class="badge bg-info bg-opacity-10 text-info border border-info-subtle">
                        <?= htmlspecialchars($pl['changed_by_name'] ?: 'Admin') ?>
                      </span>
                    </td>
                    <td>
                      <small class="text-muted"><?= htmlspecialchars($pl['notes'] ?: 'Password Update') ?></small>
                    </td>
                    <td class="text-end pe-3">
                      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText('<?= addslashes($pl['password_plain']) ?>'); alert('Password copied: <?= addslashes($pl['password_plain']) ?>');" title="Copy Password">
                        <i class="bi bi-clipboard"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="text-center py-5 text-muted">
                    <i class="bi bi-shield-x fs-1 d-block mb-2 text-secondary"></i>
                    No previous password logs found for this employee yet.<br>
                    <button class="btn btn-sm btn-outline-primary mt-2" data-bs-toggle="modal" data-bs-target="#resetPasswordModal">
                      <i class="bi bi-key-fill me-1"></i> Generate Password Now
                    </button>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Modal: Edit Profile -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit_profile">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Employee Profile</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="p-3 bg-light rounded-3 border d-flex align-items-center gap-3">
                <div>
                  <?= get_employee_avatar_html($emp, 48) ?>
                </div>
                <div class="flex-grow-1">
                  <label class="form-label fw-medium mb-1"><i class="bi bi-person-bounding-box text-primary me-1"></i>Employee Profile Photo / Image</label>
                  <input type="file" name="profile_photo" class="form-control" accept="image/*">
                  <small class="text-muted" style="font-size:0.75rem;">Upload PNG, JPG, WEBP or SVG photo to update employee profile avatar.</small>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Employee Code <span class="text-danger">*</span></label>
              <input type="text" name="employee_code" class="form-control" value="<?= e($emp['employee_code']) ?>" required>
            </div>

            <div class="col-md-8">
              <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control" value="<?= e($emp['full_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" value="<?= e($emp['email']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Phone Number <span class="text-danger">*</span></label>
              <input type="text" name="phone" class="form-control" value="<?= e($emp['phone']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Department</label>
              <input type="text" name="department" class="form-control" value="<?= e($emp['department']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Designation</label>
              <input type="text" name="designation" class="form-control" value="<?= e($emp['designation']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Joining Date</label>
              <input type="date" name="joining_date" class="form-control" value="<?= e($emp['joining_date']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Employee Type</label>
              <select name="employee_type" class="form-select">
                <option value="Full Time" <?= $emp['employee_type']=='Full Time'?'selected':'' ?>>Full Time</option>
                <option value="Part Time" <?= $emp['employee_type']=='Part Time'?'selected':'' ?>>Part Time</option>
                <option value="Intern" <?= $emp['employee_type']=='Intern'?'selected':'' ?>>Intern</option>
                <option value="Freelancer" <?= $emp['employee_type']=='Freelancer'?'selected':'' ?>>Freelancer</option>
                <option value="Contract" <?= $emp['employee_type']=='Contract'?'selected':'' ?>>Contract</option>
                <option value="Consultant" <?= $emp['employee_type']=='Consultant'?'selected':'' ?>>Consultant</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Salary Type</label>
              <select name="salary_type" class="form-select">
                <option value="Monthly" <?= $emp['salary_type']=='Monthly'?'selected':'' ?>>Monthly</option>
                <option value="Daily" <?= $emp['salary_type']=='Daily'?'selected':'' ?>>Daily</option>
                <option value="Hourly" <?= $emp['salary_type']=='Hourly'?'selected':'' ?>>Hourly</option>
                <option value="Project Based" <?= $emp['salary_type']=='Project Based'?'selected':'' ?>>Project Based</option>
                <option value="Stipend" <?= $emp['salary_type']=='Stipend'?'selected':'' ?>>Stipend</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold text-success">Monthly Base Salary (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="base_salary" class="form-control fw-bold text-success fs-6" value="<?= $emp['base_salary'] ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Bank Name</label>
              <input type="text" name="bank_name" class="form-control" value="<?= e($emp['bank_name']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Account Number</label>
              <input type="text" name="bank_acc_no" class="form-control" value="<?= e($emp['bank_acc_no']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">IFSC Code</label>
              <input type="text" name="bank_ifsc" class="form-control" value="<?= e($emp['bank_ifsc']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">PAN Number</label>
              <input type="text" name="pan" class="form-control" value="<?= e($emp['pan']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Status</label>
              <select name="status" class="form-select">
                <option value="active" <?= $emp['status']=='active'?'selected':'' ?>>Active</option>
                <option value="on_leave" <?= $emp['status']=='on_leave'?'selected':'' ?>>On Leave</option>
                <option value="terminated" <?= $emp['status']=='terminated'?'selected':'' ?>>Terminated</option>
                <option value="resigned" <?= $emp['status']=='resigned'?'selected':'' ?>>Resigned</option>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label fw-medium">Residential Address</label>
              <textarea name="address" class="form-control" rows="2"><?= e($emp['address']) ?></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Update Employee Profile</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Add Skill -->
<div class="modal fade" id="addSkillModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_skill">
        <div class="modal-header border-bottom-0">
          <h5 class="modal-title fw-bold">Add Skill</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Skill Name</label>
            <input type="text" name="skill_name" class="form-control" placeholder="e.g. PHP, Figma, SEO" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Proficiency Level</label>
            <select name="skill_level" class="form-select">
              <option value="Beginner">Beginner</option>
              <option value="Intermediate" selected>Intermediate</option>
              <option value="Advanced">Advanced</option>
              <option value="Expert">Expert</option>
            </select>
          </div>
        </div>
        <div class="modal-footer border-top-0">
          <button type="submit" class="btn btn-primary fw-semibold">Save Skill</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Increase Salary / Hike -->
<div class="modal fade" id="increaseSalaryModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="increase_salary">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-graph-up-arrow text-warning me-2"></i>Give Salary Hike / Increase Base</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="p-3 bg-light rounded-3 mb-3 border">
            <div class="d-flex justify-content-between align-items-center">
              <span class="text-muted small">Current Monthly Base Salary:</span>
              <span class="fw-bold text-dark fs-6" id="curSalaryVal">₹<?= money($emp['base_salary']) ?></span>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-medium">New Monthly Base Salary (₹) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="new_salary" id="newSalaryInput" class="form-control form-control-lg fw-bold text-success" placeholder="e.g. 45000" required>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-medium">Increment Amount (₹)</label>
              <input type="number" step="0.01" id="hikeAmountInput" class="form-control form-control-sm" placeholder="+0.00">
            </div>
            <div class="col-6">
              <label class="form-label small fw-medium">Hike Percentage (%)</label>
              <input type="number" step="0.1" id="hikePercentInput" class="form-control form-control-sm" placeholder="+0.0%">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-medium">Effective Date <span class="text-danger">*</span></label>
            <input type="date" name="effective_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label fw-medium">Appraisal Notes / Reason</label>
            <textarea name="reason_notes" class="form-control" rows="2" placeholder="e.g. Annual appraisal, performance promotion, market correction..."></textarea>
          </div>

          <div class="form-check text-muted small">
            <input type="checkbox" name="confirm_decrease" value="1" class="form-check-input" id="confDecr">
            <label class="form-check-label" for="confDecr">Allow salary decrease/reduction if new salary is less than current.</label>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning fw-semibold text-dark shadow-sm"><i class="bi bi-check-circle me-1"></i> Apply Salary Hike</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Pay / Disburse Salary -->
<div class="modal fade" id="paySalaryModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="process_quick_payroll">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-cash-coin text-success me-2"></i>Pay / Disburse Employee Salary</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-medium">Payroll Month <span class="text-danger">*</span></label>
              <select name="payroll_month" class="form-select">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                  <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>><?= date("F", mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Payroll Year <span class="text-danger">*</span></label>
              <select name="payroll_year" class="form-select">
                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                  <option value="<?= $y ?>"><?= $y ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Base Salary (₹)</label>
              <input type="number" step="0.01" name="base_salary" id="payBaseSalary" class="form-control" value="<?= $emp['base_salary'] ?>" required oninput="calcNetSalary()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Allowances (₹)</label>
              <input type="number" step="0.01" name="allowances" id="payAllowances" class="form-control" value="0" oninput="calcNetSalary()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Performance Bonus (₹)</label>
              <input type="number" step="0.01" name="bonus" id="payBonus" class="form-control" value="0" oninput="calcNetSalary()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Advance Deducted (₹) <?= $empPendingAdv > 0 ? '<span class="badge bg-warning text-dark ms-1">Pending: ₹'.money($empPendingAdv).'</span>' : '' ?></label>
              <input type="number" step="0.01" name="advance_deducted" id="payAdvDeduction" class="form-control" value="<?= $empPendingAdv ?>" oninput="calcNetSalary()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Late/Leave Deduction (₹)</label>
              <input type="number" step="0.01" name="late_deduction" id="payLateDeduction" class="form-control" value="0" oninput="calcNetSalary()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Other Deductions (₹)</label>
              <input type="number" step="0.01" name="other_deduction" id="payOtherDeduction" class="form-control" value="0" oninput="calcNetSalary()">
            </div>

            <div class="col-12">
              <div class="p-3 bg-success-subtle rounded-3 border border-success-subtle d-flex justify-content-between align-items-center">
                <span class="fw-bold text-success-emphasis fs-6">Calculated Net Salary Payable:</span>
                <span class="fw-bold fs-4 text-success" id="payNetSalaryDisplay">₹<?= money(max(0, $emp['base_salary'] - $empPendingAdv)) ?></span>
              </div>
            </div>


            <div class="col-md-4">
              <label class="form-label fw-medium">Payment Date</label>
              <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Payment Method</label>
              <select name="payment_method" class="form-select">
                <option value="Bank Transfer" selected>Bank Transfer (NEFT/IMPS)</option>
                <option value="UPI">UPI Payment</option>
                <option value="Cash">Cash</option>
                <option value="Cheque">Cheque</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Transaction Ref No</label>
              <input type="text" name="transaction_ref" class="form-control" placeholder="e.g. UTR123456789">
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-semibold shadow-sm"><i class="bi bi-file-pdf me-1"></i> Disburse Salary & Generate Slip</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Send Offer Letter -->
<div class="modal fade" id="sendOfferLetterModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-header border-bottom-0 pb-0">
        <h5 class="modal-title fw-bold text-dark"><i class="bi bi-send text-primary me-2"></i>Send / Share Offer Letter</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php
          $offerLetterUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . BASE_URL . 'modules/team/offer_letter_pdf.php?id=' . $id;
          $waMsg = urlencode("Hello " . $emp['full_name'] . ",\nHere is your official Offer Letter from " . ($settings['company_name'] ?? 'Vibesoft') . ":\n" . $offerLetterUrl);
          $emailSubject = urlencode("Official Offer Letter - " . ($settings['company_name'] ?? 'Vibesoft'));
          $emailBody = urlencode("Dear " . $emp['full_name'] . ",\n\nWe are pleased to offer you the position of " . ($emp['designation'] ?: 'Team Member') . " at " . ($settings['company_name'] ?? 'Vibesoft') . ".\n\nPlease find your official offer letter link below:\n" . $offerLetterUrl . "\n\nBest regards,\nHR Team");
        ?>
        <div class="p-3 bg-light rounded-3 mb-3 border">
          <label class="form-label small text-muted font-monospace uppercase mb-1">Direct Offer Letter URL:</label>
          <div class="input-group">
            <input type="text" class="form-control form-control-sm font-monospace" id="offerLetterUrlInput" value="<?= e($offerLetterUrl) ?>" readonly>
            <button class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('offerLetterUrlInput').value); alert('Offer letter link copied to clipboard!');"><i class="bi bi-copy"></i> Copy</button>
          </div>
        </div>

        <div class="d-grid gap-2 mb-4">
          <a href="<?= e($offerLetterUrl) ?>" target="_blank" class="btn btn-outline-primary fw-semibold"><i class="bi bi-printer me-2"></i> View & Print Offer Letter PDF</a>
          <a href="https://api.whatsapp.com/send?phone=<?= preg_replace('/[^0-9]/', '', $emp['phone']) ?>&text=<?= $waMsg ?>" target="_blank" class="btn btn-success fw-semibold"><i class="bi bi-whatsapp me-2"></i> Send via WhatsApp</a>
          <a href="mailto:<?= e($emp['email']) ?>?subject=<?= $emailSubject ?>&body=<?= $emailBody ?>" class="btn btn-info text-white fw-semibold"><i class="bi bi-envelope me-2"></i> Send via Email Client</a>
        </div>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="update_offer_letter_status">
          <div class="border-top pt-3">
            <label class="form-label fw-medium">Update Offer Letter Status</label>
            <div class="input-group">
              <select name="offer_letter_status" class="form-select">
                <option value="Generated" <?= ($emp['offer_letter_status']??'')=='Generated'?'selected':'' ?>>Generated</option>
                <option value="Sent" <?= ($emp['offer_letter_status']??'')=='Sent'?'selected':'' ?>>Sent to Candidate</option>
                <option value="Signed" <?= ($emp['offer_letter_status']??'')=='Signed'?'selected':'' ?>>Signed & Accepted</option>
                <option value="Accepted" <?= ($emp['offer_letter_status']??'')=='Accepted'?'selected':'' ?>>Accepted</option>
              </select>
              <input type="date" name="offer_letter_date" class="form-control" value="<?= $emp['offer_letter_date'] ?: date('Y-m-d') ?>">
              <button type="submit" class="btn btn-primary">Update</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const baseSalary = <?= (float)$emp['base_salary'] ?>;
  const newSalaryInp = document.getElementById('newSalaryInput');
  const hikeAmtInp = document.getElementById('hikeAmountInput');
  const hikePctInp = document.getElementById('hikePercentInput');

  if (newSalaryInp) {
    newSalaryInp.addEventListener('input', function() {
      const val = parseFloat(this.value) || 0;
      if (val > 0 && baseSalary > 0) {
        const diff = val - baseSalary;
        const pct = (diff / baseSalary) * 100;
        hikeAmtInp.value = diff.toFixed(2);
        hikePctInp.value = pct.toFixed(1);
      }
    });

    hikeAmtInp.addEventListener('input', function() {
      const amt = parseFloat(this.value) || 0;
      const newSal = baseSalary + amt;
      newSalaryInp.value = newSal.toFixed(2);
      const pct = baseSalary > 0 ? (amt / baseSalary) * 100 : 0;
      hikePctInp.value = pct.toFixed(1);
    });

    hikePctInp.addEventListener('input', function() {
      const pct = parseFloat(this.value) || 0;
      const amt = (baseSalary * pct) / 100;
      const newSal = baseSalary + amt;
      hikeAmtInp.value = amt.toFixed(2);
      newSalaryInp.value = newSal.toFixed(2);
    });
  }
});

function calcNetSalary() {
  const base = parseFloat(document.getElementById('payBaseSalary')?.value) || 0;
  const allow = parseFloat(document.getElementById('payAllowances')?.value) || 0;
  const bonus = parseFloat(document.getElementById('payBonus')?.value) || 0;
  const adv = parseFloat(document.getElementById('payAdvDeduction')?.value) || 0;
  const late = parseFloat(document.getElementById('payLateDeduction')?.value) || 0;
  const oth = parseFloat(document.getElementById('payOtherDeduction')?.value) || 0;

  const gross = base + allow + bonus;
  const ded = adv + late + oth;
  const net = Math.max(0, gross - ded);

  const disp = document.getElementById('payNetSalaryDisplay');
  if (disp) {
    disp.innerText = '₹' + net.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
}

function generateRandomPassword(elementId) {
  const prefixes = ['Emp', 'Pass', 'Vibe', 'User', 'Work'];
  const pfx = prefixes[Math.floor(Math.random() * prefixes.length)];
  const num = Math.floor(1000 + Math.random() * 9000);
  const special = ['@', '#', '$', '!'][Math.floor(Math.random() * 4)];
  const pwd = pfx + special + num;
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

// Auto populate profile modal with an initial strong password on show
const resetModalEl = document.getElementById('resetPasswordModal');
if (resetModalEl) {
  resetModalEl.addEventListener('show.bs.modal', function() {
    generateRandomPassword('profile_reset_new_password');
  });
}
</script>

<!-- Modal: Reset / Regenerate Password -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post" action="">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="reset_password">

        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-key-fill text-warning me-2"></i>Reset Employee Password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body p-4">
          <!-- Employee Details Card -->
          <div class="bg-light p-3 rounded-3 mb-3 border">
            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($emp['full_name']) ?></div>
            <div class="text-muted small"><?= htmlspecialchars($emp['employee_code']) ?> · <?= htmlspecialchars($emp['email'] ?: 'No email on file') ?></div>
          </div>

          <!-- Password Field with Generator Button -->
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label class="form-label small fw-semibold mb-0">New Password <span class="text-danger">*</span></label>
              <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small fw-semibold" onclick="generateRandomPassword('profile_reset_new_password')">
                <i class="bi bi-magic me-1 text-primary"></i>Auto-Generate
              </button>
            </div>
            <div class="input-group">
              <input type="text" name="new_password" id="profile_reset_new_password" class="form-control font-monospace fw-bold" placeholder="Type or click Auto-Generate" required>
              <button class="btn btn-outline-secondary" type="button" onclick="generateRandomPassword('profile_reset_new_password')" title="Generate another password">
                <i class="bi bi-arrow-repeat"></i>
              </button>
            </div>
            <div class="form-text small" style="font-size:0.72rem;">Admin can manually enter any password or click <em>Auto-Generate</em> for a secure random password.</div>
          </div>

          <!-- Send Email Checkbox -->
          <div class="form-check bg-light p-3 rounded-2 border">
            <input class="form-check-input" type="checkbox" name="send_email" value="1" id="profile_reset_send_email" checked>
            <label class="form-check-label small fw-semibold" for="profile_reset_send_email">
              <i class="bi bi-envelope-check text-primary me-1"></i> Send new credentials to employee via Email
            </label>
            <div class="text-muted small ms-4" style="font-size:0.72rem;">If enabled and SMTP is configured, the employee will receive an official reset notice with their new login details.</div>
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

<!-- Modal: View & Review Daily Work Report -->
<div class="modal fade" id="arReportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post" action="?id=<?= $id ?>&tab=attendance_reports&month=<?= $arMonth ?>&year=<?= $arYear ?>">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="review_daily_report">
        <input type="hidden" name="report_id" id="modalReportId" value="">
        <input type="hidden" name="month" value="<?= $arMonth ?>">
        <input type="hidden" name="year" value="<?= $arYear ?>">

        <div class="modal-header border-bottom-0 pb-0">
          <div>
            <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
              <i class="bi bi-file-earmark-text text-primary"></i> Daily Work Report
              <span id="modalReportMood" class="fs-5"></span>
            </h5>
            <div class="text-muted small">
              Employee: <strong id="modalReportEmpName"><?= e($emp['full_name']) ?></strong> &bull; Date: <strong id="modalReportDate">—</strong>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body p-4">
          <!-- Quick Stats Bar -->
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-light text-dark border p-2"><i class="bi bi-clock me-1 text-primary"></i> Hours Worked: <strong id="modalReportHours">0</strong> hrs</span>
            <span class="badge bg-light text-dark border p-2" id="modalSubmittedBadge"><i class="bi bi-calendar-check me-1 text-success"></i> Submitted: <span id="modalReportSubmitted">—</span></span>
          </div>

          <!-- Morning Plan -->
          <div class="mb-3">
            <label class="form-label small fw-bold text-uppercase text-muted mb-1"><i class="bi bi-sun text-warning me-1"></i> Morning Plan / Tasks</label>
            <div id="modalReportMorning" class="p-3 bg-light rounded-3 border small text-dark" style="min-height: 50px; white-space: pre-wrap;">—</div>
          </div>

          <!-- Evening Accomplishments -->
          <div class="mb-3">
            <label class="form-label small fw-bold text-uppercase text-muted mb-1"><i class="bi bi-check-circle text-success me-1"></i> Evening Work Done / Tasks Accomplished</label>
            <div id="modalReportEvening" class="p-3 bg-light rounded-3 border small text-dark" style="min-height: 60px; white-space: pre-wrap;">—</div>
          </div>

          <div class="row g-3 mb-3">
            <!-- Projects & Clients -->
            <div class="col-md-6">
              <label class="form-label small fw-bold text-uppercase text-muted mb-1"><i class="bi bi-folder2-open text-primary me-1"></i> Projects & Clients</label>
              <div id="modalReportProjects" class="p-2 bg-light rounded-2 border small text-dark">—</div>
            </div>

            <!-- Blockers -->
            <div class="col-md-6">
              <label class="form-label small fw-bold text-uppercase text-muted mb-1"><i class="bi bi-exclamation-triangle text-danger me-1"></i> Blockers / Challenges</label>
              <div id="modalReportBlockers" class="p-2 bg-light rounded-2 border small text-danger">—</div>
            </div>
          </div>

          <!-- Tomorrow Plan -->
          <div class="mb-4">
            <label class="form-label small fw-bold text-uppercase text-muted mb-1"><i class="bi bi-arrow-right-circle text-info me-1"></i> Plan for Tomorrow</label>
            <div id="modalReportTomorrow" class="p-2 bg-light rounded-2 border small text-dark">—</div>
          </div>

          <!-- Admin Review & Rating Form -->
          <div class="card border border-warning-subtle bg-warning bg-opacity-10" style="border-radius: 12px;">
            <div class="card-body p-3">
              <h6 class="fw-bold text-dark mb-2"><i class="bi bi-star-half text-warning me-1"></i> Admin Performance Review & Rating</h6>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Manager Rating (1 - 5 Stars)</label>
                  <select name="rating" id="modalReviewRating" class="form-select form-select-sm fw-bold">
                    <option value="">No Rating</option>
                    <option value="5">★★★★★ 5 Stars (Outstanding)</option>
                    <option value="4">★★★★☆ 4 Stars (Good)</option>
                    <option value="3">★★★☆☆ 3 Stars (Average)</option>
                    <option value="2">★★☆☆☆ 2 Stars (Below Expectation)</option>
                    <option value="1">★☆☆☆☆ 1 Star (Unsatisfactory)</option>
                  </select>
                </div>
                <div class="col-md-8">
                  <label class="form-label small fw-semibold">Review Feedback / Notes</label>
                  <input type="text" name="review_note" id="modalReviewNote" class="form-control form-control-sm" placeholder="e.g. Great progress on the UI module!">
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer border-top-0 pt-0 d-flex justify-content-between">
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary btn-sm fw-semibold px-3 shadow-sm">
            <i class="bi bi-check-lg me-1"></i> Save Review & Rating
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function setArView(view, btn) {
  document.querySelectorAll('.ar-view-toggle').forEach(b => {
    b.classList.remove('btn-primary', 'active');
    b.classList.add('btn-outline-primary');
  });
  if (btn) {
    btn.classList.add('btn-primary', 'active');
    btn.classList.remove('btn-outline-primary');
  }

  const allContainer = document.getElementById('arViewAllContainer');
  const attContainer = document.getElementById('arViewAttContainer');
  const repContainer = document.getElementById('arViewRepContainer');

  if (view === 'all') {
    if (allContainer) allContainer.classList.remove('d-none');
    if (attContainer) attContainer.classList.add('d-none');
    if (repContainer) repContainer.classList.add('d-none');
  } else if (view === 'att') {
    if (allContainer) allContainer.classList.add('d-none');
    if (attContainer) attContainer.classList.remove('d-none');
    if (repContainer) repContainer.classList.add('d-none');
  } else if (view === 'rep') {
    if (allContainer) allContainer.classList.add('d-none');
    if (attContainer) attContainer.classList.add('d-none');
    if (repContainer) repContainer.classList.remove('d-none');
  }
}

function filterArTable() {
  const statusFilter = document.getElementById('arStatusFilter')?.value || 'all';
  const searchVal = (document.getElementById('arSearchInput')?.value || '').toLowerCase().trim();
  const rows = document.querySelectorAll('.ar-row');

  rows.forEach(r => {
    const rStatus = r.getAttribute('data-status') || '';
    const hasReport = r.getAttribute('data-has-report') === '1';
    const isMissing = r.getAttribute('data-missing-report') === '1';
    const searchText = r.getAttribute('data-search') || '';

    let matchStatus = true;
    if (statusFilter === 'all') {
      matchStatus = true;
    } else if (statusFilter === 'has_report') {
      matchStatus = hasReport;
    } else if (statusFilter === 'missing_report') {
      matchStatus = isMissing;
    } else {
      matchStatus = (rStatus === statusFilter);
    }

    const matchSearch = !searchVal || searchText.includes(searchVal);

    if (matchStatus && matchSearch) {
      r.style.display = '';
    } else {
      r.style.display = 'none';
    }
  });
}

// Monthly Reports Data for Modal Popup
const monthlyReportsData = <?= json_encode($reportsByDate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || {};
const employeeFullName = <?= json_encode($emp['full_name']) ?>;

function openReportModal(dateStr) {
  const rep = monthlyReportsData ? monthlyReportsData[dateStr] : null;
  if (!rep) {
    alert('No report data found for date: ' + dateStr);
    return;
  }

  const setTxt = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  };
  const setVal = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.value = val;
  };

  setVal('modalReportId', rep.id || '');
  setTxt('modalReportEmpName', employeeFullName || '');
  setTxt('modalReportDate', rep.report_date || dateStr);
  setTxt('modalReportHours', rep.hours_worked || '0');
  setTxt('modalReportSubmitted', rep.submitted_at || 'Recorded');
  setTxt('modalReportMorning', rep.morning_tasks || 'None logged');
  setTxt('modalReportEvening', rep.evening_tasks || 'None logged');
  setTxt('modalReportProjects', (rep.projects_worked || '') + (rep.clients_worked ? ' (Clients: ' + rep.clients_worked + ')' : '') || 'None logged');
  setTxt('modalReportBlockers', rep.blockers || 'None (No blockers reported)');
  setTxt('modalReportTomorrow', rep.plan_for_tomorrow || 'None logged');

  const moods = {'great':'😄 Great','good':'🙂 Good','okay':'😐 Okay','tired':'😴 Tired','stressed':'😰 Stressed'};
  setTxt('modalReportMood', moods[rep.mood] || '📝');

  setVal('modalReviewRating', rep.rating || '');
  setVal('modalReviewNote', rep.review_note || '');

  const modalEl = document.getElementById('arReportModal');
  if (modalEl) {
    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } else if (window.jQuery && $(modalEl).modal) {
      $(modalEl).modal('show');
    }
  }
}

// Global Universal Document Preview Modal Handler for Admin
function openAdminDocPreview(url, title, ext, downloadUrl) {
  if (!url) return;
  ext = (ext || '').toLowerCase().trim();
  downloadUrl = downloadUrl || url;
  title = title || 'Document';
  
  // Format categorization
  const imgExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp', 'ico'];
  const officeWord = ['doc', 'docx'];
  const officeExcel = ['xls', 'xlsx'];
  const officePpt = ['ppt', 'pptx'];
  const textExts = ['txt', 'log', 'json', 'xml', 'sql', 'md', 'html', 'js', 'css'];
  
  // Choose header icon
  let iconClass = 'bi-file-earmark-text text-secondary';
  if (imgExts.includes(ext)) iconClass = 'bi-file-earmark-image text-success';
  else if (ext === 'pdf') iconClass = 'bi-file-earmark-pdf text-danger';
  else if (officeWord.includes(ext)) iconClass = 'bi-file-earmark-word text-primary';
  else if (officeExcel.includes(ext)) iconClass = 'bi-file-earmark-excel text-success';
  else if (officePpt.includes(ext)) iconClass = 'bi-file-earmark-ppt text-warning';
  else if (textExts.includes(ext) || ext === 'csv') iconClass = 'bi-file-earmark-code text-info';

  const titleEl = document.getElementById('previewDocModalTitle');
  if (titleEl) {
    titleEl.innerHTML = `<i class="bi ${iconClass} me-2"></i> ${title} <span class="badge bg-light text-muted border ms-2 uppercase font-monospace">.${ext.toUpperCase()}</span>`;
  }
  const dlBtn = document.getElementById('previewDownloadBtn');
  if (dlBtn) {
    dlBtn.setAttribute('href', downloadUrl);
    dlBtn.setAttribute('download', (title ? title.replace(/[^a-zA-Z0-9_-]/g, '_') : 'document') + '.' + ext);
  }

  const container = document.getElementById('previewContainer');
  if (container) {
    container.innerHTML = '<div class="py-5 text-center"><div class="spinner-border text-primary" role="status"></div><div class="mt-2 text-muted small">Loading document preview...</div></div>';

    if (imgExts.includes(ext)) {
      // 1. High-Res Image Preview with Zoom Controls
      container.innerHTML = `
        <div class="d-flex justify-content-end gap-2 mb-2 p-2 bg-white rounded border">
          <button class="btn btn-sm btn-outline-secondary" onclick="zoomAdminImg(0.2)"><i class="bi bi-zoom-in"></i></button>
          <button class="btn btn-sm btn-outline-secondary" onclick="zoomAdminImg(-0.2)"><i class="bi bi-zoom-out"></i></button>
          <button class="btn btn-sm btn-outline-secondary" onclick="resetAdminImg()"><i class="bi bi-arrows-fullscreen"></i> Reset</button>
        </div>
        <div style="overflow: auto; max-height: 70vh; background: #0f172a;" class="rounded p-3 text-center">
          <img id="adminDocPreviewImg" src="${url}" class="img-fluid rounded shadow-sm" style="max-height: 65vh; object-fit: contain; transition: transform 0.2s ease;" alt="${title}">
        </div>`;
    } else if (ext === 'pdf') {
      // 2. PDF Viewer
      container.innerHTML = `
        <div class="h-100">
          <iframe src="${url}#toolbar=1" style="width: 100%; height: 75vh; border: none; border-radius: 8px;"></iframe>
        </div>`;
    } else if (textExts.includes(ext)) {
      // 3. Text / Code Viewer
      fetch(url)
        .then(res => res.text())
        .then(text => {
          container.innerHTML = `
            <div class="text-start bg-dark text-light p-3 rounded" style="max-height: 72vh; overflow: auto; font-family: monospace; font-size: 13px; line-height: 1.6;">
              <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-secondary">
                <span class="text-secondary small font-monospace">${title} (${ext.toUpperCase()})</span>
                <button class="btn btn-sm btn-outline-light py-0 px-2" onclick="navigator.clipboard.writeText(document.getElementById('adminTextPreviewBox').innerText); alert('Text copied to clipboard!');"><i class="bi bi-clipboard me-1"></i> Copy</button>
              </div>
              <pre id="adminTextPreviewBox" style="margin: 0; white-space: pre-wrap; word-break: break-all;">${text.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre>
            </div>`;
        })
        .catch(() => {
          renderOfficeCard(container, title, ext, url, downloadUrl);
        });
    } else if (ext === 'csv') {
      // 4. CSV Interactive Table
      fetch(url)
        .then(res => res.text())
        .then(csvText => {
          const lines = csvText.trim().split('\n').filter(l => l.trim().length > 0);
          if (lines.length === 0) {
            container.innerHTML = '<div class="alert alert-info my-4">CSV file is empty.</div>';
            return;
          }
          let html = '<div class="table-responsive bg-white rounded border" style="max-height: 72vh;"><table class="table table-sm table-striped table-hover mb-0 text-start small">';
          lines.slice(0, 50).forEach((line, idx) => {
            const cols = line.split(',');
            if (idx === 0) {
              html += '<thead class="table-dark sticky-top"><tr><th style="width:40px;">#</th>' + cols.map(c => `<th>${c.replace(/["\r]/g, '')}</th>`).join('') + '</tr></thead><tbody>';
            } else {
              html += `<tr><td class="text-muted">${idx}</td>` + cols.map(c => `<td>${c.replace(/["\r]/g, '')}</td>`).join('') + '</tr>';
            }
          });
          html += '</tbody></table></div>';
          if (lines.length > 50) {
            html += `<div class="p-2 text-muted small bg-light border-top">Showing first 50 of ${lines.length} rows. Download full CSV to view all rows.</div>`;
          }
          container.innerHTML = html;
        })
        .catch(() => {
          renderOfficeCard(container, title, ext, url, downloadUrl);
        });
    } else if (officeWord.includes(ext) || officeExcel.includes(ext) || officePpt.includes(ext)) {
      // 5. Office Documents (Word / Excel / PPT)
      renderOfficeCard(container, title, ext, url, downloadUrl);
    } else {
      // 6. Generic Formats
      renderOfficeCard(container, title, ext, url, downloadUrl);
    }
  }

  const modalEl = document.getElementById('previewDocModal');
  if (modalEl) {
    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } else if (window.jQuery && $(modalEl).modal) {
      $(modalEl).modal('show');
    }
  }
}

let adminImgZoom = 1;
function zoomAdminImg(delta) {
  const img = document.getElementById('adminDocPreviewImg');
  if (img) {
    adminImgZoom = Math.max(0.4, Math.min(3.5, adminImgZoom + delta));
    img.style.transform = `scale(${adminImgZoom})`;
  }
}
function resetAdminImg() {
  const img = document.getElementById('adminDocPreviewImg');
  if (img) {
    adminImgZoom = 1;
    img.style.transform = 'scale(1)';
  }
}

function renderOfficeCard(container, title, ext, url, downloadUrl) {
  const isOnline = window.location.protocol.startsWith('http') && !['localhost', '127.0.0.1'].includes(window.location.hostname);
  const fullUrl = url.startsWith('http') ? url : (window.location.origin + url);
  const gDocsUrl = `https://docs.google.com/viewer?url=${encodeURIComponent(fullUrl)}&embedded=true`;
  const mOfficeUrl = `https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(fullUrl)}`;

  let icon = 'bi-file-earmark-word text-primary';
  let badgeText = 'Word Document';
  if (['xls', 'xlsx'].includes(ext)) {
    icon = 'bi-file-earmark-excel text-success';
    badgeText = 'Excel Spreadsheet';
  } else if (['ppt', 'pptx'].includes(ext)) {
    icon = 'bi-file-earmark-ppt text-warning';
    badgeText = 'PowerPoint Presentation';
  }

  if (isOnline) {
    container.innerHTML = `
      <div class="h-100">
        <iframe src="${gDocsUrl}" style="width: 100%; height: 75vh; border: none; border-radius: 8px;" onerror="this.src='${mOfficeUrl}'"></iframe>
      </div>`;
  } else {
    container.innerHTML = `
      <div class="p-5 text-center bg-white rounded-3 border my-3">
        <i class="bi ${icon} display-3 d-block mb-3"></i>
        <h4 class="fw-bold text-dark mb-1">${title}</h4>
        <div class="mb-3">
          <span class="badge bg-primary bg-opacity-10 text-primary border px-3 py-2 fs-6">${badgeText} (.${ext.toUpperCase()})</span>
        </div>
        <p class="text-muted mx-auto" style="max-width: 500px;">
          This official <strong>.${ext.toUpperCase()}</strong> record is digitally verified on the CRM server. You can view it directly in Office / Native viewer or download it to your system.
        </p>
        <div class="d-flex justify-content-center gap-3 mt-4">
          <a href="${downloadUrl}" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm">
            <i class="bi bi-download me-2"></i> Download File (.${ext.toUpperCase()})
          </a>
          <a href="${url}" target="_blank" class="btn btn-outline-secondary px-4 py-2 fw-semibold">
            <i class="bi bi-box-arrow-up-right me-2"></i> Open in New Window
          </a>
        </div>
      </div>`;
  }
}
</script>

<!-- Modal: Preview Document -->
<div class="modal fade" id="previewDocModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-header border-bottom-0 pb-0">
        <h5 class="modal-title fw-bold" id="previewDocModalTitle"><i class="bi bi-file-earmark-pdf text-primary me-2"></i>Document Preview</h5>
        <div class="d-flex gap-2 align-items-center">
          <a href="#" id="previewDownloadBtn" download target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i> Download File</a>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body p-0 text-center bg-light" style="min-height: 500px; max-height: 80vh; overflow-y: auto;">
        <div id="previewContainer" class="p-3"></div>
      </div>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>

