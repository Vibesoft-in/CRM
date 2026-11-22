<?php
// modules/team/employee_ar.php — Shortcut redirect to Employee Attendance & Reports (A & R)
require_once '../../includes/functions.php';
require_login();

$id    = intval($_GET['id'] ?? 0);
$month = intval($_GET['month'] ?? date('n'));
$year  = intval($_GET['year'] ?? date('Y'));

if ($id <= 0) {
    header("Location: employees.php");
    exit;
}

header("Location: employee_profile.php?id=$id&tab=attendance_reports&month=$month&year=$year");
exit;
