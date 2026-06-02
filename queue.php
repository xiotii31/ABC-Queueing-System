<?php
// ============================================================
// api/queue.php — Staff Monitor: get current queue state
// GET  → returns full queue + inside count + now serving
// POST { action: "call_next"|"mark_done"|"mark_severity"|"skip" }
// ============================================================
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$pdo = getDB();

// ── GET: return queue data ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // All active patients today
    $queueStmt = $pdo->prepare("
        SELECT id, ticket_number, patient_type, status, severity, queue_position, created_at, called_at
        FROM patients
        WHERE status IN ('waiting', 'inside', 'called')
          AND DATE(created_at) = CURDATE()
        ORDER BY
            FIELD(patient_type, 'priority', 'regular', 'followup'),
            created_at ASC
    ");
    $queueStmt->execute();
    $queue = $queueStmt->fetchAll();

    // Inside count
    $insideStmt = $pdo->prepare("
        SELECT COUNT(*) FROM inside_log WHERE exited_at IS NULL
    ");
    $insideStmt->execute();
    $insideCount = (int)$insideStmt->fetchColumn();

    // Now serving
    $nsStmt = $pdo->prepare("SELECT ticket_number, patient_type, updated_at FROM now_serving WHERE id = 1");
    $nsStmt->execute();
    $nowServing = $nsStmt->fetch();

    // Counts per type
    $countsStmt = $pdo->prepare("
        SELECT patient_type, COUNT(*) as cnt
        FROM patients
        WHERE status = 'waiting' AND DATE(created_at) = CURDATE()
        GROUP BY patient_type
    ");
    $countsStmt->execute();
    $countRows = $countsStmt->fetchAll();
    $counts = ['priority' => 0, 'regular' => 0, 'followup' => 0];
    foreach ($countRows as $row) { $counts[$row['patient_type']] = (int)$row['cnt']; }

    // Today's totals
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE()");
    $totalStmt->execute();
    $totalToday = (int)$totalStmt->fetchColumn();

    $servedStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE status = 'done' AND DATE(created_at) = CURDATE()");
    $servedStmt->execute();
    $totalServed = (int)$servedStmt->fetchColumn();

    jsonResponse([
        'queue'        => $queue,
        'inside_count' => $insideCount,
        'now_serving'  => $nowServing,
        'counts'       => $counts,
        'total_today'  => $totalToday,
        'total_served' => $totalServed,
    ]);
}

// ── POST: perform action ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';

    switch ($action) {

        // ── Call next patient ──
        case 'call_next':
            $insideStmt = $pdo->prepare("SELECT COUNT(*) FROM inside_log WHERE exited_at IS NULL");
            $insideStmt->execute();
            $insideCount = (int)$insideStmt->fetchColumn();

            if ($insideCount >= 5) {
                jsonResponse(['error' => 'Maximum 5 patients inside. Mark a patient as done first.'], 400);
            }

            // Pick next: priority first, then regular/followup by time; Cat3 always first
            $nextStmt = $pdo->prepare("
                SELECT id, ticket_number, patient_type FROM patients
                WHERE status = 'waiting' AND DATE(created_at) = CURDATE()
                ORDER BY
                    CASE severity WHEN 'cat3' THEN 0 ELSE 1 END ASC,
                    FIELD(patient_type, 'priority', 'regular', 'followup') ASC,
                    created_at ASC
                LIMIT 1
            ");
            $nextStmt->execute();
            $next = $nextStmt->fetch();

            if (!$next) {
                jsonResponse(['error' => 'No patients waiting'], 404);
            }

            $pdo->beginTransaction();

            // Update patient status
            $pdo->prepare("UPDATE patients SET status = 'called', called_at = NOW() WHERE id = :id")
                ->execute(['id' => $next['id']]);

            // Log inside entry
            $pdo->prepare("INSERT INTO inside_log (patient_id) VALUES (:id)")
                ->execute(['id' => $next['id']]);

            // Update now_serving for TV
            $pdo->prepare("UPDATE now_serving SET ticket_number = :t, patient_type = :pt WHERE id = 1")
                ->execute(['t' => $next['ticket_number'], 'pt' => $next['patient_type']]);

            // Activity log
            $pdo->prepare("INSERT INTO activity_log (action, ticket_number, performed_by) VALUES ('called', :t, 'staff')")
                ->execute(['t' => $next['ticket_number']]);

            $pdo->commit();
            jsonResponse(['success' => true, 'called' => $next['ticket_number'], 'type' => $next['patient_type']]);

        // ── Mark patient done / exited ──
        case 'mark_done':
            $ticketNumber = $body['ticket_number'] ?? '';
            if (!$ticketNumber) jsonResponse(['error' => 'ticket_number required'], 400);

            $pdo->beginTransaction();

            $patStmt = $pdo->prepare("SELECT id FROM patients WHERE ticket_number = :t AND DATE(created_at) = CURDATE()");
            $patStmt->execute(['t' => $ticketNumber]);
            $patient = $patStmt->fetch();

            if (!$patient) jsonResponse(['error' => 'Patient not found'], 404);

            $pdo->prepare("UPDATE patients SET status = 'done', done_at = NOW() WHERE id = :id")
                ->execute(['id' => $patient['id']]);

            $pdo->prepare("UPDATE inside_log SET exited_at = NOW() WHERE patient_id = :id AND exited_at IS NULL")
                ->execute(['id' => $patient['id']]);

            $pdo->prepare("INSERT INTO activity_log (action, ticket_number, performed_by) VALUES ('done', :t, 'staff')")
                ->execute(['t' => $ticketNumber]);

            $pdo->commit();
            jsonResponse(['success' => true, 'done' => $ticketNumber]);

        // ── Update severity (triage) ──
        case 'mark_severity':
            $ticketNumber = $body['ticket_number'] ?? '';
            $severity     = $body['severity'] ?? '';
            if (!in_array($severity, ['cat1','cat2','cat3'])) jsonResponse(['error' => 'Invalid severity'], 400);

            $pdo->prepare("UPDATE patients SET severity = :sev WHERE ticket_number = :t AND DATE(created_at) = CURDATE()")
                ->execute(['sev' => $severity, 't' => $ticketNumber]);

            jsonResponse(['success' => true]);

        // ── Skip / no-show ──
        case 'skip':
            $ticketNumber = $body['ticket_number'] ?? '';
            $pdo->prepare("UPDATE patients SET status = 'skipped', done_at = NOW() WHERE ticket_number = :t AND DATE(created_at) = CURDATE()")
                ->execute(['t' => $ticketNumber]);

            $pdo->prepare("UPDATE inside_log SET exited_at = NOW() WHERE patient_id = (SELECT id FROM patients WHERE ticket_number = :t LIMIT 1) AND exited_at IS NULL")
                ->execute(['t' => $ticketNumber]);

            $pdo->prepare("INSERT INTO activity_log (action, ticket_number, performed_by) VALUES ('skipped', :t, 'staff')")
                ->execute(['t' => $ticketNumber]);

            jsonResponse(['success' => true, 'skipped' => $ticketNumber]);

        // ── Reset daily queue ──
        case 'reset_daily':
            $pdo->exec("CALL reset_daily()");
            jsonResponse(['success' => true, 'message' => 'Queue reset for new day']);

        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
}
?>
