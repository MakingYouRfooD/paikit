<?php
/**
 * reset_password.php - New Password Creation Portal
 * Paikit Health Center Information System
 * Powered by PHPMailer & Compliant with Data Privacy Act of 2012 (RA 10173)
 */

require_once __DIR__ . '/db.php';

$error = null;
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$isValidToken = false;
$resetRecord = null;

if (!empty($token)) {
    // Validate token exists and has not expired
    $nowTimestamp = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        SELECT pr.*, u.username, u.full_name, u.email AS user_email, u.role
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.user_id
        WHERE pr.token = ? AND pr.expires_at > ?
        LIMIT 1
    ");
    $stmt->execute([$token, $nowTimestamp]);
    $resetRecord = $stmt->fetch();
    if ($resetRecord) {
        $isValidToken = true;
    }
}

// Handle Password Reset Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation token expired. Please try again.";
    } elseif (!$isValidToken) {
        $error = "This password reset session has expired or is invalid. Please request a new link.";
    } else {
        $newPassword     = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword !== $confirmPassword) {
            $error = "Password and Confirm Password do not match.";
        } elseif (strlen($newPassword) < 8 
            || !preg_match('/[A-Z]/', $newPassword) 
            || !preg_match('/[a-z]/', $newPassword) 
            || !preg_match('/[0-9]/', $newPassword) 
            || !preg_match('/[\W_]/', $newPassword)) {
            $error = "Password must be at least 8 characters long and contain uppercase (A-Z), lowercase (a-z), numbers (0-9), and special characters (e.g. @, #, $, !).";
        } else {
            try {
                $pdo->beginTransaction();

                $userId = (int)$resetRecord['user_id'];
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                // Update user's password in database
                $stmtUp = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $stmtUp->execute([$newHash, $userId]);

                // Clear all reset tokens for this user
                $stmtDel = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $stmtDel->execute([$userId]);

                $pdo->commit();

                audit_log('PASSWORD_RESET_COMPLETED', "Password successfully reset for user #{$userId} ({$resetRecord['username']}).");

                header("Location: ./?msg=" . urlencode("Your password has been successfully updated! You can now log in with your new password."));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Password reset failed: " . $e->getMessage());
                $error = "An error occurred while updating your password. Please try again.";
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
    <title>Set New Password - Paikit Health Center</title>
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

                    <?php if (!$isValidToken): ?>
                        <!-- Invalid or Expired Token State -->
                        <div class="text-center">
                            <div class="icon-circle mb-3 shadow-sm bg-danger-subtle text-danger">
                                <i class="bi bi-exclamation-triangle-fill fs-2"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-2">Invalid or Expired Link</h4>
                            <p class="text-muted small mb-4">
                                This password reset link is invalid, has already been used, or has expired after 60 minutes for your account security.
                            </p>
                            <div class="d-grid gap-2">
                                <a href="forgot_password" class="btn btn-primary fw-bold py-2 shadow-sm">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Request a New Reset Link
                                </a>
                                <a href="./" class="btn btn-outline-secondary py-2">
                                    <i class="bi bi-box-arrow-in-right me-1"></i> Return to Sign In
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Valid Token Form -->
                        <div class="text-center mb-4">
                            <div class="icon-circle mb-3 shadow-sm">
                                <i class="bi bi-shield-lock-fill fs-2"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-1">Set a New Password</h4>
                            <p class="text-muted small mb-0">
                                Resetting password for: <strong><?= sanitize($resetRecord['email']) ?></strong>
                            </p>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show small" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= sanitize($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form action="reset_password?token=<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>" method="POST" id="resetPasswordForm" onsubmit="return validatePasswords(event)">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                            <div class="mb-3">
                                <label for="newPassword" class="form-label fw-bold small text-dark">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="bi bi-lock"></i></span>
                                    <input type="password" name="password" id="newPassword" class="form-control" placeholder="Minimum 8 characters" required minlength="8" autofocus oninput="checkPasswordRules()">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility('newPassword', this)" title="Show / Hide Password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="confirmPassword" class="form-label fw-bold small text-dark">Confirm New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="Re-enter password" required minlength="8" oninput="checkPasswordRules()">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility('confirmPassword', this)" title="Show / Hide Password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Real-time Password Security Checklist -->
                            <div class="p-2 mb-3 bg-light rounded border" style="font-size: 0.72rem;">
                                <div class="fw-bold text-muted mb-1"><i class="bi bi-shield-check me-1"></i> PASSWORD SECURITY REQUIREMENTS:</div>
                                <div class="row g-1">
                                    <div class="col-6" id="ruleLength"><i class="bi bi-x-circle text-danger me-1"></i> Min. 8 characters</div>
                                    <div class="col-6" id="ruleUpper"><i class="bi bi-x-circle text-danger me-1"></i> Uppercase letter (A-Z)</div>
                                    <div class="col-6" id="ruleLower"><i class="bi bi-x-circle text-danger me-1"></i> Lowercase letter (a-z)</div>
                                    <div class="col-6" id="ruleNumber"><i class="bi bi-x-circle text-danger me-1"></i> Number (0-9)</div>
                                    <div class="col-6" id="ruleSpecial"><i class="bi bi-x-circle text-danger me-1"></i> Special char (@#$!%...)</div>
                                    <div class="col-6" id="ruleMatch"><i class="bi bi-x-circle text-danger me-1"></i> Passwords match</div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm mb-3">
                                <i class="bi bi-check2-circle me-1"></i> Update Password & Sign In
                            </button>

                            <div class="text-center">
                                <a href="./" class="text-secondary small fw-semibold text-decoration-none">
                                    <i class="bi bi-arrow-left me-1"></i> Cancel & Return to Sign In
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
    <script>
        function togglePassVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input && icon) {
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.replace('bi-eye', 'bi-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.replace('bi-eye-slash', 'bi-eye');
                }
            }
        }

        function updateRuleBadge(id, valid, text) {
            const el = document.getElementById(id);
            if (!el) return;
            if (valid) {
                el.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> ' + text;
            } else {
                el.innerHTML = '<i class="bi bi-x-circle text-danger me-1"></i> ' + text;
            }
        }

        function checkPasswordRules() {
            const p1 = document.getElementById('newPassword') ? document.getElementById('newPassword').value : '';
            const p2 = document.getElementById('confirmPassword') ? document.getElementById('confirmPassword').value : '';

            const hasLength  = p1.length >= 8;
            const hasUpper   = /[A-Z]/.test(p1);
            const hasLower   = /[a-z]/.test(p1);
            const hasNumber  = /[0-9]/.test(p1);
            const hasSpecial = /[\W_]/.test(p1);
            const hasMatch   = p1.length > 0 && p1 === p2;

            updateRuleBadge('ruleLength', hasLength, 'Min. 8 characters');
            updateRuleBadge('ruleUpper', hasUpper, 'Uppercase letter (A-Z)');
            updateRuleBadge('ruleLower', hasLower, 'Lowercase letter (a-z)');
            updateRuleBadge('ruleNumber', hasNumber, 'Number (0-9)');
            updateRuleBadge('ruleSpecial', hasSpecial, 'Special char (@#$!%...)');
            updateRuleBadge('ruleMatch', hasMatch, 'Passwords match');

            return hasLength && hasUpper && hasLower && hasNumber && hasSpecial && hasMatch;
        }

        function validatePasswords(e) {
            if (!checkPasswordRules()) {
                alert('Please meet all password requirements (at least 8 characters, uppercase A-Z, lowercase a-z, number 0-9, special character, and matching confirmation).');
                e.preventDefault();
                return false;
            }
            return true;
        }
    </script>
</body>
</html>
