<?php
/**
 * verify_email.php - Email Verification Portal
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config/mail.php';

$error = $_GET['error'] ?? null;
$success = $_GET['msg'] ?? null;
$email = trim($_GET['email'] ?? ($_POST['email'] ?? ''));
$token = trim($_GET['token'] ?? '');
$isVerifiedSuccess = false;

// 1. Direct Token Link Verification (User clicked link in email)
if (!empty($token)) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        SELECT ev.*, u.user_id, u.username, u.full_name, u.is_verified 
        FROM email_verifications ev
        JOIN users u ON ev.user_id = u.user_id
        WHERE ev.token = ? AND ev.expires_at > ?
        LIMIT 1
    ");
    $stmt->execute([$token, $now]);
    $verif = $stmt->fetch();

    if ($verif) {
        try {
            $pdo->beginTransaction();
            $userId = (int)$verif['user_id'];
            $targetEmail = $verif['email'];

            // Mark user account as verified
            $stmtUp = $pdo->prepare("UPDATE users SET is_verified = 1, email_verified_at = ? WHERE user_id = ?");
            $stmtUp->execute([$now, $userId]);

            // Clear all verification tokens for this user
            $stmtDel = $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?");
            $stmtDel->execute([$userId]);

            $pdo->commit();

            audit_log('EMAIL_VERIFIED_LINK', "Account '{$verif['username']}' verified email '{$targetEmail}' via secure link.", 'users', (string)$userId);

            $isVerifiedSuccess = true;
            $email = $targetEmail;
            $success = "Your email address (<strong>" . sanitize($targetEmail) . "</strong>) has been successfully verified! You may now sign in to your account.";
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Token verification error: " . $e->getMessage());
            $error = "An unexpected error occurred while verifying your email. Please try again.";
        }
    } else {
        // Check if user was already verified
        $stmtCheck = $pdo->prepare("SELECT user_id FROM email_verifications WHERE token = ?");
        $stmtCheck->execute([$token]);
        if ($stmtCheck->fetch()) {
            $error = "This verification link has expired (valid for 60 minutes). Please enter your email below to request a new verification code.";
        } else {
            $error = "This verification link is invalid or has already been used. Please sign in or request a new code.";
        }
    }
}

// 2. Handle POST Actions (Verify 6-digit OTP or Resend Code)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token expired. Please refresh the page and try again.";
    } else {
        $action = trim($_POST['action'] ?? 'verify_otp');
        $inputEmail = trim($_POST['email'] ?? '');

        if ($action === 'verify_otp') {
            $otpCode = preg_replace('/\D/', '', trim($_POST['otp_code'] ?? ''));

            if (empty($inputEmail) || !filter_var($inputEmail, FILTER_VALIDATE_EMAIL)) {
                $error = "Please provide a valid registered email address.";
            } elseif (strlen($otpCode) !== 6) {
                $error = "Please enter the complete 6-digit verification code sent to your email.";
            } else {
                $now = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("
                    SELECT ev.*, u.user_id, u.username, u.full_name, u.is_verified 
                    FROM email_verifications ev
                    JOIN users u ON ev.user_id = u.user_id
                    WHERE LOWER(ev.email) = LOWER(?) AND ev.otp_code = ? AND ev.expires_at > ?
                    LIMIT 1
                ");
                $stmt->execute([$inputEmail, $otpCode, $now]);
                $verif = $stmt->fetch();

                if ($verif) {
                    try {
                        $pdo->beginTransaction();
                        $userId = (int)$verif['user_id'];
                        $targetEmail = $verif['email'];

                        $stmtUp = $pdo->prepare("UPDATE users SET is_verified = 1, email_verified_at = ? WHERE user_id = ?");
                        $stmtUp->execute([$now, $userId]);

                        $stmtDel = $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?");
                        $stmtDel->execute([$userId]);

                        $pdo->commit();

                        audit_log('EMAIL_VERIFIED_OTP', "Account '{$verif['username']}' verified email '{$targetEmail}' via 6-digit OTP code.", 'users', (string)$userId);

                        $isVerifiedSuccess = true;
                        $email = $targetEmail;
                        $success = "Your email address (<strong>" . sanitize($targetEmail) . "</strong>) has been successfully verified! You may now sign in.";
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("OTP verification error: " . $e->getMessage());
                        $error = "An error occurred while verifying your code. Please try again.";
                    }
                } else {
                    // Check if already verified
                    $stmtUser = $pdo->prepare("SELECT user_id, is_verified FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                    $stmtUser->execute([$inputEmail]);
                    $existingUser = $stmtUser->fetch();

                    if ($existingUser && !empty($existingUser['is_verified'])) {
                        $isVerifiedSuccess = true;
                        $success = "Your account is already verified! You can proceed to sign in.";
                    } else {
                        $error = "Invalid or expired 6-digit verification code. Please check your email or click 'Resend Code' below.";
                    }
                }
            }
        } elseif ($action === 'resend_code') {
            if (empty($inputEmail) || !is_valid_deliverable_email($inputEmail, $emailValErr)) {
                $error = $emailValErr ?: "Please provide a valid, active registered email address to resend the code.";
            } else {
                $stmtUser = $pdo->prepare("SELECT user_id, username, full_name, email, is_verified FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $stmtUser->execute([$inputEmail]);
                $targetUser = $stmtUser->fetch();

                if (!$targetUser) {
                    // Privacy compliance: Generic confirmation
                    $success = "If an account matches <strong>" . sanitize($inputEmail) . "</strong>, a new verification code has been dispatched. Please check your inbox.";
                } elseif (!empty($targetUser['is_verified'])) {
                    $isVerifiedSuccess = true;
                    $success = "Your account is already verified! You can sign in immediately.";
                } else {
                    $userId = (int)$targetUser['user_id'];
                    $targetEmail = $targetUser['email'];
                    $displayName = !empty($targetUser['full_name']) ? $targetUser['full_name'] : $targetUser['username'];

                    // Check rate limiting (at least 45 seconds between resends)
                    $stmtRecent = $pdo->prepare("
                        SELECT created_at FROM email_verifications 
                        WHERE user_id = ? AND created_at > ? 
                        ORDER BY verification_id DESC LIMIT 1
                    ");
                    $coolDownTime = date('Y-m-d H:i:s', time() - 45);
                    $stmtRecent->execute([$userId, $coolDownTime]);
                    if ($stmtRecent->fetch()) {
                        $error = "Please wait a moment before requesting another verification code. Check your Gmail inbox and Spam folder.";
                    } else {
                        // Generate fresh OTP & Token
                        $otpCode = sprintf('%06d', random_int(100000, 999999));
                        $newToken = bin2hex(random_bytes(32));
                        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                        $stmtDel = $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?");
                        $stmtDel->execute([$userId]);

                        $stmtIns = $pdo->prepare("INSERT INTO email_verifications (user_id, email, otp_code, token, expires_at) VALUES (?, ?, ?, ?, ?)");
                        $stmtIns->execute([$userId, $targetEmail, $otpCode, $newToken, $expiresAt]);

                        // Build Absolute Verify Link
                        $protocol = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) 
                            ? $_SERVER['HTTP_X_FORWARDED_PROTO'] 
                            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http");
                        $host = $_SERVER['HTTP_HOST'];
                        $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                        $verifyLink = "{$protocol}://{$host}{$baseDir}/verify_email?token={$newToken}";

                        $mailResult = send_verification_email($targetEmail, $displayName, $otpCode, $verifyLink, 60);

                        if (!empty($mailResult['sent']) || !empty($mailResult['simulated'])) {
                            audit_log('VERIFICATION_CODE_RESENT', "Verification code resent to {$targetEmail}.", 'users', (string)$userId);
                            $success = "A fresh 6-digit verification code has been successfully dispatched to <strong>" . sanitize($targetEmail) . "</strong>. Please check your inbox (and spam folder).";
                        } else {
                            $error = "Failed to dispatch email: " . sanitize($mailResult['error'] ?? 'SMTP delivery error.');
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - Paikit Health Center</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/modern-theme.css">
    <script src="js/accessibility.js"></script>
    <style>
        body {
            background-color: #f8fafc;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .verif-card {
            border: 0;
            border-radius: var(--radius-xl);
            box-shadow: 0 10px 25px -5px rgba(21, 128, 61, 0.1), 0 8px 10px -6px rgba(21, 128, 61, 0.1);
        }
        .icon-circle {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #dcfce7;
            color: var(--primary);
        }
        .otp-input {
            letter-spacing: 10px;
            font-size: 26px;
            font-weight: 700;
            text-align: center;
            font-family: monospace;
        }
    </style>
</head>
<body>

    <!-- Minimal Header -->
    <header class="navbar navbar-dark patient-header shadow-sm py-2">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand d-flex align-items-center gap-2" href="./">
                <img src="img/logo.png" alt="Paikit Logo" class="brand-logo-circle" style="width: 40px; height: 40px;">
                <div>
                    <span class="d-block lh-1 text-white fw-bold fs-6">Paikit Health Center</span>
                    <span class="text-white-50 small" style="font-size: 0.72rem;">Primary Care & Consultation Portal</span>
                </div>
            </a>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="senior-toggle-btn" onclick="toggleSeniorMode()" title="Toggle larger text for seniors">
                    <i class="bi bi-fonts fs-6"></i> <strong>A+</strong> Senior Text
                </button>
                <a href="./" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Home
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="container my-auto py-5">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7 col-sm-10">

                <div class="card verif-card bg-white p-4 p-md-5">

                    <?php if ($isVerifiedSuccess): ?>
                        <!-- SUCCESS STATE -->
                        <div class="text-center">
                            <div class="icon-circle mb-3 shadow-sm bg-success-subtle text-success">
                                <i class="bi bi-patch-check-fill fs-1"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-2">Email Verified Successfully!</h4>
                            <div class="alert alert-success border-0 small mb-4 py-3">
                                <?= $success ?: "Your registered email has been verified. You can now access your Paikit Health Center patient account." ?>
                            </div>
                            <div class="d-grid gap-2">
                                <a href="./?action=login" class="btn btn-primary fw-bold py-2 shadow-sm">
                                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to Your Account
                                </a>
                                <a href="./" class="btn btn-outline-secondary py-2">
                                    <i class="bi bi-house me-1"></i> Return to Home
                                </a>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- VERIFICATION FORM STATE -->
                        <div class="text-center mb-4">
                            <div class="icon-circle mb-3 shadow-sm">
                                <i class="bi bi-envelope-check-fill fs-2"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-1">Verify Your Email</h4>
                            <p class="text-muted small mb-0">
                                Enter the 6-digit verification code sent to your registered email address before logging in.
                            </p>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show small" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= $error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show small" role="alert">
                                <i class="bi bi-check-circle-fill me-1"></i> <?= $success ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- OTP Verification Form -->
                        <form action="verify_email" method="POST" id="verifyOtpForm" class="mb-4">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="verify_otp">

                            <div class="mb-3">
                                <label for="emailInput" class="form-label fw-bold small text-dark">Registered Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" id="emailInput" class="form-control" 
                                           placeholder="e.g. juan.delacruz@gmail.com" required 
                                           value="<?= sanitize($email) ?>">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="otpCodeInput" class="form-label fw-bold small text-dark d-flex justify-content-between align-items-center">
                                    <span>6-Digit Verification Code</span>
                                    <span class="badge bg-light text-muted border">OTP</span>
                                </label>
                                <input type="text" name="otp_code" id="otpCodeInput" class="form-control otp-input" 
                                       placeholder="------" maxlength="6" pattern="[0-9]{6}" required autofocus 
                                       inputmode="numeric" autocomplete="one-time-code">
                                <div class="form-text small text-muted text-center mt-1">
                                    Check your Gmail inbox and Spam folder for the 6-digit code.
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm mb-3">
                                <i class="bi bi-shield-check me-1"></i> Verify & Activate Account
                            </button>
                        </form>

                        <!-- Resend Code Accordion / Section -->
                        <div class="border rounded-3 p-3 bg-light text-center">
                            <span class="small text-muted d-block mb-2">Didn't receive the verification email?</span>
                            <form action="verify_email" method="POST" id="resendForm" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="resend_code">
                                <input type="hidden" name="email" id="resendEmailHidden" value="<?= sanitize($email) ?>">
                                <button type="button" class="btn btn-sm btn-outline-success fw-bold" onclick="handleResendCode()">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Resend Verification Code
                                </button>
                            </form>
                        </div>

                        <div class="text-center mt-3">
                            <a href="./" class="text-secondary small fw-semibold text-decoration-none">
                                <i class="bi bi-arrow-left me-1"></i> Back to Sign In / Home
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="border-top pt-3 mt-4 text-center text-muted" style="font-size: 0.72rem;">
                        <i class="bi bi-shield-check text-success me-1"></i> Protected under Republic Act No. 10173 (Data Privacy Act of 2012).
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-top py-3 text-center text-muted small mt-auto">
        <div class="container">
            &copy; <?= date('Y') ?> Paikit Health Center. All rights reserved. Sitio Paikit, Canlubang, Calamba City, Laguna.
        </div>
    </footer>

    <script src="js/bootstrap.bundle.min.js"></script>
    <script>
        function handleResendCode() {
            const emailInput = document.getElementById('emailInput');
            const resendHidden = document.getElementById('resendEmailHidden');
            const form = document.getElementById('resendForm');
            if (emailInput && emailInput.value.trim() !== '') {
                resendHidden.value = emailInput.value.trim();
                form.submit();
            } else {
                alert('Please enter your registered email address first.');
                if (emailInput) emailInput.focus();
            }
        }

        // Auto-format OTP input
        const otpInput = document.getElementById('otpCodeInput');
        if (otpInput) {
            otpInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
            });
        }
    </script>
</body>
</html>