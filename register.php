<?php
// ============================================================
// api/register.php — Kiosk: register new patient, get ticket
// POST { "type": "priority"|"regular"|"followup" }
// ============================================================
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
$type = trim($body['type'] ?? '');

$validTypes = ['priority', 'regular', 'followup'];
if (!in_array($type, $validTypes)) {
    jsonResponse(['error' => 'Invalid patient type'], 400);
}

$prefix = match($type) {
    'priority' => 'P',
    'regular'  => 'R',
    'followup' => 'F',
};

$pdo = getDB();

try {
    $pdo->beginTransaction();

    // Get or create today's counter for this prefix
    $stmt = $pdo->prepare("
        INSERT INTO queue_counters (prefix, current_count, date_active)
        VALUES (:prefix, 1, CURDATE())
        ON DUPLICATE KEY UPDATE current_count = current_count + 1
    ");
    $stmt->execute(['prefix' => $prefix]);

    // Fetch updated count
    $stmt = $pdo->prepare("
        SELECT current_count FROM queue_counters
        WHERE prefix = :prefix AND date_active = CURDATE()
    ");
    $stmt->execute(['prefix' => $prefix]);
    $count = $stmt->fetchColumn();

    $ticketNumber = $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);

    // Count current waiting patients to set queue position
    $posStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE status IN ('waiting','inside','called') AND DATE(created_at) = CURDATE()");
    $posStmt->execute();
    $position = (int)$posStmt->fetchColumn() + 1;

    // Insert patient record
    $insertStmt = $pdo->prepare("
        INSERT INTO patients (ticket_number, patient_type, status, queue_position)
        VALUES (:ticket, :type, 'waiting', :pos)
    ");
    $insertStmt->execute([
        'ticket' => $ticketNumber,
        'type'   => $type,
        'pos'    => $position,
    ]);

    // Log activity
    $logStmt = $pdo->prepare("INSERT INTO activity_log (action, ticket_number, performed_by) VALUES ('registered', :ticket, 'kiosk')");
    $logStmt->execute(['ticket' => $ticketNumber]);

    $pdo->commit();

    jsonResponse([
        'success'       => true,
        'ticket_number' => $ticketNumber,
        'patient_type'  => $type,
        'position'      => $position,
        'message'       => "Your queue number is $ticketNumber",
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonResponse(['error' => 'Registration failed: ' . $e->getMessage()], 500);
}
?>
