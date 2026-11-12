<?php
/**
 * forgot_password.php - Password Reset Request Portal
 * Paikit Health Center Information System
 * Powered by PHPMailer & Compliant with Data Privacy Act of 2012 (RA 10173)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config/mail.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation token expired. Please try again.";
    } else {
        $inputEmail = trim($_POST['email'] ?? '');

        if (empty($inputEmail) || !is_valid_deliverable_email($inputEmail, $emailValErr)) {
            $error = $emailValErr ?: "Please provide a valid registered email address.";
        } else {
            // Find user by email (in users table or joined with patients)
            $stmt = $pdo->prepare("
                SELECT u.user_id, u.username, u.email, u.full_name, p.full_name AS patient_name, p.email AS patient_email
                FROM users u
                LEFT JOIN patients p ON u.patient_id = p.patient_id
                WHERE u.email = ? OR p.email = ?
                LIMIT 1
            ");
            $stmt->execute([$inputEmail, $inputEmail]);
            $user = $stmt->fetch();

            if ($user) {
                $targetEmail = !empty($user['email']) ? $user['email'] : $user['patient_email'];
                $displayName = !empty($user['full_name']) ? $user['full_name'] : (!empty($user['patient_name']) ? $user['patient_name'] : $user['username']);
                $userId = (int)$user['user_id'];

                // Generate Cryptographically Secure 64-character token
                $token = bin2hex(random_bytes(32));
                // Expiry time: 60 minutes from now
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                // Invalidate any previous reset requests for this user
                $stmtDel = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $stmtDel->execute([$userId]);

                // Store new reset request
                $stmtInsert = $pdo->prepare("
                    INSERT INTO password_resets (user_id, email, token, expires_at) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmtInsert->execute([$userId, $targetEmail, $token, $expiresAt]);

                // Build Absolute Reset URL (Using HTTPS on Render / Production)
                $protocol = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) 
                    ? $_SERVER['HTTP_X_FORWARDED_PROTO'] 
                    : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http");
                $host = $_SERVER['HTTP_HOST'];
                $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $resetLink = "{$protocol}://{$host}{$baseDir}/reset_password?token={$token}";

                // Send email using PHPMailer / Live Dispatch
                $mailResult = send_password_reset_email($targetEmail, $displayName, $resetLink, 60);

                if (!empty($mailResult['sent'])) {
                    audit_log('PASSWORD_RESET_DISPATCHED', "Password reset email successfully delivered to {$targetEmail}.");
                    $success = "An official password reset email has been successfully sent to <strong>" . sanitize($targetEmail) . "</strong>. Please check your Gmail inbox (and spam folder).";
                } else {
                    audit_log('PASSWORD_RESET_FAILED', "Email dispatch failed to {$targetEmail}: " . ($mailResult['error'] ?? 'Unknown error'));
                    $error = "Unable to dispatch email: " . sanitize($mailResult['error'] ?? 'Network delivery failed. Please check SMTP/API configuration.');
                }
            } else {
                // RA 10173 Security: Generic confirmation to prevent email enumeration
                audit_log('PASSWORD_RESET_UNKNOWN_EMAIL', "Password reset attempted for unregistered email '{$inputEmail}'.");
                $success = "If an account matches that email address, an official password reset email has been dispatched. Please check your inbox.";
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
    <title>Forgot Password - Paikit Health Center</title>
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
        .reset-card {
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
                
                <div class="card reset-card bg-white p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="icon-circle mb-3 shadow-sm">
                            <i class="bi bi-key-fill fs-2"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">Forgot Your Password?</h4>
                        <p class="text-muted small mb-0">
                            Enter your registered email address and we'll send you a secure link to reset your account password.
                        </p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show small" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= $error ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success border-0 bg-success-subtle text-success p-4 rounded-4 mb-4 text-center">
                            <i class="bi bi-envelope-check-fill fs-1 d-block mb-2 text-success"></i>
                            <h5 class="fw-bold text-success mb-2">Check Your Email</h5>
                            <p class="small mb-0">
                                <?= $success ?>
                            </p>
                        </div>

                        <div class="text-center">
                            <a href="./" class="btn btn-outline-secondary w-100 fw-bold py-2 rounded-3">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Return to Sign In
                            </a>
                        </div>
                    <?php else: ?>
                        <form action="forgot_password" method="POST" id="forgotPasswordForm">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="mb-3">
                                <label for="emailInput" class="form-label fw-bold small text-dark">Registered Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" id="emailInput" class="form-control" placeholder="e.g. juan.delacruz@gmail.com" required autofocus value="<?= sanitize($_POST['email'] ?? '') ?>">
                                </div>
                                <div class="form-text small text-muted" style="font-size: 0.75rem;">
                                    We will dispatch a secure, single-use password reset link powered by PHPMailer.
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm mb-3">
                                <i class="bi bi-send me-1"></i> Send Reset Link
                            </button>

                            <div class="text-center">
                                <a href="./" class="text-secondary small fw-semibold text-decoration-none">
                                    <i class="bi bi-arrow-left me-1"></i> Back to Sign In
                                </a>
                            </div>
                        </form>
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
</body>
</html>
