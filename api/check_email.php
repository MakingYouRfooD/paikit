<?php
/**
 * api/check_email.php - Real-Time Email Legitimacy & Duplicate Verification API
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173)
 */

header('Content-Type: application/json; charset=utf-8');

// Include central configuration & validators
require_once __DIR__ . '/../db.php';

$rawEmail = $_GET['email'] ?? ($_POST['email'] ?? '');
$email = strtolower(trim($rawEmail));
$excludeUserId = (int)($_GET['exclude_user_id'] ?? ($_POST['exclude_user_id'] ?? 0));

if (empty($email)) {
    echo json_encode([
        'status'     => 'empty',
        'valid'      => false,
        'registered' => false,
        'message'    => 'Email address is required.'
    ]);
    exit;
}

// 1. Comprehensive Email Legitimacy & Deliverability Check
// Checks syntax, obvious dummy names, repetitive characters, common typos (@gamil.com),
// disposable email services, and live DNS MX/A records.
$errorMessage = null;
if (!is_valid_deliverable_email($email, $errorMessage)) {
    echo json_encode([
        'status'     => 'invalid',
        'valid'      => false,
        'registered' => false,
        'message'    => $errorMessage ?: 'Please provide a valid, legitimate email address.'
    ]);
    exit;
}

// 2. Real-Time Database Duplicate Verification
$isRegistered = false;
$registeredRole = null;

try {
    $pdo = get_db();

    // Check users table
    $sqlUsers = "SELECT user_id, role, username FROM users WHERE LOWER(email) = LOWER(?)";
    $paramsUsers = [$email];
    if ($excludeUserId > 0) {
        $sqlUsers .= " AND user_id != ?";
        $paramsUsers[] = $excludeUserId;
    }
    $sqlUsers .= " LIMIT 1";

    $stmtUsers = $pdo->prepare($sqlUsers);
    $stmtUsers->execute($paramsUsers);
    $foundUser = $stmtUsers->fetch();

    if ($foundUser) {
        $isRegistered = true;
        $registeredRole = $foundUser['role'] ?? 'Client';
    } else {
        // Also check patients table for linked active accounts
        $sqlPat = "SELECT p.patient_id, u.user_id 
                   FROM patients p
                   LEFT JOIN users u ON u.patient_id = p.patient_id
                   WHERE LOWER(p.email) = LOWER(?) AND p.email != ''";
        $paramsPat = [$email];
        if ($excludeUserId > 0) {
            $sqlPat .= " AND (u.user_id IS NULL OR u.user_id != ?)";
            $paramsPat[] = $excludeUserId;
        }
        $sqlPat .= " LIMIT 1";

        $stmtPat = $pdo->prepare($sqlPat);
        $stmtPat->execute($paramsPat);
        $foundPat = $stmtPat->fetch();

        if ($foundPat && !empty($foundPat['user_id'])) {
            $isRegistered = true;
            $registeredRole = 'Client';
        }
    }
} catch (\Throwable $e) {
    // If DB check encounters an unexpected issue, log and proceed with valid status
    error_log("api/check_email.php database error: " . $e->getMessage());
}

if ($isRegistered) {
    echo json_encode([
        'status'     => 'registered',
        'valid'      => true,
        'registered' => true,
        'role'       => $registeredRole,
        'message'    => "The email '" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "' is already registered to an existing account. Please sign in or use Forgot Password."
    ]);
    exit;
}

// Email is legitimate, active, and available!
echo json_encode([
    'status'     => 'available',
    'valid'      => true,
    'registered' => false,
    'message'    => "Email address is valid and available for registration."
]);
exit;
