<?php
// app/Security/Authorization.php — Centralized RBAC & Object-Level Ownership (IDOR Defense)
namespace App\Security;

use PDO;

class Authorization {
    private static function getPdo(): PDO {
        global $pdo;
        return $pdo;
    }

    /**
     * Check if current user has a specific permission key
     */
    public static function can(string $permissionKey): bool {
        if (!Auth::check()) return false;
        if (Auth::isAdmin()) return true;

        $roleName = Auth::role();
        $pdo = self::getPdo();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM role_permissions rp
            JOIN roles r ON r.id = rp.role_id
            JOIN permissions p ON p.id = rp.perm_id
            WHERE LOWER(r.role_name) = LOWER(?) AND p.perm_key = ?
        ");
        $stmt->execute([$roleName, $permissionKey]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * Enforce permission or abort with 403
     */
    public static function requirePermission(string $permissionKey): void {
        Auth::requireLogin();
        if (!self::can($permissionKey)) {
            AuditLogger::securityEvent('SECURITY_PERMISSION_DENIED', [
                'required_permission' => $permissionKey,
                'user_role' => Auth::role(),
                'uri' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
            Auth::abortForbidden("Access Denied: You do not have permission ($permissionKey) to perform this action.");
        }
    }

    /**
     * STRICT IDOR DEFENSE: Authorize Salary Slip Access
     * Employees can ONLY open their own salary slips.
     * Managers cannot access unauthorized salary slips.
     * Admins can view authorized slips.
     */
    public static function authorizeSalarySlip(int $payrollId): array {
        Auth::requireLogin();

        if ($payrollId <= 0) {
            Auth::abortForbidden("Invalid Salary Slip reference.");
        }

        $pdo = self::getPdo();
        $stmt = $pdo->prepare("SELECT * FROM payroll WHERE id = ?");
        $stmt->execute([$payrollId]);
        $payroll = $stmt->fetch();

        if (!$payroll) {
            Auth::abortForbidden("Salary slip not found.");
        }

        $slipOwnerEmpId = (int)$payroll['employee_id'];
        $currentEmpId   = Auth::employeeId();

        // 1. Admin has global administrative access
        if (Auth::isAdmin()) {
            return $payroll;
        }

        // 2. Manager Access: ONLY if the employee is assigned to this manager and manager has reports/payroll permission
        if (Auth::isManager()) {
            $mStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND manager_id = ?");
            $mStmt->execute([$slipOwnerEmpId, Auth::id()]);
            $isAssigned = $mStmt->fetch();

            if ($isAssigned && self::can('salary.view_assigned')) {
                return $payroll;
            }
        }

        // 3. Employee Access: Strictly verified against the authenticated employee ID
        if ($currentEmpId !== null && $currentEmpId > 0 && $slipOwnerEmpId === $currentEmpId) {
            return $payroll;
        }

        // SECURITY VIOLATION: IDOR Attempt Detected!
        AuditLogger::securityEvent('SECURITY_IDOR_SALARY_SLIP_ATTEMPT', [
            'attempted_payroll_id' => $payrollId,
            'slip_owner_emp_id'    => $slipOwnerEmpId,
            'current_user_id'      => Auth::id(),
            'current_employee_id'  => $currentEmpId,
            'current_role'         => Auth::role(),
            'uri'                  => $_SERVER['REQUEST_URI'] ?? ''
        ]);

        Auth::abortForbidden("Access Denied: You are not authorized to view this salary slip.");
        exit;
    }

    /**
     * STRICT IDOR DEFENSE: Authorize Employee Record Access
     */
    public static function authorizeEmployee(int $targetEmployeeId): array {
        Auth::requireLogin();

        if ($targetEmployeeId <= 0) {
            Auth::abortForbidden("Invalid Employee reference.");
        }

        $pdo = self::getPdo();
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$targetEmployeeId]);
        $emp = $stmt->fetch();

        if (!$emp) {
            Auth::abortForbidden("Employee record not found.");
        }

        if (Auth::isAdmin()) {
            return $emp;
        }

        if (Auth::isManager()) {
            // Check assignment
            if ((int)($emp['manager_id'] ?? 0) === Auth::id()) {
                return $emp;
            }
        }

        $currentEmpId = Auth::employeeId();
        if ($currentEmpId !== null && $currentEmpId > 0 && (int)$emp['id'] === $currentEmpId) {
            return $emp;
        }

        AuditLogger::securityEvent('SECURITY_IDOR_EMPLOYEE_ATTEMPT', [
            'target_emp_id'       => $targetEmployeeId,
            'current_user_id'     => Auth::id(),
            'current_employee_id' => $currentEmpId
        ]);

        Auth::abortForbidden("Access Denied: You do not have permission to access another employee's records.");
        exit;
    }

    /**
     * STRICT IDOR DEFENSE: Authorize Daily Work Report Access
     */
    public static function authorizeWorkReport(int $reportId): array {
        Auth::requireLogin();

        $pdo = self::getPdo();
        $stmt = $pdo->prepare("SELECT * FROM daily_work_reports WHERE id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();

        if (!$report) {
            Auth::abortForbidden("Work report not found.");
        }

        $reportOwnerEmpId = (int)$report['employee_id'];
        $currentEmpId     = Auth::employeeId();

        if (Auth::isAdmin()) {
            return $report;
        }

        if (Auth::isManager()) {
            $mStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND manager_id = ?");
            $mStmt->execute([$reportOwnerEmpId, Auth::id()]);
            if ($mStmt->fetch()) {
                return $report;
            }
        }

        if ($currentEmpId !== null && $currentEmpId > 0 && $reportOwnerEmpId === $currentEmpId) {
            return $report;
        }

        AuditLogger::securityEvent('SECURITY_IDOR_WORK_REPORT_ATTEMPT', [
            'report_id'           => $reportId,
            'report_owner_emp_id' => $reportOwnerEmpId,
            'current_employee_id' => $currentEmpId
        ]);

        Auth::abortForbidden("Access Denied: You cannot view or modify another employee's work report.");
        exit;
    }

    /**
     * STRICT IDOR DEFENSE: Authorize Attendance Record Access
     */
    public static function authorizeAttendance(int $attendanceId): array {
        Auth::requireLogin();

        $pdo = self::getPdo();
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
        $stmt->execute([$attendanceId]);
        $att = $stmt->fetch();

        if (!$att) {
            Auth::abortForbidden("Attendance record not found.");
        }

        $attOwnerEmpId = (int)$att['employee_id'];
        $currentEmpId  = Auth::employeeId();

        if (Auth::isAdmin()) {
            return $att;
        }

        if (Auth::isManager()) {
            $mStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND manager_id = ?");
            $mStmt->execute([$attOwnerEmpId, Auth::id()]);
            if ($mStmt->fetch()) {
                return $att;
            }
        }

        if ($currentEmpId !== null && $currentEmpId > 0 && $attOwnerEmpId === $currentEmpId) {
            return $att;
        }

        AuditLogger::securityEvent('SECURITY_IDOR_ATTENDANCE_ATTEMPT', [
            'attendance_id'       => $attendanceId,
            'att_owner_emp_id'    => $attOwnerEmpId,
            'current_employee_id' => $currentEmpId
        ]);

        Auth::abortForbidden("Access Denied: You cannot access another employee's attendance record.");
        exit;
    }
}
