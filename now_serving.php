<?php
// ============================================================
// api/now_serving.php — TV Display: lightweight polling endpoint
// GET → returns current ticket being served + next 5 in queue
// ============================================================
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store');

$pdo = getDB();

$nsStmt = $pdo->prepare("SELECT ticket_number, patient_type, updated_at FROM now_serving WHERE id = 1");
$nsStmt->execute();
$nowServing = $nsStmt->fetch();

$nextStmt = $pdo->prepare("
    SELECT ticket_number, patient_type, severity
    FROM patients
    WHERE status = 'waiting' AND DATE(created_at) = CURDATE()
    ORDER BY
        CASE severity WHEN 'cat3' THEN 0 ELSE 1 END ASC,
        FIELD(patient_type, 'priority', 'regular', 'followup') ASC,
        created_at ASC
    LIMIT 6
");
$nextStmt->execute();
$nextInLine = $nextStmt->fetchAll();

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

$servedStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE status = 'done' AND DATE(created_at) = CURDATE()");
$servedStmt->execute();
$totalServed = (int)$servedStmt->fetchColumn();

jsonResponse([
    'now_serving'   => $nowServing,
    'next_in_line'  => $nextInLine,
    'counts'        => $counts,
    'total_served'  => $totalServed,
    'server_time'   => date('H:i:s'),
]);
?>
