<?php
/**
 * client/queue_status_api.php
 * Real-time Live Queue Tracker API for Patient Mobile Dashboard
 * Paikit Health Center Information System
 */

require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Patient') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$patientId = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd   = $today . ' 23:59:59';

$encounterId = isset($_GET['encounter_id']) ? (int)$_GET['encounter_id'] : 0;

// If no encounter_id provided, find the active one for today
if ($encounterId <= 0) {
    $stmtAct = $pdo->prepare("
        SELECT encounter_id FROM encounters 
        WHERE patient_id = ? AND visit_date BETWEEN ? AND ? 
          AND queue_status IN ('Waiting', 'Serving')
        ORDER BY encounter_id DESC LIMIT 1
    ");
    $stmtAct->execute([$patientId, $todayStart, $todayEnd]);
    $row = $stmtAct->fetch();
    if ($row) {
        $encounterId = (int)$row['encounter_id'];
    }
}

if ($encounterId <= 0) {
    echo json_encode([
        'success'        => true,
        'has_active'     => false,
        'serving_number' => 'Standby'
    ]);
    exit;
}

$info = get_patient_live_queue_info($pdo, $encounterId, $todayStart, $todayEnd);

if (!$info) {
    echo json_encode([
        'success'    => true,
        'has_active' => false
    ]);
    exit;
}

echo json_encode([
    'success'         => true,
    'has_active'      => true,
    'encounter_id'    => $encounterId,
    'serving_number'  => $info['serving_number'],
    'my_number'       => $info['my_number'],
    'position'        => $info['position'],
    'ahead_count'     => $info['ahead_count'],
    'total_waiting'   => $info['total_waiting'],
    'is_priority'     => $info['is_priority'],
    'queue_status'    => $info['queue_status'],
    'check_in_status' => $info['check_in_status']
]);
