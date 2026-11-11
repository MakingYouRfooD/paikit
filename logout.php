<?php
require_once __DIR__ . '/db.php';

if (is_logged_in()) {
    audit_log('LOGOUT', "User '{$_SESSION['username']}' logged out.");
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header("Location: ./?msg=" . urlencode("You have been successfully logged out."));
exit;
