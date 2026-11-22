<?php
// Crm and backend/api/mobile_work_reports.php
require_once __DIR__ . '/mobile_helper.php';

$auth = authenticate_mobile();
$empId = $auth['employee_id'];
if (!$empId) {
    json_error('No linked employee profile found for this account', 400);
}

// Strict IDOR Check: Ensure employee only queries their own work reports unless Manager/Admin
$targetEmpId = (int)($_GET['employee_id'] ?? ($_POST['employee_id'] ?? $empId));
if ($targetEmpId !== $empId) {
    verify_employee_access($targetEmpId, $auth, 'work reports');
    $empId = $targetEmpId;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$input = get_json_input();

// Standard categories
$categories = [
    'Frontend Development',
    'Backend API Integration',
    'Mobile App Development',
    'UI/UX Design & Prototyping',
    'Bug Fixing & Optimization',
    'Code Review & Architecture',
    'Testing & Quality Assurance',
    'Client Meeting & Requirements'
];

if ($action === 'categories') {
    json_success(['categories' => $categories], 'Categories loaded');
}

// ----------------------------------------------------
// ACTION: SINGLE REPORT DETAIL
// ----------------------------------------------------
if ($action === 'detail') {
    $reportId = (int)($input['id'] ?? ($_GET['id'] ?? 0));
    if ($reportId <= 0) json_error('Report ID is required', 400);

    $report = verify_record_ownership('work_reports', $reportId, 'employee_id', $auth, 'work report');
    json_success(['report' => $report], 'Work report detail loaded');
}

// ----------------------------------------------------
// ACTION: GET CALCULATED DURATION FOR A DATE
// ----------------------------------------------------
// ACTION: GET CALCULATED DURATION FOR A DATE
// ----------------------------------------------------
if ($action === 'get_calculated_duration') {
    $reportDate = trim($input['date'] ?? ($_GET['date'] ?? date('Y-m-d')));
    if (strpos($reportDate, '/') !== false) {
        $parts = explode('/', $reportDate);
        if (count($parts) === 3) {
            $reportDate = sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[0], (int)$parts[1]);
        }
    }
    $calc = calculate_work_report_duration($pdo, $empId, $reportDate);
    json_success([
        'date'               => $reportDate,
        'calculated_hours'   => $calc['hours'],
        'calculated_minutes' => $calc['minutes'],
        'duration_formatted' => $calc['duration_formatted'],
        'is_clocked_in'      => $calc['is_clocked_in'],
        'is_clocked_out'     => $calc['is_clocked_out'],
        'lunch_minutes'      => $calc['lunch_minutes'],
    ], 'Calculated work duration retrieved');
}

// ----------------------------------------------------
// ACTION: GET FORM DATA (LOAD EXISTING REPORT & DURATION)
// ----------------------------------------------------
if ($action === 'get_form_data') {
    $reportDate = trim($input['date'] ?? ($_GET['date'] ?? date('Y-m-d')));
    if (strpos($reportDate, '/') !== false) {
        $parts = explode('/', $reportDate);
        if (count($parts) === 3) {
            $reportDate = sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[0], (int)$parts[1]);
        }
    }
    $durCalc = calculate_work_report_duration($pdo, $empId, $reportDate);

    $eStmt = $pdo->prepare("SELECT * FROM daily_work_reports WHERE employee_id = ? AND report_date = ? LIMIT 1");
    $eStmt->execute([$empId, $reportDate]);
    $existingDwr = $eStmt->fetch(PDO::FETCH_ASSOC);

    $wStmt = $pdo->prepare("SELECT * FROM work_reports WHERE employee_id = ? AND report_date = ? LIMIT 1");
    $wStmt->execute([$empId, $reportDate]);
    $existingWr = $wStmt->fetch(PDO::FETCH_ASSOC);

    $isRevisionRequested = false;
    if (($existingDwr && ($existingDwr['status'] ?? '') === 'revision_requested') ||
        ($existingWr && ($existingWr['status'] ?? '') === 'revision_requested') ||
        ($existingDwr && !empty($existingDwr['review_note']) && stripos($existingDwr['review_note'], 'revision') !== false)) {
        $isRevisionRequested = true;
    }

    $isLocked = false;
    if (!$isRevisionRequested) {
        if ($existingDwr) {
            if (!empty($existingDwr['rating']) || !empty($existingDwr['reviewed_by']) || !empty($existingDwr['reviewed_at']) || !empty($existingDwr['review_note'])) {
                $isLocked = true;
            }
        }
        if (!$isLocked && $existingWr) {
            $st = strtolower($existingWr['status'] ?? 'submitted');
            if (!in_array($st, ['submitted', 'draft', 'pending', '']) || !empty($existingWr['reviewed_by']) || !empty($existingWr['reviewed_at']) || !empty($existingWr['review_comment'])) {
                $isLocked = true;
            }
        }
    }

    $morningTasks = $existingDwr['morning_tasks'] ?? ($existingWr['summary'] ?? '');
    $eveningTasks = $existingDwr['evening_tasks'] ?? ($existingWr['tasks_completed'] ?? '');
    $projectsWorked = $existingDwr['projects_worked'] ?? ($existingWr['projects_worked'] ?? '');
    $challenges = $existingDwr['blockers'] ?? ($existingWr['challenges'] ?? '');
    $tomorrowPlan = $existingDwr['plan_for_tomorrow'] ?? ($existingWr['tomorrow_plan'] ?? '');
    $status = $existingWr['status'] ?? ($existingDwr ? 'submitted' : '');
    $reviewNote = $existingDwr['review_note'] ?? ($existingWr['review_comment'] ?? '');

    json_success([
        'report_date'         => $reportDate,
        'hours_worked'        => $durCalc['hours'],
        'duration_formatted'  => $durCalc['duration_formatted'],
        'is_clocked_in'       => $durCalc['is_clocked_in'] ?? false,
        'is_clocked_out'      => $durCalc['is_clocked_out'] ?? false,
        'lunch_minutes'       => $durCalc['lunch_minutes'] ?? 0,
        'has_existing_report' => !empty($existingDwr) || !empty($existingWr),
        'is_locked'           => $isLocked,
        'morning_tasks'       => $morningTasks,
        'evening_tasks'       => $eveningTasks,
        'projects_worked'     => $projectsWorked,
        'challenges'          => $challenges,
        'tomorrow_plan'       => $tomorrowPlan,
        'status'              => $status,
        'review_note'         => $reviewNote,
        'rating'              => $existingDwr['rating'] ?? null,
    ], 'Report form data loaded');
}

// ----------------------------------------------------
// ACTION: SUBMIT DAILY WORK REPORT (WEB-CRM IDENTICAL)
// ----------------------------------------------------
if ($action === 'submit') {
    $reportDate = trim($input['report_date'] ?? ($input['date'] ?? date('Y-m-d')));
    if (strpos($reportDate, '/') !== false) {
        $parts = explode('/', $reportDate);
        if (count($parts) === 3) {
            $reportDate = sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[0], (int)$parts[1]);
        }
    }

    $morningTasks   = trim($input['morning_tasks'] ?? '');
    $eveningTasks   = trim($input['evening_tasks'] ?? ($input['tasks_completed'] ?? ($input['description'] ?? '')));
    $projectsWorked = trim($input['projects_worked'] ?? ($input['category'] ?? ''));
    $challenges     = trim($input['challenges'] ?? ($input['blockers'] ?? ''));
    $tomorrowPlan   = trim($input['tomorrow_plan'] ?? ($input['plan_for_tomorrow'] ?? ''));
    $saveStatus     = strtolower(trim($input['save_status'] ?? ($input['status'] ?? 'submitted')));
    if ($saveStatus !== 'draft') {
        $saveStatus = 'submitted';
    }

    if (strtotime($reportDate) > strtotime(date('Y-m-d'))) {
        json_error("You cannot submit a work report for a future date (" . date('d M Y', strtotime($reportDate)) . ").", 400);
    }

    if (empty($eveningTasks)) {
        json_error('Evening Work Completed / Tasks completed today is required', 400);
    }

    // Auto calculate hours worked from attendance minus lunch breaks
    $durCalc     = calculate_work_report_duration($pdo, $empId, $reportDate);
    $hoursWorked = (float)$durCalc['hours'];
    if (isset($input['hours_worked']) && is_numeric($input['hours_worked']) && (float)$input['hours_worked'] > 0 && $hoursWorked <= 0) {
        $hoursWorked = (float)$input['hours_worked'];
    }

    // Mandatory rule: User must punch out before submitting work report
    $isAdminUser = is_admin() || in_array(strtolower($auth['role'] ?? ''), ['admin', 'super admin', 'manager']);
    if (!$isAdminUser && empty($durCalc['is_clocked_out'])) {
        json_error("Work report submit karne se pehle Punch-Out karna zaroori hai. Kripya pehle punch-out karein.", 400, [
            'requires_clock_out' => true,
            'is_clocked_in'      => $durCalc['is_clocked_in'],
            'is_clocked_out'     => false,
        ]);
    }

    // Lock check
    $checkDwr = $pdo->prepare("SELECT * FROM daily_work_reports WHERE employee_id = ? AND report_date = ? LIMIT 1");
    $checkDwr->execute([$empId, $reportDate]);
    $existingDwr = $checkDwr->fetch(PDO::FETCH_ASSOC);

    $checkWr = $pdo->prepare("SELECT * FROM work_reports WHERE employee_id = ? AND report_date = ? LIMIT 1");
    $checkWr->execute([$empId, $reportDate]);
    $existingWr = $checkWr->fetch(PDO::FETCH_ASSOC);

    $isRevisionRequested = false;
    if (($existingDwr && ($existingDwr['status'] ?? '') === 'revision_requested') ||
        ($existingWr && ($existingWr['status'] ?? '') === 'revision_requested') ||
        ($existingDwr && !empty($existingDwr['review_note']) && stripos($existingDwr['review_note'], 'revision') !== false)) {
        $isRevisionRequested = true;
    }

    $isLocked = false;
    if (!$isRevisionRequested) {
        if ($existingDwr) {
            if (!empty($existingDwr['rating']) || !empty($existingDwr['reviewed_by']) || !empty($existingDwr['reviewed_at']) || !empty($existingDwr['review_note'])) {
                $isLocked = true;
            }
        }
        if (!$isLocked && $existingWr) {
            $st = strtolower($existingWr['status'] ?? 'submitted');
            if (!in_array($st, ['submitted', 'draft', 'pending', '']) || !empty($existingWr['reviewed_by']) || !empty($existingWr['reviewed_at']) || !empty($existingWr['review_comment'])) {
                $isLocked = true;
            }
        }
    }

    if ($isLocked) {
        json_error("This work report has been reviewed or locked and cannot be modified.", 403, [
            'is_locked'   => true,
            'report_date' => $reportDate
        ]);
    }

    // 1. Update daily_work_reports (Web table)
    $dSql = "
        INSERT INTO daily_work_reports 
            (employee_id, report_date, morning_tasks, evening_tasks, projects_worked, hours_worked, blockers, plan_for_tomorrow, status, rating, reviewed_by, reviewed_at, submitted_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NULL, NULL, NULL, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            morning_tasks = VALUES(morning_tasks),
            evening_tasks = VALUES(evening_tasks),
            projects_worked = VALUES(projects_worked),
            hours_worked = VALUES(hours_worked),
            blockers = VALUES(blockers),
            plan_for_tomorrow = VALUES(plan_for_tomorrow),
            status = 'submitted',
            rating = NULL,
            reviewed_by = NULL,
            reviewed_at = NULL,
            updated_at = NOW()
    ";
    $pdo->prepare($dSql)->execute([$empId, $reportDate, $morningTasks, $eveningTasks, $projectsWorked, $hoursWorked, $challenges, $tomorrowPlan]);

    // 2. Update work_reports table
    $summaryText = !empty($morningTasks) ? $morningTasks : (!empty($projectsWorked) ? $projectsWorked : 'Evening Report');
    $wSql = "
        INSERT INTO work_reports 
            (employee_id, report_date, summary, tasks_completed, projects_worked, hours_worked, challenges, tomorrow_plan, status, submitted_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            summary = VALUES(summary),
            tasks_completed = VALUES(tasks_completed),
            projects_worked = VALUES(projects_worked),
            hours_worked = VALUES(hours_worked),
            challenges = VALUES(challenges),
            tomorrow_plan = VALUES(tomorrow_plan),
            status = VALUES(status),
            reviewed_by = NULL,
            reviewed_at = NULL,
            review_comment = NULL,
            updated_at = NOW()
    ";
    $pdo->prepare($wSql)->execute([$empId, $reportDate, $summaryText, $eveningTasks, $projectsWorked, $hoursWorked, $challenges, $tomorrowPlan, $saveStatus]);

    try {
        require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
        \App\Realtime\RealtimeService::getInstance($pdo)->emit(
            ($existingDwr || $existingWr) ? 'work_report.updated' : 'work_report.new',
            'work_report',
            $empId,
            ($existingDwr || $existingWr) ? 'update' : 'create',
            [
                'employee_id' => (int)$empId,
                'date'        => $reportDate,
                'status'      => $saveStatus
            ],
            ['admin', 'manager'],
            null,
            $auth['user_id']
        );
    } catch (\Throwable $e) {}

    $isUpdateAction = !empty($existingDwr) || !empty($existingWr);
    $notifTitle = $isUpdateAction ? 'Report Edited' : ($saveStatus === 'draft' ? 'Report Draft Saved' : 'Report Submitted');
    $notifMsg = "Your daily work report for " . date('d M Y', strtotime($reportDate)) . " was " . ($saveStatus === 'draft' ? 'saved as draft.' : ($isUpdateAction ? 'updated successfully.' : 'submitted successfully.'));
    create_action_notification(
        $pdo,
        (int)$auth['user_id'],
        $notifTitle,
        $notifMsg,
        '/employee/work-reports.php',
        $isUpdateAction ? 'report_edited' : 'report_submitted'
    );

    json_success([
        'report_date' => $reportDate,
        'status'      => $saveStatus
    ], $saveStatus === 'draft' ? 'Work report saved as draft' : 'Work report submitted successfully');
}

// ----------------------------------------------------
// ACTION: LIST WORK REPORTS (WEB-MATCHING COLUMNS)
// ----------------------------------------------------
$pagination = get_pagination_params(20, 60);

$stmt = $pdo->prepare("
    SELECT 
        COALESCE(dwr.id, wr.id) as id,
        COALESCE(dwr.report_date, wr.report_date) as report_date,
        dwr.morning_tasks,
        COALESCE(dwr.evening_tasks, wr.tasks_completed) as evening_tasks,
        COALESCE(dwr.projects_worked, wr.projects_worked) as projects_worked,
        COALESCE(dwr.hours_worked, wr.hours_worked, 0) as hours_worked,
        COALESCE(dwr.blockers, wr.challenges) as blockers,
        COALESCE(dwr.plan_for_tomorrow, wr.tomorrow_plan) as plan_for_tomorrow,
        dwr.rating,
        dwr.reviewed_by,
        dwr.reviewed_at,
        dwr.review_note,
        wr.status as wr_status,
        wr.review_comment as wr_review_comment,
        COALESCE(dwr.submitted_at, wr.submitted_at) as submitted_at,
        COALESCE(dwr.updated_at, wr.updated_at) as updated_at
    FROM daily_work_reports dwr
    LEFT JOIN work_reports wr ON wr.employee_id = dwr.employee_id AND wr.report_date = dwr.report_date
    WHERE dwr.employee_id = ?
    ORDER BY dwr.report_date DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $empId, PDO::PARAM_INT);
$stmt->bindValue(2, $pagination['limit'], PDO::PARAM_INT);
$stmt->bindValue(3, $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If daily_work_reports was empty, fallback query on work_reports
if (empty($rows)) {
    $wrStmt = $pdo->prepare("
        SELECT 
            wr.id,
            wr.report_date,
            wr.summary as morning_tasks,
            wr.tasks_completed as evening_tasks,
            wr.projects_worked,
            wr.hours_worked,
            wr.challenges as blockers,
            wr.tomorrow_plan as plan_for_tomorrow,
            NULL as rating,
            wr.reviewed_by,
            wr.reviewed_at,
            wr.review_comment as review_note,
            wr.status as wr_status,
            wr.review_comment as wr_review_comment,
            wr.submitted_at,
            wr.updated_at
        FROM work_reports wr
        WHERE wr.employee_id = ?
        ORDER BY wr.report_date DESC
        LIMIT ? OFFSET ?
    ");
    $wrStmt->bindValue(1, $empId, PDO::PARAM_INT);
    $wrStmt->bindValue(2, $pagination['limit'], PDO::PARAM_INT);
    $wrStmt->bindValue(3, $pagination['offset'], PDO::PARAM_INT);
    $wrStmt->execute();
    $rows = $wrStmt->fetchAll(PDO::FETCH_ASSOC);
}

$reports = [];
foreach ($rows as $r) {
    $dateTs = strtotime($r['report_date']);
    $st = strtolower($r['wr_status'] ?? 'submitted');
    
    $isApproved = (!empty($r['rating']) || $st === 'approved' || !empty($r['reviewed_at']));
    $rowLocked = (!empty($r['rating']) || !empty($r['reviewed_by']) || !empty($r['reviewed_at']) || !empty($r['review_note']) || (!empty($r['wr_status']) && !in_array($st, ['submitted', 'draft', 'pending', ''])) || !empty($r['wr_review_comment']));

    $isEdited = !empty($r['updated_at']) && !empty($r['submitted_at']) && (strtotime($r['updated_at']) > strtotime($r['submitted_at']) + 60);

    $badge = 'Submitted';
    if ($isApproved || $rowLocked) {
        $badge = 'Approved';
    } elseif ($st === 'draft') {
        $badge = 'Draft';
    }

    $submittedTime = !empty($r['submitted_at']) ? date('h:i A', strtotime($r['submitted_at'])) : '—';
    $editedTime = !empty($r['updated_at']) ? date('d M, h:i A', strtotime($r['updated_at'])) : '';

    $reports[] = [
        'id'                     => (int)$r['id'],
        'report_date'            => $r['report_date'],
        'formatted_date'         => date('d M Y', $dateTs),
        'submitted_time'         => $submittedTime,
        'is_edited'              => $isEdited,
        'edited_time'            => $editedTime,
        'morning_tasks'          => $r['morning_tasks'] ?? '',
        'evening_tasks'          => $r['evening_tasks'] ?? '—',
        'projects_worked'        => !empty($r['projects_worked']) ? $r['projects_worked'] : '—',
        'hours_worked'           => (float)($r['hours_worked'] ?? 0),
        'hours_formatted'        => number_format((float)($r['hours_worked'] ?? 0), 1) . 'h',
        'blockers'               => $r['blockers'] ?? '',
        'plan_for_tomorrow'      => $r['plan_for_tomorrow'] ?? '',
        'status'                 => $badge,
        'status_raw'             => $st,
        'is_approved'            => $isApproved,
        'is_locked'              => $rowLocked,
        'can_edit'               => !$rowLocked,
        'review_note'            => !empty($r['review_note']) ? $r['review_note'] : ($r['wr_review_comment'] ?? null),
        'rating'                 => !empty($r['rating']) ? (int)$r['rating'] : null,
        'lock_reason'            => $rowLocked ? 'This report has been reviewed or finalized by management and cannot be modified.' : null,
    ];
}

json_success([
    'categories' => $categories,
    'reports'    => $reports,
    'page'       => $pagination['page'],
    'limit'      => $pagination['limit']
], 'Work reports loaded');
