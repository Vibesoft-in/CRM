<?php
// app/Notifications/NotificationTemplate.php
namespace App\Notifications;

class NotificationTemplate {
    private static array $templates = [
        'attendance_reminder' => [
            'title' => 'Attendance Reminder',
            'message' => 'Hello {{employee_name}}, your attendance has not been marked today ({{date}}). Expected check-in was {{attendance_time}}. Please mark your attendance as soon as possible.',
            'link' => '/employee/dashboard.php',
            'icon' => 'bi-clock-history text-warning'
        ],
        'punchout_reminder' => [
            'title' => 'Punch Out Reminder',
            'message' => 'Hello {{employee_name}}, punch out was not marked today. Your attendance has been marked as Half Day.',
            'link' => '/employee/attendance.php',
            'icon' => 'bi-box-arrow-right text-danger'
        ],
        'half_day_marked' => [
            'title' => 'Attendance Notice: Half Day',
            'message' => 'Hello {{employee_name}}, your attendance for {{date}} has been recorded as Half Day.',
            'link' => '/employee/attendance.php',
            'icon' => 'bi-exclamation-triangle text-warning'
        ],
        'leave_submitted' => [
            'title' => 'New Leave Application',
            'message' => '{{employee_name}} has submitted a leave application for {{date}}.',
            'link' => '/modules/team/leave.php',
            'icon' => 'bi-calendar-event text-info'
        ],
        'leave_approved' => [
            'title' => 'Leave Approved',
            'message' => 'Hello {{employee_name}}, your leave application has been approved.',
            'link' => '/employee/leave.php',
            'icon' => 'bi-check-circle text-success'
        ],
        'leave_rejected' => [
            'title' => 'Leave Application Update',
            'message' => 'Hello {{employee_name}}, your leave application could not be approved at this time.',
            'link' => '/employee/leave.php',
            'icon' => 'bi-x-circle text-danger'
        ],
        'salary_generated' => [
            'title' => 'Salary Generated',
            'message' => 'Hello {{employee_name}}, your salary for {{month}} has been processed.',
            'link' => '/employee/salary.php',
            'icon' => 'bi-cash-coin text-success'
        ],
        'salary_slip_generated' => [
            'title' => 'Salary Slip Ready',
            'message' => 'Hello {{employee_name}}, your salary slip for {{month}} is now available to view and download.',
            'link' => '/employee/salary-slips.php',
            'icon' => 'bi-file-earmark-pdf text-primary'
        ],
        'task_assigned' => [
            'title' => 'New Task Assigned',
            'message' => 'Hello {{employee_name}}, you have been assigned a new task: "{{task_title}}".',
            'link' => '/employee/work-reports.php',
            'icon' => 'bi-list-check text-primary'
        ],
        'admin_announcement' => [
            'title' => 'Announcement',
            'message' => '{{message}}',
            'link' => '/employee/dashboard.php',
            'icon' => 'bi-megaphone text-primary'
        ],
        'work_report_reviewed' => [
            'title' => 'Daily Report Reviewed',
            'message' => 'Hello {{employee_name}}, your daily work report for {{date}} has been reviewed by Admin.',
            'link' => '/employee/work-reports.php',
            'icon' => 'bi-clipboard-check text-success'
        ],
        'offer_letter_issued' => [
            'title' => 'Official Offer Letter Issued',
            'message' => 'Hello {{employee_name}}, your official offer letter has been issued by management. View your profile documents to review.',
            'link' => '/employee/profile.php',
            'icon' => 'bi-file-earmark-check text-primary'
        ],
        'lunch_resume_missed' => [
            'title' => 'Lunch Break Overdue Notice',
            'message' => 'Hello {{employee_name}}, your lunch break exceeded the allowed limit and has been automatically ended because work was not resumed in time.',
            'link' => '/employee/attendance.php',
            'icon' => 'bi-cup-hot text-danger'
        ],
        'system_alert' => [
            'title' => 'System Alert',
            'message' => '{{message}}',
            'link' => '',
            'icon' => 'bi-bell text-secondary'
        ]
    ];

    /**
     * Render template with variables
     */
    public static function render(string $type, array $params = []): array {
        $tpl = self::$templates[$type] ?? self::$templates['system_alert'];

        $title = $params['title'] ?? $tpl['title'];
        $message = $params['message'] ?? $tpl['message'];
        $link = $params['link'] ?? $tpl['link'];
        $icon = $tpl['icon'] ?? 'bi-bell text-primary';

        // Replace placeholders
        foreach ($params as $key => $val) {
            if (is_scalar($val)) {
                $needle = '{{' . $key . '}}';
                $title = str_replace($needle, (string)$val, $title);
                $message = str_replace($needle, (string)$val, $message);
                $link = str_replace($needle, (string)$val, $link);
            }
        }

        return [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'icon' => $icon,
            'payload' => $params
        ];
    }

    public static function getAllTypes(): array {
        return array_keys(self::$templates);
    }
}
