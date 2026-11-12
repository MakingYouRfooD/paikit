<?php
/**
 * api/security_alerts.php - In-App Security Alert Notification Endpoint
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$pdo = get_db();
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? ($_POST['action'] ?? 'check');

if ($action === 'check') {
    $alerts = get_unread_security_alerts($pdo, $userId);
    echo json_encode([
        'success'   => true,
        'has_alert' => !empty($alerts),
        'count'     => count($alerts),
        'alerts'    => $alerts
    ]);
    exit;
}

if ($action === 'dismiss') {
    $alertId = (int)($_POST['alert_id'] ?? ($_GET['alert_id'] ?? 0));
    if ($alertId > 0) {
        $ok = mark_security_alert_read($pdo, $alertId, $userId);
        echo json_encode(['success' => $ok]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid alert ID']);
    }
    exit;
}

if ($action === 'dismiss_all') {
    try {
        $stmt = $pdo->prepare("UPDATE security_alerts SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
exit;
