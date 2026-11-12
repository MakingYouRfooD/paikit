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

        if (strlen($newPassword) < 6) {
            $error = "Password must be at least 6 characters in length.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "Passwords do not match. Please ensure both fields are identical.";
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
                                    <input type="password" name="password" id="newPassword" class="form-control" placeholder="Minimum 6 characters" required minlength="6" autofocus>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility('newPassword', this)" title="Show / Hide Password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="confirmPassword" class="form-label fw-bold small text-dark">Confirm New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="Re-enter password" required minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility('confirmPassword', this)" title="Show / Hide Password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div id="passMatchMsg" class="form-text small" style="font-size: 0.75rem;"></div>
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

        function validatePasswords(e) {
            const p1 = document.getElementById('newPassword').value;
            const p2 = document.getElementById('confirmPassword').value;
            if (p1.length < 6) {
                alert('Password must be at least 6 characters long.');
                e.preventDefault();
                return false;
            }
            if (p1 !== p2) {
                alert('Passwords do not match. Please verify your new password.');
                e.preventDefault();
                return false;
            }
            return true;
        }

        const p1Input = document.getElementById('newPassword');
        const p2Input = document.getElementById('confirmPassword');
        const matchMsg = document.getElementById('passMatchMsg');

        function checkMatch() {
            if (!p2Input || !matchMsg) return;
            if (p2Input.value.length === 0) {
                matchMsg.textContent = '';
                return;
            }
            if (p1Input.value === p2Input.value) {
                matchMsg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Passwords match!</span>';
            } else {
                matchMsg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Passwords do not match.</span>';
            }
        }

        if (p1Input && p2Input) {
            p1Input.addEventListener('input', checkMatch);
            p2Input.addEventListener('input', checkMatch);
        }
    </script>
</body>
</html>
