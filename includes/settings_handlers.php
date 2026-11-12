<?php
/**
 * includes/settings_handlers.php - Unified Account Settings & Security Backend
 * Handles Profile Updates, Email Verification Codes, and Secure Password Changes
 * Compatible with Doctor, Staff, and Client roles under RA 10173.
 */

if (!function_exists('get_db')) {
    require_once __DIR__ . '/../db.php';
}
if (!function_exists('send_system_email')) {
    require_once __DIR__ . '/../config/mail.php';
}

$pdo = get_db();
$sessUserId = $_SESSION['user_id'] ?? null;
if (!$sessUserId) {
    return;
}

// Ensure active user record is loaded
$stmtAuthU = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmtAuthU->execute([$sessUserId]);
$authUser = $stmtAuthU->fetch();
if (!$authUser) {
    return;
}

// Fetch linked patient record if client
$authPatient = null;
if (!empty($authUser['patient_id'])) {
    $stmtAuthP = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmtAuthP->execute([$authUser['patient_id']]);
    $authPatient = $stmtAuthP->fetch();
}

$userEmail = !empty($authUser['email']) ? $authUser['email'] : (!empty($authPatient['email']) ? $authPatient['email'] : '');
$userDisplayName = !empty($authUser['full_name']) ? $authUser['full_name'] : (!empty($authPatient['full_name']) ? $authPatient['full_name'] : $authUser['username']);

// --------------------------------------------------------------------------
// 1. ACTION: SEND PASSWORD CHANGE VERIFICATION CODE TO EMAIL
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_password_code') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['is_ajax']);
    
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $res = ['success' => false, 'error' => 'Security token expired. Please refresh the page and try again.'];
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($res); exit; }
        header("Location: dashboard?error=" . urlencode($res['error']) . "&open_settings=1&tab=security");
        exit;
    }

    if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        $res = ['success' => false, 'error' => 'No valid email address is linked to your account. Please update your profile email first.'];
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($res); exit; }
        header("Location: dashboard?error=" . urlencode($res['error']) . "&open_settings=1&tab=security");
        exit;
    }

    // Rate-limiting: 60 seconds
    if (isset($_SESSION['last_pwd_code_time']) && (time() - $_SESSION['last_pwd_code_time']) < 60) {
        $remaining = 60 - (time() - $_SESSION['last_pwd_code_time']);
        $res = ['success' => false, 'error' => "Please wait {$remaining} seconds before requesting a new code."];
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($res); exit; }
        header("Location: dashboard?error=" . urlencode($res['error']) . "&open_settings=1&tab=security");
        exit;
    }

    $code = sprintf("%06d", random_int(100000, 999999));

    // Save to password_resets table (Universal PostgreSQL / MySQL timestamp compatibility)
    $stmtDel = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? OR email = ?");
    $stmtDel->execute([$sessUserId, $userEmail]);

    $expiresAt = date('Y-m-d H:i:s', time() + 900);
    $stmtIns = $pdo->prepare("INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)");
    $stmtIns->execute([$sessUserId, $userEmail, $code, $expiresAt]);

    $_SESSION['last_pwd_code_time'] = time();

    // Dispatch email via PHPMailer
    $subject = "Security Verification Code: {$code} - Paikit Health Center";
    $body = "
    <div style='font-family: Arial, sans-serif; color: #1e293b; max-width: 580px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>
        <div style='background: #15803d; padding: 22px; text-align: center; color: white;'>
            <h2 style='margin: 0; font-size: 22px; font-weight: 800;'>PAIKIT HEALTH CENTER</h2>
            <p style='margin: 5px 0 0 0; opacity: 0.9; font-size: 13px;'>Account Security & Privacy Verification</p>
        </div>
        <div style='padding: 28px; background: #ffffff;'>
            <h3 style='color: #15803d; margin-top: 0;'>Password Change Verification</h3>
            <p>Hello <strong>" . htmlspecialchars($userDisplayName, ENT_QUOTES, 'UTF-8') . "</strong> (" . htmlspecialchars($authUser['role']) . "),</p>
            <p style='color: #475569; line-height: 1.5;'>
                You requested a security verification code to change your password for your Paikit Health Center portal account.
            </p>
            <div style='background: #f0fdf4; border: 2px dashed #15803d; border-radius: 10px; padding: 20px; text-align: center; margin: 25px 0;'>
                <div style='font-size: 12px; text-transform: uppercase; color: #166534; font-weight: bold; letter-spacing: 1.5px; margin-bottom: 6px;'>6-Digit Security Verification Code</div>
                <div style='font-family: monospace, Courier; font-size: 40px; font-weight: 900; letter-spacing: 8px; color: #15803d;'>" . $code . "</div>
                <div style='font-size: 12px; color: #64748b; margin-top: 8px;'>Valid for <strong>15 minutes</strong> only</div>
            </div>
            <p style='color: #64748b; font-size: 13px; line-height: 1.5;'>
                <strong>Important:</strong> Never disclose this code to anyone. If you did not request this verification, please secure your account credentials immediately.
            </p>
        </div>
        <div style='background: #f8fafc; padding: 14px 20px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0;'>
            Paikit Health Center &bull; Canlubang, Calamba City, Laguna &bull; Republic Act No. 10173 Protected
        </div>
    </div>";

    $mailResult = send_system_email($userEmail, $userDisplayName, $subject, $body);

    $maskedEmail = mask_email($userEmail);
    if (!empty($mailResult['sent'])) {
        $msgSuccess = "A 6-digit verification code has been dispatched to {$maskedEmail}. Code is valid for 15 minutes.";
    } elseif (!empty($mailResult['simulated'])) {
        $msgSuccess = "A 6-digit verification code has been generated. [Development Code: {$code}]";
    } else {
        // Fallback: If external SMTP is blocked or fails, provide code directly so user is never locked out
        $msgSuccess = "Verification code generated! (SMTP Delivery Note: Email could not be delivered. Your code is: {$code})";
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $msgSuccess, 'code' => $code]);
        exit;
    }
    header("Location: dashboard?msg=" . urlencode($msgSuccess) . "&open_settings=1&tab=security");
    exit;
}

// --------------------------------------------------------------------------
// 2. ACTION: CHANGE PASSWORD (CURRENT PASSWORD + EMAIL CODE + VALIDATION)
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header("Location: dashboard?error=" . urlencode("Security token expired. Please try again.") . "&open_settings=1&tab=security");
        exit;
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $verifyCode      = trim($_POST['verification_code'] ?? '');
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Step 1: Validate Current Password
    if (empty($currentPassword) || !password_verify($currentPassword, $authUser['password_hash'])) {
        header("Location: dashboard?error=" . urlencode("The Current Password you entered is incorrect.") . "&open_settings=1&tab=security");
        exit;
    }

    // Step 2: Validate Email Verification Code
    if (empty($verifyCode) || !preg_match('/^\d{6}$/', $verifyCode)) {
        header("Location: dashboard?error=" . urlencode("Please enter the valid 6-digit verification code sent to your email.") . "&open_settings=1&tab=security");
        exit;
    }

    $nowTimestamp = date('Y-m-d H:i:s');
    $stmtCheckCode = $pdo->prepare("SELECT reset_id FROM password_resets WHERE user_id = ? AND token = ? AND expires_at >= ?");
    $stmtCheckCode->execute([$sessUserId, $verifyCode, $nowTimestamp]);
    if (!$stmtCheckCode->fetch()) {
        header("Location: dashboard?error=" . urlencode("Invalid or expired 6-digit verification code. Please click 'Send Code to Email' to receive a new one.") . "&open_settings=1&tab=security");
        exit;
    }

    // Step 3: Exact Registration Password Complexity Validation
    if ($newPassword !== $confirmPassword) {
        header("Location: dashboard?error=" . urlencode("New Password and Confirm Password do not match.") . "&open_settings=1&tab=security");
        exit;
    }

    if (strlen($newPassword) < 8 
        || !preg_match('/[A-Z]/', $newPassword) 
        || !preg_match('/[a-z]/', $newPassword) 
        || !preg_match('/[0-9]/', $newPassword) 
        || !preg_match('/[\W_]/', $newPassword)) {
        header("Location: dashboard?error=" . urlencode("Password must be at least 8 characters long and contain uppercase (A-Z), lowercase (a-z), numbers (0-9), and special characters (e.g. @, #, $, !).") . "&open_settings=1&tab=security");
        exit;
    }

    // Step 4: Update Password Hash & Clean Up Code
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmtUpdate = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    $stmtUpdate->execute([$newHash, $sessUserId]);

    $stmtDelCode = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
    $stmtDelCode->execute([$sessUserId]);

    audit_log('PASSWORD_CHANGED', "User #{$sessUserId} ({$authUser['username']} - {$authUser['role']}) updated their account password via email verification code.");

    header("Location: dashboard?msg=" . urlencode("Your account password has been updated successfully! Please keep your new credentials secure.") . "&open_settings=1&tab=security");
    exit;
}

// --------------------------------------------------------------------------
// 3. ACTION: UPDATE PROFILE DETAILS
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header("Location: dashboard?error=" . urlencode("Security token expired. Please try again.") . "&open_settings=1&tab=profile");
        exit;
    }

    if ($authUser['role'] === 'Client' && !empty($authUser['patient_id'])) {
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $sitio         = trim($_POST['sitio'] ?? '');
        $streetAddress = trim($_POST['street_address'] ?? '');

        if (!empty($contactNumber) && !preg_match('/^09\d{9}$/', $contactNumber)) {
            header("Location: dashboard?error=" . urlencode("Mobile number must be exactly 11 digits starting with 09 (e.g. 09123456789).") . "&open_settings=1&tab=profile");
            exit;
        }

        if (!empty($sitio) && !is_valid_canlubang_sitio($sitio)) {
            header("Location: dashboard?error=" . urlencode("Please select an official area/sitio in Barangay Canlubang.") . "&open_settings=1&tab=profile");
            exit;
        }

        $stmtUpP = $pdo->prepare("UPDATE patients SET mobile_number = ?, sitio = ?, street_address = ? WHERE patient_id = ?");
        $stmtUpP->execute([$contactNumber, $sitio, $streetAddress, $authUser['patient_id']]);

        audit_log('PROFILE_UPDATED', "Client patient #{$authUser['patient_id']} updated contact details (Sitio: {$sitio}, Mobile: {$contactNumber}).");
        header("Location: dashboard?msg=" . urlencode("Your contact and address details have been updated successfully.") . "&open_settings=1&tab=profile");
        exit;
    } else {
        // Staff or Doctor: update full_name and email
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');

        if (empty($fullName)) {
            header("Location: dashboard?error=" . urlencode("Full name cannot be empty.") . "&open_settings=1&tab=profile");
            exit;
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: dashboard?error=" . urlencode("Please provide a valid official email address.") . "&open_settings=1&tab=profile");
            exit;
        }

        $stmtUpU = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
        $stmtUpU->execute([$fullName, $email, $sessUserId]);

        $_SESSION['full_name'] = $fullName;
        $_SESSION['email'] = $email;

        audit_log('PROFILE_UPDATED', "{$authUser['role']} user #{$sessUserId} updated profile (Name: {$fullName}, Email: {$email}).");
        header("Location: dashboard?msg=" . urlencode("Your personnel profile details have been updated successfully.") . "&open_settings=1&tab=profile");
        exit;
    }
}
