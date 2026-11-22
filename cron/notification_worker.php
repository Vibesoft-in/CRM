<?php
// cron/notification_worker.php — Centralized Notification Worker
// Recommended execution: Every minute via Windows Task Scheduler or Crontab
// Example CLI command: php c:\xampp\htdocs\cms\cron\notification_worker.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Notifications\NotificationService;

// Optional: Web execution authorization key
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/json');
    $authKey = $_GET['key'] ?? '';
    // Secret cron key (matches or can be admin session)
    $validKey = defined('CRON_KEY') ? CRON_KEY : 'vibesoft_cron_secret_2026';
    if ($authKey !== $validKey && !is_admin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized cron execution']);
        exit;
    }
}

$startTime = microtime(true);
$logMessages = [];

function worker_log(string $msg): void {
    global $logMessages, $isCli;
    $formatted = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $logMessages[] = $formatted;
    if ($isCli) {
        echo $formatted . PHP_EOL;
    }
}

worker_log("Notification Worker started (Timezone: " . date_default_timezone_get() . ")");

// Concurrency Guard: Lock file with 3-minute timeout
$lockFile = __DIR__ . '/../logs/notification_worker.lock';
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < 180) { // Less than 3 minutes old
        worker_log("Another worker process is currently running (lock age: {$lockAge}s). Exiting.");
        if (!$isCli) echo json_encode(['status' => 'busy', 'log' => $logMessages]);
        exit;
    }
    worker_log("Stale lock file detected ({$lockAge}s old). Overwriting lock.");
}
@file_put_contents($lockFile, getmypid());

try {
    global $pdo;
    $service = NotificationService::getInstance($pdo);

    // 1. Process Attendance Reminders (e.g. 10:02 AM)
    worker_log("Evaluating attendance reminders...");
    $attResult = $service->processAttendanceReminders();
    worker_log("Attendance reminders: Processed={$attResult['processed']}, Skipped={$attResult['skipped']}" . (!empty($attResult['reason']) ? " ({$attResult['reason']})" : ''));

    // 2. Process Punch-Out Reminders (e.g. 8:02 PM)
    worker_log("Evaluating punch-out reminders & auto half-day...");
    $punchResult = $service->processPunchoutReminders();
    worker_log("Punch-out reminders: Processed={$punchResult['processed']}, Skipped={$punchResult['skipped']}" . (!empty($punchResult['reason']) ? " ({$punchResult['reason']})" : ''));

    // 3. Process Due Items in Notification Queue
    worker_log("Processing queued notifications...");
    $queueResult = $service->processDueQueue(30);
    worker_log("Queue processing: Processed={$queueResult['processed']}, Failed={$queueResult['failed']}");

    $elapsed = round(microtime(true) - $startTime, 3);
    worker_log("Notification Worker completed in {$elapsed}s.");

    if (!$isCli) {
        echo json_encode([
            'status' => 'success',
            'elapsed_seconds' => $elapsed,
            'attendance' => $attResult,
            'punchout' => $punchResult,
            'queue' => $queueResult,
            'log' => $logMessages
        ]);
    }
} catch (\Exception $e) {
    worker_log("ERROR in Notification Worker: " . $e->getMessage());
    if (!$isCli) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => $e->getMessage(), 'log' => $logMessages]);
    }
} finally {
    // Release lock file
    @unlink($lockFile);
}
