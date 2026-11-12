<?php
/**
 * logout.php - Safe Session Termination & Audit Log
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173)
 */

require_once __DIR__ . '/db.php';

if (is_logged_in()) {
    $auth = get_auth_user();
    $username = $auth['username'] ?? 'User';
    $role = $auth['role'] ?? 'User';
    audit_log('LOGOUT', "User '{$username}' logged out.");
}

// Clear all session data
$_SESSION = [];

// Invalidate session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// Redirect to root index
header("Location: index?msg=" . urlencode("You have safely logged out."));
exit;
