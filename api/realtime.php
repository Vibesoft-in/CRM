<?php
// Crm and backend/api/realtime.php — Realtime SSE Stream & Delta Sync Endpoint
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../app/Realtime/RealtimeService.php';

use App\Realtime\RealtimeService;

$auth = authenticate_mobile();
$user = $auth['user'];
$userId = (int)$auth['user_id'];
$userRole = strtolower(trim($auth['role'] ?? ($user['role'] ?? 'staff')));

$action = $_GET['action'] ?? 'stream';
$realtimeService = RealtimeService::getInstance($pdo);

// ----------------------------------------------------
// 1. REST API DELTA SYNC (FALLBACK & MISSED EVENTS)
// ----------------------------------------------------
if ($action === 'sync') {
    $sinceId = max(0, (int)($_GET['since'] ?? ($_GET['last_event_id'] ?? 0)));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

    $events = $realtimeService->getEventsSince($sinceId, $userId, $userRole, $limit);
    $lastId = $sinceId;
    if (!empty($events)) {
        $lastId = end($events)['id'];
    }

    json_success([
        'events'        => $events,
        'last_event_id' => $lastId,
        'count'         => count($events)
    ], 'Events synced successfully');
}

// ----------------------------------------------------
// 2. DIAGNOSTIC STATUS
// ----------------------------------------------------
if ($action === 'status') {
    $stmt = $pdo->query("SELECT MAX(id) AS max_id, COUNT(*) as total_events FROM realtime_events");
    $stat = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : ['max_id' => 0, 'total_events' => 0];

    json_success([
        'status'         => 'active',
        'server_time'    => date('Y-m-d H:i:s'),
        'user_id'        => $userId,
        'user_role'      => $userRole,
        'max_event_id'   => (int)($stat['max_id'] ?? 0),
        'total_events'   => (int)($stat['total_events'] ?? 0),
    ], 'Realtime engine is healthy');
}

// ----------------------------------------------------
// 3. SERVER-SENT EVENTS (SSE) STREAM
// ----------------------------------------------------
if ($action === 'stream') {
    // Disable any output buffering so chunks are flushed immediately
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Configure headers for SSE
    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    // Determine initial last_event_id from header or query
    $headerLastId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? '';
    $sinceQuery = $_GET['since'] ?? ($_GET['last_event_id'] ?? $headerLastId);
    $currentLastId = max(0, (int)$sinceQuery);

    // Instruct SSE client to reconnect after 3 seconds on disconnect
    echo "retry: 3000\n\n";
    flush();

    // Max runtime for single connection loop to prevent Apache thread exhaustion: 60 seconds
    // After 60 seconds, stream closes gracefully and the client reconnects automatically
    $maxDurationSeconds = 60;
    $startTime = time();
    $lastPingTime = time();

    // Prevent script timeout
    @set_time_limit($maxDurationSeconds + 10);
    @ignore_user_abort(false);

    while (time() - $startTime < $maxDurationSeconds) {
        if (connection_aborted()) {
            break;
        }

        // Fetch any new events since currentLastId
        $events = $realtimeService->getEventsSince($currentLastId, $userId, $userRole, 25);

        if (!empty($events)) {
            foreach ($events as $evt) {
                $evtId = $evt['id'];
                $evtName = $evt['event_name'];
                $jsonData = json_encode($evt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                echo "id: {$evtId}\n";
                echo "event: {$evtName}\n";
                echo "data: {$jsonData}\n\n";

                $currentLastId = max($currentLastId, $evtId);
            }
            flush();
            $lastPingTime = time();
        }

        // Send a heartbeat ping comment every 15 seconds to keep connection alive through proxies/NAT
        if (time() - $lastPingTime >= 15) {
            echo ": ping - " . time() . "\n\n";
            flush();
            $lastPingTime = time();
        }

        // Wait 1 second before next poll
        sleep(1);
    }

    exit;
}

json_error('Invalid realtime action', 400);
