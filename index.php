<?php
/**
 * index.php - Main Landing Page & Portal Gateway
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173)
 */

require_once __DIR__ . '/db.php';

$error = $_GET['error'] ?? null;
$msg   = $_GET['msg'] ?? null;
$errorScope = '';

// Handle Login Submission (Strict RBAC Routing via Email or Phone Number)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $errorScope = 'login';
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
        $loginInput = trim($_POST['email'] ?? ($_POST['login_identifier'] ?? ''));
        $password   = $_POST['password'] ?? '';

        if (empty($loginInput) || empty($password)) {
            $error = "Please enter your Email or Phone Number and Password.";
        } else {
            $normPhone = normalize_phone($loginInput);
            $isEmail   = (filter_var($loginInput, FILTER_VALIDATE_EMAIL) !== false);
            $isPhone   = is_valid_ph_mobile($loginInput);

            if (!$isEmail && !$isPhone) {
                $error = "Please enter a valid Email address or 11-digit Mobile Number (e.g. 09xxxxxxxxx).";
            } else {
                // Strict RBAC Authentication: Only Email or Phone Number allowed
                $stmt = $pdo->prepare("
                    SELECT u.*, p.full_name AS patient_name, p.mobile_number, p.email AS patient_email 
                    FROM users u 
                    LEFT JOIN patients p ON u.patient_id = p.patient_id 
                    WHERE LOWER(u.email) = LOWER(?) 
                       OR (LOWER(p.email) = LOWER(?) AND p.email != '')
                       OR (p.mobile_number = ? AND p.mobile_number != '')
                    LIMIT 1
                ");
                $stmt->execute([$loginInput, $loginInput, $normPhone]);
                $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Enforce email verification strictly for Client accounts before permitting login
                $isVerified = isset($user['is_verified']) ? (int)$user['is_verified'] : 1;
                if ($user['role'] === 'Client' && $isVerified === 0) {
                    $targetEmail = !empty($user['email']) ? $user['email'] : (!empty($user['patient_email']) ? $user['patient_email'] : $loginInput);
                    audit_log('LOGIN_BLOCKED_UNVERIFIED', "User '{$user['username']}' attempted login without verified email ({$targetEmail}).", 'users', (string)$user['user_id']);
                    header("Location: verify_email?email=" . urlencode($targetEmail) . "&error=" . urlencode("Your email address is not verified yet. Please enter the 6-digit verification code sent to your email or request a new code before signing in."));
                    exit;
                }

                // Check Single Active Device Constraint (Strict Concurrent Login Prevention)
                $concurrentCheck = check_concurrent_device_login($pdo, (int)$user['user_id']);
                if ($concurrentCheck['blocked']) {
                    $targetEmail   = !empty($user['email']) ? $user['email'] : (!empty($user['patient_email']) ? $user['patient_email'] : '');
                    $displayName   = $user['full_name'] ?: ($user['patient_name'] ?: $user['username']);
                    $attemptIp     = get_client_ip();
                    $attemptDevice = get_device_description();
                    $activeDevice  = $concurrentCheck['device'] ?: 'Another device';

                    // 1. RA 10173 Audit Logging
                    audit_log(
                        'LOGIN_BLOCKED_CONCURRENT_DEVICE', 
                        "Concurrent login attempt blocked for user '{$user['username']}'. Currently active on '{$activeDevice}' ({$concurrentCheck['ip']}). Attempted from: '{$attemptDevice}' ({$attemptIp}).", 
                        'users', 
                        (string)$user['user_id']
                    );

                    // 2. Insert In-App Alert Notification for Account Owner
                    $alertMsg = "An attempt was made to sign in to your account from another device ({$attemptDevice}) from IP {$attemptIp}. It was blocked by the system for your protection.";
                    record_security_alert($pdo, (int)$user['user_id'], 'CONCURRENT_LOGIN_BLOCKED', $alertMsg, $attemptIp, $attemptDevice);

                    // 3. Dispatch Security Alert Email to Account Owner
                    if (!empty($targetEmail)) {
                        require_once __DIR__ . '/config/mail.php';
                        send_security_alert_email($targetEmail, $displayName, [
                            'attempt_ip'     => $attemptIp,
                            'attempt_device' => $attemptDevice,
                            'active_device'  => $activeDevice,
                            'attempt_time'   => date('F j, Y, g:i A \P\S\T')
                        ]);
                    }

                    // 4. Present clear blocked notification to attempting user
                    $errorScope = 'login';
                    $error = "Temporarily blocked: This account is currently logged in on another device (" . sanitize($activeDevice) . "). Concurrent logins on two different devices are prohibited for your data security. A security alert notification has been sent to your registered email.";
                } else {
                    // Regenerate session ID to prevent session fixation attacks
                    session_regenerate_id(true);

                    $_SESSION['user_id']    = $user['user_id'];
                    $_SESSION['username']   = $user['username'];
                    $_SESSION['email']      = $user['email'] ?? '';
                    $_SESSION['role']       = $user['role'];
                    $_SESSION['patient_id'] = $user['patient_id'];
                    $_SESSION['full_name']  = $user['full_name'] ?: ($user['patient_name'] ?: $user['username']);

                    // Bind active device session in database
                    record_active_login($pdo, (int)$user['user_id']);

                    audit_log('LOGIN_SUCCESS', "User '{$user['username']}' ({$user['email']}) authenticated as {$user['role']} on " . get_device_description() . ".");

                    $displayName = $user['full_name'] ?: ($user['patient_name'] ?: $user['username']);
                    $welcomeMsg  = get_time_based_welcome($displayName);

                    // Strict RBAC Redirection with time-based greeting (Clean URLs)
                    switch ($user['role']) {
                        case 'Doctor':
                            header("Location: doctor/dashboard?msg=" . urlencode($welcomeMsg));
                            exit;
                        case 'Staff':
                            header("Location: staff/dashboard?msg=" . urlencode($welcomeMsg));
                            exit;
                        case 'Client':
                            header("Location: client/dashboard?msg=" . urlencode($welcomeMsg));
                            exit;
                        default:
                            header("Location: ./?msg=" . urlencode($welcomeMsg));
                            exit;
                    }
                }
            } else {
                audit_log('LOGIN_FAILURE', "Failed login attempt for identifier '{$loginInput}'.");
                $error = "Invalid credentials. Please verify your Email or Phone Number and Password, or click 'Forgot Password?'.";
            }
        }
    }
}
}

// Handle Patient Sign-Up (Strictly Client Role)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_signup'])) {
    $errorScope = 'signup';
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
        $rawMobile       = trim($_POST['mobile_number'] ?? '');
        $mobile          = normalize_phone($rawMobile);
        $email           = trim($_POST['email'] ?? '');
        $firstName       = trim($_POST['first_name'] ?? '');
        $middleName      = trim($_POST['middle_name'] ?? '');
        $lastName        = trim($_POST['last_name'] ?? '');
        $suffix          = trim($_POST['suffix'] ?? '');
        
        // Assemble Full Name for display and backwards compatibility
        $nameParts       = array_filter([$firstName, $middleName, $lastName]);
        $fullName        = implode(' ', $nameParts);
        if (!empty($suffix)) {
            $fullName .= ' ' . $suffix;
        }

        $birthDate       = trim($_POST['birth_date'] ?? '');
        $gender          = trim($_POST['gender'] ?? 'Other');
        $sitio           = trim($_POST['sitio'] ?? '');
        $streetAddress   = trim($_POST['street_address'] ?? '');
        $username        = trim($_POST['username'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $todayStr = date('Y-m-d');
        if (empty($mobile) || empty($email) || empty($firstName) || empty($lastName) || empty($birthDate) || empty($sitio) || empty($username) || empty($password) || empty($confirmPassword)) {
            $error = "Please enter your First Name, Last Name, and all required registration details.";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) || $birthDate > $todayStr || $birthDate < '1900-01-01') {
            $error = "Invalid birth date: Please select a valid date between January 1, 1900 and today.";
        } elseif (!is_valid_person_name($firstName, 2)) {
            $error = "Invalid First Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters.";
        } elseif (!empty($middleName) && !is_valid_person_name($middleName, 2)) {
            $error = "Invalid Middle Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters.";
        } elseif (!is_valid_person_name($lastName, 2)) {
            $error = "Invalid Last Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters.";
        } elseif (!is_valid_canlubang_sitio($sitio)) {
            $error = "Permitted addresses are official areas in Canlubang only: Asia 1, Asia 2, Carmel Housing, Ceris 1 & 2, GK, Manfil, Mcdc, Paikit, or Palao.";
        } elseif (!is_valid_ph_mobile($mobile)) {
            $error = "Mobile number must be a valid 11-digit Philippine mobile number starting with 09 or 08 (e.g. 09171234567).";
        } elseif (!is_valid_deliverable_email($email, $emailError)) {
            $error = $emailError ?: "Please enter a valid, active personal email address (e.g. juan.delacruz@gmail.com).";
        } elseif ($password !== $confirmPassword) {
            $error = "Password and Confirm Password do not match.";
        } elseif (strlen($password) < 8 
            || !preg_match('/[A-Z]/', $password) 
            || !preg_match('/[a-z]/', $password) 
            || !preg_match('/[0-9]/', $password) 
            || !preg_match('/[\W_]/', $password)) {
            $error = "Password must be at least 8 characters long and contain uppercase (A-Z), lowercase (a-z), numbers (0-9), and special characters (e.g. @, #, $, !).";
        } else {
            // Check 1: Username uniqueness
            $stmtUserCheck = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(username) = LOWER(?)");
            $stmtUserCheck->execute([$username]);
            if ($stmtUserCheck->fetch()) {
                $error = "The username '{$username}' is already taken. Please choose another username.";
            } else {
                // Check 2: Email uniqueness
                $stmtEmailCheck = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(?)");
                $stmtEmailCheck->execute([$email]);
                if ($stmtEmailCheck->fetch()) {
                    $error = "The email address '" . sanitize($email) . "' is already registered to an existing account. Please sign in or use Forgot Password.";
                } else {
                    // Check 3: Mobile number rules and walk-in linking
                    // Rule: If mobile number is already linked to an active user account or registered_client,
                    // it is STRICTLY PROHIBITED to register it to another email/account.
                    // It is ONLY permitted to link when the mobile number belongs to an unlinked walk-in guest (created by staff without an online account).
                    $stmtPatCheck = $pdo->prepare("
                        SELECT p.patient_id, p.account_type, p.email, u.user_id, u.username, u.email AS user_email 
                        FROM patients p 
                        LEFT JOIN users u ON p.patient_id = u.patient_id 
                        WHERE p.mobile_number = ?
                        LIMIT 1
                    ");
                    $stmtPatCheck->execute([$mobile]);
                    $existingPat = $stmtPatCheck->fetch(PDO::FETCH_ASSOC);

                    if ($existingPat && (!empty($existingPat['user_id']) || $existingPat['account_type'] === 'registered_client')) {
                        $error = "This mobile number (" . sanitize($mobile) . ") is already registered to an active patient account. Each mobile number can only be linked to one account. If this is your account, please sign in using your credentials or use Forgot Password.";
                    } else {
                        try {
                            $pdo->beginTransaction();

                            if ($existingPat && empty($existingPat['user_id'])) {
                                // Mobile belongs to a walk-in patient created by staff without an online account.
                                // Link the online account to the existing walk-in medical record!
                                $patientId = (int)$existingPat['patient_id'];
                                $stmtUp = $pdo->prepare("
                                    UPDATE patients 
                                    SET full_name = ?, first_name = ?, middle_name = ?, last_name = ?, suffix = ?, 
                                        email = ?, birth_date = ?, gender = ?, sitio = ?, street_address = ?, account_type = 'registered_client' 
                                    WHERE patient_id = ?
                                ");
                                $stmtUp->execute([$fullName, $firstName, $middleName, $lastName, $suffix, $email, $birthDate, $gender, $sitio, $streetAddress, $patientId]);
                            } else {
                                // Brand new patient
                                $stmtNewPat = $pdo->prepare("
                                    INSERT INTO patients 
                                    (mobile_number, email, full_name, first_name, middle_name, last_name, suffix, birth_date, gender, sitio, street_address, account_type) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'registered_client')
                                ");
                                $stmtNewPat->execute([$mobile, $email, $fullName, $firstName, $middleName, $lastName, $suffix, $birthDate, $gender, $sitio, $streetAddress]);
                                $patientId = (int)$pdo->lastInsertId();
                            }

                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            $stmtNewUser = $pdo->prepare("INSERT INTO users (patient_id, username, full_name, email, password_hash, role, is_verified) VALUES (?, ?, ?, ?, ?, 'Client', 0)");
                            $stmtNewUser->execute([$patientId, $username, $fullName, $email, $passwordHash]);
                            $newUserId = (int)$pdo->lastInsertId();

                            // Generate cryptographically secure OTP & Token for Email Verification
                            $otpCode = sprintf('%06d', random_int(100000, 999999));
                            $token = bin2hex(random_bytes(32));
                            $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                            // Invalidate any previous verifications for this user ID
                            $stmtDelVerif = $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?");
                            $stmtDelVerif->execute([$newUserId]);

                            // Store verification record
                            $stmtInsVerif = $pdo->prepare("
                                INSERT INTO email_verifications (user_id, email, otp_code, token, expires_at) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmtInsVerif->execute([$newUserId, $email, $otpCode, $token, $expiresAt]);

                            $pdo->commit();

                            // Build Absolute Verification URL
                            $protocol = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) 
                                ? $_SERVER['HTTP_X_FORWARDED_PROTO'] 
                                : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http");
                            $host = $_SERVER['HTTP_HOST'];
                            $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                            $verifyLink = "{$protocol}://{$host}{$baseDir}/verify_email?token={$token}";

                            // Dispatch Verification Email
                            require_once __DIR__ . '/config/mail.php';
                            $mailResult = send_verification_email($email, $fullName, $otpCode, $verifyLink, 60);

                            audit_log('PATIENT_SELF_REGISTERED', "Patient {$fullName} registered account '{$username}' (Email: {$email}, Mobile: {$mobile}). Email verification pending.", 'users', (string)$newUserId);

                            // Redirect user to email verification portal (strictly no auto-login before email verification)
                            header("Location: verify_email?email=" . urlencode($email) . "&msg=" . urlencode("Registration successful! We have sent a 6-digit verification code to " . $email . ". Please verify your email before logging in."));
                            exit;
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            error_log("Patient sign up error: " . $e->getMessage());
                            $error = "Registration failed. Please check your inputs.";
                        }
                    }
                }
            }
        }
    }
}

// Fetch Today's Duty Schedule & Clinic Statistics
$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd   = $today . ' 23:59:59';

$attendingDoctor = get_attending_physician($pdo);

$stmtSched = $pdo->prepare("SELECT * FROM doctor_schedules WHERE duty_date = ? ORDER BY schedule_id DESC LIMIT 1");
$stmtSched->execute([$today]);
$schedule = $stmtSched->fetch();

// Queue Summary for Today
$stmtCounts = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_today,
        SUM(CASE WHEN queue_status = 'Waiting' THEN 1 ELSE 0 END) AS count_waiting,
        SUM(CASE WHEN queue_status = 'Serving' THEN 1 ELSE 0 END) AS count_serving,
        SUM(CASE WHEN queue_status = 'Completed' THEN 1 ELSE 0 END) AS count_completed
    FROM encounters
    WHERE visit_date BETWEEN ? AND ?
");
$stmtCounts->execute([$todayStart, $todayEnd]);
$stats = $stmtCounts->fetch() ?: ['total_today' => 0, 'count_waiting' => 0, 'count_serving' => 0, 'count_completed' => 0];

// Currently Serving Patient Queue Number
$stmtServing = $pdo->prepare("SELECT queue_number FROM encounters WHERE visit_date BETWEEN ? AND ? AND queue_status = 'Serving' ORDER BY encounter_id DESC LIMIT 1");
$stmtServing->execute([$todayStart, $todayEnd]);
$servingTicket = $stmtServing->fetchColumn() ?: 'None';

$maxSlots = $schedule ? (int)$schedule['max_slots'] : 50;
$slotsTaken = (int)$stats['total_today'];
$slotsRemaining = max(0, $maxSlots - $slotsTaken);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paikit Health Center - Rural Health Unit Portal</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/modern-theme.css">
    <script src="js/accessibility.js"></script>
    <style>
        .hero-banner {
            padding: 4rem 1rem 3.5rem;
            position: relative;
        }
        .slip-search-box {
            background: #ffffff;
            border-radius: var(--radius-full);
            padding: 6px 8px;
            box-shadow: 0 16px 36px -4px rgba(20, 83, 45, 0.35);
            max-width: 660px;
            margin: 0 auto;
            border: 2px solid rgba(255, 255, 255, 0.4);
            transition: all 0.2s ease;
        }
        .slip-search-box:focus-within {
            border-color: #22c55e;
            box-shadow: 0 16px 40px -2px rgba(22, 128, 61, 0.45);
        }
        .service-card {
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-card);
            transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .service-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-elevated);
        }
        .now-serving-badge {
            font-size: 2.4rem;
            font-weight: 800;
            letter-spacing: 1px;
            color: #e11d48;
        }
        @keyframes floatDownAlert {
            0% { opacity: 0; transform: translateY(-8px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        #modalFloatingAlert {
            animation: floatDownAlert 0.25s ease-out;
            background-color: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #ef4444 !important;
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.18) !important;
        }
    </style>
</head>
<body>

<!-- Header Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark main-header shadow-sm sticky-top">
    <div class="container">
        <!-- Brand with Official Logo -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="./">
            <img src="img/logo.png" alt="Paikit Health Center Logo" class="brand-logo-circle">
            <div>
                <span class="d-block lh-1 text-white fw-bold fs-5">Paikit Health Center</span>
                <span class="small text-white-50" style="font-size: 0.72rem; letter-spacing: 0.5px;">Brgy. Canlubang, Calamba City</span>
            </div>
        </a>

        <!-- Header Action Controls & Senior Friendly Toggle -->
        <div class="d-flex align-items-center gap-2 ms-auto">
            <!-- Senior Accessibility Mode Button -->
            <button type="button" class="senior-toggle-btn me-1" onclick="toggleSeniorMode()" title="Enlarge text size for senior citizens">
                <i class="bi bi-fonts fs-6"></i> <strong>A+</strong> Senior Text
            </button>

            <?php if (is_logged_in()): ?>
                    <?php 
                        $dashUrl = ($_SESSION['role'] === 'Doctor') ? 'doctor/dashboard' : (($_SESSION['role'] === 'Staff') ? 'staff/dashboard' : 'client/dashboard');
                        $badgeColor = ($_SESSION['role'] === 'Doctor') ? 'bg-info text-dark' : (($_SESSION['role'] === 'Staff') ? 'bg-success' : 'bg-warning text-dark');
                    ?>
                    <div class="dropdown">
                        <button class="btn btn-light btn-sm fw-bold dropdown-toggle d-flex align-items-center gap-1 shadow-sm" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle text-primary"></i>
                            <span><?= sanitize($_SESSION['full_name']) ?></span>
                            <span class="badge <?= $badgeColor ?> ms-1"><?= sanitize($_SESSION['role']) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li>
                                <a class="dropdown-item fw-bold" href="<?= $dashUrl ?>">
                                    <i class="bi bi-speedometer2 me-2 text-primary"></i> Go to <?= sanitize($_SESSION['role']) ?> Dashboard
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="logout">
                                    <i class="bi bi-box-arrow-right me-2"></i> Log Out
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <!-- Clean Login / Register Button in Header -->
                    <button type="button" class="btn btn-light btn-sm fw-bold px-3 py-2 shadow-sm d-flex align-items-center gap-2 rounded-pill border-0" data-bs-toggle="modal" data-bs-target="#loginModal">
                        <i class="bi bi-person-fill text-success"></i>
                        <span class="text-success fw-bold">Login / Register</span>
                    </button>
                <?php endif; ?>
            </div>
    </div>
</nav>

<!-- Hero Section with Official Logo Emblem -->
<header class="hero-banner text-center shadow-sm text-white">
    <div class="container">
        <div class="d-flex justify-content-center mb-3">
            <img src="img/logo.png" alt="Paikit Health Center Official Logo" class="brand-logo-large shadow">
        </div>
        <span class="badge bg-success text-uppercase px-3 py-2 mb-3 rounded-pill shadow-sm" style="border: 1px solid #86efac;">
            <i class="bi bi-shield-check me-1"></i> Republic Act No. 10173 (Data Privacy Act of 2012) Compliant
        </span>
        <h1 class="display-5 fw-bold mb-2">Paikit Health Center Information System</h1>
        <p class="lead text-light mb-4" style="max-width: 750px; margin: 0 auto; opacity: 0.95;">
            Barangay Canlubang, Calamba City, Laguna &bull; Primary Care, Community Health, and Fast Queue Services.
        </p>

        <!-- Clean Walk-in Consultation Slip Search -->
        <div class="slip-search-box mb-4">
            <form action="client/view_record" method="GET" class="d-flex align-items-center">
                <span class="ps-3 pe-2 text-muted">
                    <i class="bi bi-receipt-cutoff fs-5 text-primary"></i>
                </span>
                <input type="text" name="code" class="form-control border-0 shadow-none text-uppercase fw-bold" placeholder="Enter Your Transaction Code" required style="font-size: 0.95rem;">
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold text-nowrap">
                    <i class="bi bi-search me-1"></i> View My Record
                </button>
            </form>
        </div>
        <small class="text-white-50 d-block">
            Enter your official transaction code to immediately access digital diagnoses & e-prescriptions.
        </small>

        <!-- Clinic Real-Time Overview Row -->
        <div class="row g-3 justify-content-center text-dark text-start mt-4">
            <div class="col-lg-3 col-md-4">
                <div class="card border-0 shadow-sm h-100 bg-white p-3 rounded-3">
                    <div class="d-flex align-items-center mb-1">
                        <i class="bi bi-calendar2-check text-primary fs-4 me-2"></i>
                        <span class="text-muted small fw-bold">ATTENDING PHYSICIAN</span>
                    </div>
                    <div class="fw-bold fs-6 text-dark"><?= sanitize($attendingDoctor['full_name']) ?></div>
                    <div class="text-secondary small">
                        <?= $schedule ? date('g:i A', strtotime($schedule['start_time'])) . ' - ' . date('g:i A', strtotime($schedule['end_time'])) : '08:00 AM - 05:00 PM' ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-4">
                <div class="card border-0 shadow-sm h-100 bg-white p-3 rounded-3">
                    <div class="d-flex align-items-center mb-1">
                        <i class="bi bi-ticket-perforated text-success fs-4 me-2"></i>
                        <span class="text-muted small fw-bold">DAILY CAPACITY QUOTA</span>
                    </div>
                    <div class="fw-bold fs-6">
                        <span class="text-success"><?= $slotsRemaining ?> Available</span> / <?= $maxSlots ?> Max
                    </div>
                    <div class="progress mt-2" style="height: 6px;">
                        <?php $pct = $maxSlots > 0 ? min(100, round(($slotsTaken / $maxSlots) * 100)) : 0; ?>
                        <div class="progress-bar bg-success" style="width: <?= $pct ?>%;"></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-4">
                <div class="card border-0 shadow-sm h-100 bg-white p-3 rounded-3">
                    <div class="d-flex align-items-center mb-1">
                        <i class="bi bi-broadcast text-danger fs-4 me-2"></i>
                        <span class="text-muted small fw-bold">NOW SERVING QUEUE</span>
                    </div>
                    <div class="now-serving-badge">
                        <?= sanitize($servingTicket) ?>
                    </div>
                    <div class="text-muted small">
                        Waiting in line: <strong><?= (int)$stats['count_waiting'] ?> patients</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="container my-5">

    <!-- Flash Alerts (Errors as alert, Messages as Modal) -->
    <?php if ($error && empty($errorScope)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= sanitize($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="modal fade" id="welcomeMsgModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-center p-4">
                    <div class="modal-body p-2">
                        <div class="mb-3">
                            <span class="badge rounded-circle p-3 shadow-sm bg-success-subtle text-success">
                                <i class="bi bi-check-circle-fill fs-1"></i>
                            </span>
                        </div>
                        <h4 class="fw-bold text-dark mb-2">
                            <?= (stripos($msg, 'welcome') !== false || stripos($msg, 'good') !== false) ? 'Welcome!' : 'Paikit Health Center' ?>
                        </h4>
                        <p class="text-secondary fs-6 mb-4 px-2">
                            <?= sanitize($msg) ?>
                        </p>
                        <button type="button" class="btn btn-primary w-100 fw-bold py-2 rounded-pill shadow-sm" data-bs-dismiss="modal">
                            <i class="bi bi-arrow-right-circle me-1"></i> Continue
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Health Center Core Services Grid -->
    <div class="mb-5">
        <div class="text-center mb-4">
            <span class="badge bg-primary-subtle text-primary text-uppercase px-3 py-2 rounded-pill fw-bold">Services & Outpatient Care</span>
            <h3 class="fw-bold text-dark mt-2">Comprehensive Primary Health Services</h3>
            <p class="text-muted small">Barangay Paikit Rural Health Unit delivers accessible public healthcare with privacy protection.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <!-- 1. General Consultation -->
            <div class="col-lg-4 col-md-6">
                <div class="service-card p-4 h-100">
                    <div class="badge bg-primary-subtle text-primary p-3 rounded-circle mb-3">
                        <i class="bi bi-stethoscope fs-3"></i>
                    </div>
                    <h5 class="fw-bold text-dark">General Consultation</h5>
                    <p class="text-muted small mb-0">
                        Primary medical check-ups, vital signs evaluation, diagnostic assessment, and personalized treatment plans by our attending licensed physician.
                    </p>
                </div>
            </div>

            <!-- 2. Immunization -->
            <div class="col-lg-4 col-md-6">
                <div class="service-card p-4 h-100">
                    <div class="badge bg-success-subtle text-success p-3 rounded-circle mb-3">
                        <i class="bi bi-shield-plus fs-3"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Immunization</h5>
                    <p class="text-muted small mb-0">
                        Routine and mandated vaccine administration for infants, children, adults, and seniors against preventable communicable diseases.
                    </p>
                </div>
            </div>

            <!-- 3. Pediatric Care -->
            <div class="col-lg-4 col-md-6">
                <div class="service-card p-4 h-100">
                    <div class="badge bg-info-subtle text-info p-3 rounded-circle mb-3">
                        <i class="bi bi-emoji-smile fs-3"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Pediatric Care</h5>
                    <p class="text-muted small mb-0">
                        Dedicated clinical management for children and adolescents, focusing on treatment of common childhood illnesses, nutrition, and child wellness.
                    </p>
                </div>
            </div>

            <!-- 4. Prenatal Check up -->
            <div class="col-lg-4 col-md-6">
                <div class="service-card p-4 h-100">
                    <div class="badge bg-warning-subtle text-warning-emphasis p-3 rounded-circle mb-3">
                        <i class="bi bi-heart-pulse fs-3"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Prenatal Check up</h5>
                    <p class="text-muted small mb-0">
                        Comprehensive maternal health monitoring, fetal development assessment, routine obstetrical exams, and maternal care guidance.
                    </p>
                </div>
            </div>

            <!-- 5. Well-Baby Check up -->
            <div class="col-lg-4 col-md-6">
                <div class="service-card p-4 h-100">
                    <div class="badge bg-danger-subtle text-danger p-3 rounded-circle mb-3">
                        <i class="bi bi-balloon fs-3"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Well-Baby Check up</h5>
                    <p class="text-muted small mb-0">
                        Regular infant development monitoring, weight and height percentile tracking, developmental milestone evaluation, and essential newborn care.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Queue & Privacy Assurance Banner -->
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-white">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-shield-check text-success fs-3"></i>
                    <h4 class="fw-bold text-dark mb-0">Data Privacy Act of 2012 (RA 10173)</h4>
                </div>
                <p class="text-secondary small mb-3">
                    Paikit Health Center strictly adheres to national statutory standards for sensitive personal health data protection. Access privileges are strictly segregated across the system:
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3 h-100">
                            <strong class="text-dark small d-block mb-1"><i class="bi bi-lock-fill text-danger me-1"></i> Strict Clinical Isolation</strong>
                            <p class="text-muted mb-0" style="font-size: 0.8rem;">
                                Staff desk members are technically blocked from viewing diagnostic notes and medical histories. Only the physician accesses clinical assessments.
                            </p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3 h-100">
                            <strong class="text-dark small d-block mb-1"><i class="bi bi-diagram-3-fill text-primary me-1"></i> Deterministic Auto-Linking</strong>
                            <p class="text-muted mb-0" style="font-size: 0.8rem;">
                                Walk-in registration uses the patient's phone number to seamlessly route encounters to their profile, preventing duplicate files.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 p-4 text-center bg-white h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="fw-bold text-dark mb-0">Live Queue Monitor</h5>
                        <span class="badge bg-danger animate-pulse">LIVE BROADCAST</span>
                    </div>
                    <p class="text-muted small">Public display screen for the patient waiting area.</p>

                    <div class="p-3 bg-light rounded-3 my-3">
                        <div class="text-muted small text-uppercase fw-bold">NOW SERVING</div>
                        <div class="display-4 fw-bold text-danger"><?= sanitize($servingTicket) ?></div>
                        <div class="text-secondary small">Consultation Room 1 - <?= sanitize($attendingDoctor['full_name']) ?></div>
                    </div>
                </div>

                <a href="queue_monitor" target="_blank" class="btn btn-outline-primary w-100 fw-bold">
                    <i class="bi bi-fullscreen me-1"></i> Launch Fullscreen Monitor
                </a>
            </div>
        </div>
    </div>
</main>

<!-- Central RBAC Login & Registration Modal (Triggered from Header) -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
            <!-- Modal Header with Official Emblem -->
            <div class="modal-header bg-primary text-white py-3">
                <div class="d-flex align-items-center gap-2">
                    <img src="img/logo.png" alt="Paikit Logo" class="brand-logo-circle">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="loginModalLabel">Paikit Health Center</h5>
                        <small class="text-white-50" style="font-size: 0.75rem;">Sign In & Patient Registration</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body with Sign In & Patient Sign Up Nav -->
            <div class="modal-body p-4 position-relative">
                <!-- Floating Error Alert inside Modal -->
                <div id="modalFloatingAlert" class="alert alert-danger shadow border-0 d-flex align-items-center mb-3 py-2 px-3 rounded-3" role="alert" style="<?= (empty($error) || empty($errorScope)) ? 'display: none !important;' : '' ?>">
                    <i class="bi bi-exclamation-triangle-fill fs-5 text-danger me-2 flex-shrink-0"></i>
                    <div class="flex-grow-1 small fw-semibold" id="modalFloatingAlertText"><?= sanitize($error ?? '') ?></div>
                    <button type="button" class="btn-close btn-sm ms-2 flex-shrink-0" onclick="closeFloatingAlert()" aria-label="Close"></button>
                </div>

                <ul class="nav nav-pills nav-fill mb-4 p-1 bg-light rounded-3" id="authTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold py-2" id="signin-tab" data-bs-toggle="pill" data-bs-target="#signin-pane" type="button" role="tab">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold py-2" id="signup-tab" data-bs-toggle="pill" data-bs-target="#signup-pane" type="button" role="tab">
                            <i class="bi bi-person-plus me-1"></i> Patient Sign Up
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="authTabContent">
                    <!-- PANE 1: AUTOMATIC RBAC SIGN IN VIA EMAIL OR PHONE NUMBER -->
                    <div class="tab-pane fade show active" id="signin-pane" role="tabpanel">
                        <form action="./" method="POST" id="loginForm" onsubmit="return validateLoginForm(event)">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="login_submit" value="1">

                            <div class="mb-3">
                                <label class="form-label fw-bold small text-dark">Email or Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-person-fill text-primary"></i></span>
                                    <input type="text" name="email" id="loginEmail" class="form-control" required placeholder="Enter email or 09xxxxxxxxx" value="<?= ($errorScope === 'login') ? sanitize($_POST['email'] ?? '') : '' ?>">
                                </div>
                                <div class="form-text small text-muted mt-1" style="font-size: 0.76rem;">
                                    Sign in using your registered Email or 11-digit Mobile Number.
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-bold small text-dark mb-0">Password</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <a href="verify_email" class="text-success small fw-semibold text-decoration-none" style="font-size: 0.8rem;" title="Enter verification code for new accounts">
                                            Verify Email?
                                        </a>
                                        <span class="text-muted small" style="font-size: 0.75rem;">&bull;</span>
                                        <a href="forgot_password" class="text-primary small fw-semibold text-decoration-none" style="font-size: 0.8rem;">
                                            Forgot Password?
                                        </a>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-lock text-primary"></i></span>
                                    <input type="password" name="password" id="loginPassword" class="form-control" required placeholder="Enter your password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('loginPassword', this)" title="Show / Hide Password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm mb-3">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                            </button>

                            <div class="text-center small text-muted">
                                Don't have a patient account yet? 
                                <a href="javascript:void(0)" class="text-primary fw-bold text-decoration-none" onclick="document.getElementById('signup-tab').click()">
                                    Sign Up Here
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- PANE 2: PATIENT SIGN UP ONLY -->
                    <div class="tab-pane fade" id="signup-pane" role="tabpanel">
                        <form action="./" method="POST" id="patientSignupForm" onsubmit="return validateSignupForm(event)">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="client_signup" value="1">

                            <!-- Mobile Number (Strict 11 Digits & PH Registered Telco) -->
                            <div class="mb-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-bold small text-dark mb-0">Philippine Mobile Number (11 Digits)</label>
                                    <span class="badge bg-light text-muted border" id="mobileDigitCount">0 / 11 digits</span>
                                </div>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                    <input type="tel" name="mobile_number" id="regMobile" class="form-control" 
                                           placeholder="09171234567" required maxlength="11" minlength="11" 
                                           pattern="(09|08)[0-9]{9}" 
                                           title="Must be an 11-digit Philippine registered mobile number (e.g. 09171234567)" 
                                           value="<?= ($errorScope === 'signup') ? sanitize($_POST['mobile_number'] ?? '') : '' ?>"
                                           oninput="handleMobileInput(this)">
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span class="form-text small text-muted" style="font-size: 0.72rem;">Globe, Smart, TM, TNT, Sun, DITO</span>
                                    <span id="telcoNetworkBadge" class="badge bg-light text-secondary border small" style="font-size: 0.72rem;">Enter PH Mobile</span>
                                </div>
                            </div>

                            <!-- Email Address (Required) -->
                            <div class="mb-2">
                                <label class="form-label fw-bold small text-dark mb-1">Email Address (Required)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" id="regEmail" class="form-control" placeholder="juan.delacruz@gmail.com" required value="<?= ($errorScope === 'signup') ? sanitize($_POST['email'] ?? '') : '' ?>" oninput="handleRegEmailInput()" onblur="handleRegEmailBlur()">
                                </div>
                                <div id="regEmailFeedback" class="mt-1" style="font-size: 0.73rem;"></div>
                                <div class="form-text small text-muted" style="font-size: 0.72rem;">Used for official clinic notifications and electronic prescription copies.</div>
                            </div>

                            <!-- Patient Name Fields (First, Middle, Last, Suffix) -->
                            <div class="row g-2 mb-2">
                                <div class="col-md-6 col-12">
                                    <label class="form-label fw-bold small text-dark mb-1">First Name</label>
                                    <input type="text" name="first_name" id="regFirstName" class="form-control form-control-sm" placeholder="e.g. Juan" required minlength="2" maxlength="50" value="<?= ($errorScope === 'signup') ? sanitize($_POST['first_name'] ?? '') : '' ?>" oninput="handleNameInput(this)">
                                </div>
                                <div class="col-md-6 col-12">
                                    <label class="form-label fw-bold small text-dark mb-1">Middle Name <span class="text-muted fw-normal">(Optional)</span></label>
                                    <input type="text" name="middle_name" id="regMiddleName" class="form-control form-control-sm" placeholder="e.g. Mercado" maxlength="50" value="<?= ($errorScope === 'signup') ? sanitize($_POST['middle_name'] ?? '') : '' ?>" oninput="handleNameInput(this)">
                                </div>
                                <div class="col-md-8 col-8">
                                    <label class="form-label fw-bold small text-dark mb-1">Last Name</label>
                                    <input type="text" name="last_name" id="regLastName" class="form-control form-control-sm" placeholder="e.g. Dela Cruz" required minlength="2" maxlength="50" value="<?= ($errorScope === 'signup') ? sanitize($_POST['last_name'] ?? '') : '' ?>" oninput="handleNameInput(this)">
                                </div>
                                <div class="col-md-4 col-4">
                                    <label class="form-label fw-bold small text-dark mb-1">Suffix <span class="text-muted fw-normal">(Opt)</span></label>
                                    <?php $selSuffix = ($errorScope === 'signup') ? ($_POST['suffix'] ?? '') : ''; ?>
                                    <select name="suffix" id="regSuffix" class="form-select form-select-sm">
                                        <option value="" <?= $selSuffix === '' ? 'selected' : '' ?>>None</option>
                                        <option value="Jr." <?= $selSuffix === 'Jr.' ? 'selected' : '' ?>>Jr.</option>
                                        <option value="Sr." <?= $selSuffix === 'Sr.' ? 'selected' : '' ?>>Sr.</option>
                                        <option value="II" <?= $selSuffix === 'II' ? 'selected' : '' ?>>II</option>
                                        <option value="III" <?= $selSuffix === 'III' ? 'selected' : '' ?>>III</option>
                                        <option value="IV" <?= $selSuffix === 'IV' ? 'selected' : '' ?>>IV</option>
                                        <option value="V" <?= $selSuffix === 'V' ? 'selected' : '' ?>>V</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-bold small text-dark mb-1">Birth Date</label>
                                    <input type="date" name="birth_date" id="regBirthDate" class="form-control form-control-sm" min="1900-01-01" max="<?= date('Y-m-d') ?>" required value="<?= ($errorScope === 'signup') ? sanitize($_POST['birth_date'] ?? '') : '' ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-bold small text-dark mb-1">Gender</label>
                                    <?php $selGender = ($errorScope === 'signup') ? ($_POST['gender'] ?? 'Male') : 'Male'; ?>
                                    <select name="gender" id="regGender" class="form-select form-select-sm" required>
                                        <option value="Male" <?= $selGender === 'Male' ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= $selGender === 'Female' ? 'selected' : '' ?>>Female</option>
                                        <option value="Other" <?= $selGender === 'Other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Address: Canlubang Area Restricted Selection -->
                            <div class="row g-2 mb-2">
                                <div class="col-md-6 col-12">
                                    <label class="form-label fw-bold small text-dark mb-1">
                                        <i class="bi bi-geo-alt-fill text-danger me-1"></i>Canlubang Area
                                    </label>
                                    <?php $selSitio = ($errorScope === 'signup') ? ($_POST['sitio'] ?? '') : ''; ?>
                                    <select name="sitio" id="regSitio" class="form-select form-select-sm" required>
                                        <option value="" disabled <?= empty($selSitio) ? 'selected' : '' ?>>-- Select Area (A-Z) --</option>
                                        <option value="Asia 1" <?= $selSitio === 'Asia 1' ? 'selected' : '' ?>>Asia 1</option>
                                        <option value="Asia 2" <?= $selSitio === 'Asia 2' ? 'selected' : '' ?>>Asia 2</option>
                                        <option value="Carmel Housing" <?= $selSitio === 'Carmel Housing' ? 'selected' : '' ?>>Carmel Housing</option>
                                        <option value="Ceris 1 & 2" <?= $selSitio === 'Ceris 1 & 2' ? 'selected' : '' ?>>Ceris 1 & 2</option>
                                        <option value="GK" <?= $selSitio === 'GK' ? 'selected' : '' ?>>GK</option>
                                        <option value="Manfil" <?= $selSitio === 'Manfil' ? 'selected' : '' ?>>Manfil</option>
                                        <option value="Mcdc" <?= $selSitio === 'Mcdc' ? 'selected' : '' ?>>Mcdc</option>
                                        <option value="Paikit" <?= $selSitio === 'Paikit' ? 'selected' : '' ?>>Paikit</option>
                                        <option value="Palao" <?= $selSitio === 'Palao' ? 'selected' : '' ?>>Palao</option>
                                    </select>
                                </div>
                                <div class="col-md-6 col-12">
                                    <label class="form-label fw-bold small text-dark mb-1">House No. / Street (Optional)</label>
                                    <input type="text" name="street_address" id="regStreetAddress" class="form-control form-control-sm" placeholder="Blk / Lot / Street" value="<?= ($errorScope === 'signup') ? sanitize($_POST['street_address'] ?? '') : '' ?>">
                                </div>
                                <div class="col-12">
                                    <span class="text-muted" style="font-size: 0.71rem;">
                                        <i class="bi bi-info-circle text-primary me-1"></i>
                                        Exclusive to Canlubang residents (A-Z): <strong>Asia 1, Asia 2, Carmel Housing, Ceris 1 & 2, GK, Manfil, Mcdc, Paikit, Palao</strong>.
                                    </span>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold small text-dark mb-1">Desired Username</label>
                                <input type="text" name="username" id="regUsername" class="form-control form-control-sm" placeholder="e.g. jdelacruz" required value="<?= ($errorScope === 'signup') ? sanitize($_POST['username'] ?? '') : '' ?>">
                            </div>

                            <!-- Password & Confirm Password (with Show/Hide Toggle) -->
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label fw-bold small text-dark mb-1">Password</label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="password" id="regPassword" class="form-control form-control-sm" 
                                               placeholder="Min. 8 chars" required minlength="8" 
                                               oninput="checkPasswordRules()">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('regPassword', this)" title="Show or hide password">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-bold small text-dark mb-1">Confirm Password</label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="confirm_password" id="regConfirmPassword" class="form-control form-control-sm" 
                                               placeholder="Repeat password" required minlength="8" 
                                               oninput="checkPasswordRules()">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('regConfirmPassword', this)" title="Show or hide password">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
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

                            <button type="submit" id="btnSubmitSignup" class="btn btn-success w-100 fw-bold py-2 shadow-sm mb-2">
                                <i class="bi bi-person-check me-1"></i> Register Patient Account
                            </button>

                            <div class="text-center small text-muted">
                                Already registered? 
                                <a href="javascript:void(0)" class="text-primary fw-bold text-decoration-none" onclick="document.getElementById('signin-tab').click()">
                                    Sign In
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Footer -->
<footer class="bg-dark text-light py-4 mt-5">
    <div class="container text-center small">
        <p class="mb-1 fw-bold">Paikit Health Center Information System</p>
        <p class="text-secondary mb-2">
            Developed in strict compliance with the Republic Act No. 10173 (Data Privacy Act of 2012) and National Privacy Commission regulations.
        </p>
        <div class="text-secondary" style="font-size: 0.8rem;">
            Confidentiality Notice: Clinical diagnostic records and e-prescriptions are protected health data accessible strictly by authorized medical practitioners.
        </div>
    </div>
</footer>

<script src="js/bootstrap.bundle.min.js"></script>
<script>
    const globePrefixes = ['0905','0906','0915','0916','0917','0926','0927','0935','0936','0945','0953','0954','0955','0956','0965','0966','0967','0975','0977','0978','0979','0995','0996','0997','0817'];
    const smartPrefixes = ['0907','0908','0909','0910','0911','0912','0913','0914','0918','0919','0920','0921','0922','0923','0924','0925','0928','0929','0930','0931','0932','0933','0934','0938','0939','0940','0941','0942','0943','0946','0947','0948','0949','0950','0951','0961','0962','0963','0964','0968','0969','0970','0971','0981','0982','0985','0989','0998','0999','0813'];
    const ditoPrefixes  = ['0991','0992','0993','0994','0895','0896','0897','0898'];

    function handleMobileInput(el) {
        el.value = el.value.replace(/[^0-9]/g, '').slice(0, 11);
        const countBadge = document.getElementById('mobileDigitCount');
        const telcoBadge = document.getElementById('telcoNetworkBadge');
        const len = el.value.length;
        countBadge.textContent = len + ' / 11 digits';

        if (len >= 4) {
            const p = el.value.substring(0, 4);
            if (globePrefixes.includes(p)) {
                telcoBadge.className = 'badge bg-primary text-white border small';
                telcoBadge.textContent = 'Globe / TM';
            } else if (smartPrefixes.includes(p)) {
                telcoBadge.className = 'badge bg-success text-white border small';
                telcoBadge.textContent = 'Smart / TNT / Sun';
            } else if (ditoPrefixes.includes(p)) {
                telcoBadge.className = 'badge bg-danger text-white border small';
                telcoBadge.textContent = 'DITO Telecommunity';
            } else if (el.value.startsWith('09') || el.value.startsWith('08')) {
                telcoBadge.className = 'badge bg-info text-dark border small';
                telcoBadge.textContent = 'PH Mobile Number';
            } else {
                telcoBadge.className = 'badge bg-danger text-white border small';
                telcoBadge.textContent = 'Invalid PH Prefix';
            }
        } else {
            telcoBadge.className = 'badge bg-light text-secondary border small';
            telcoBadge.textContent = 'Enter PH Mobile';
        }

        if (len === 11 && (el.value.startsWith('09') || el.value.startsWith('08'))) {
            countBadge.className = 'badge bg-success-subtle text-success border border-success';
        } else {
            countBadge.className = 'badge bg-light text-danger border border-danger';
        }
    }

    function checkPasswordRules() {
        const pwd = document.getElementById('regPassword').value;
        const confirm = document.getElementById('regConfirmPassword').value;

        const hasLength  = pwd.length >= 8;
        const hasUpper   = /[A-Z]/.test(pwd);
        const hasLower   = /[a-z]/.test(pwd);
        const hasNumber  = /[0-9]/.test(pwd);
        const hasSpecial = /[\W_]/.test(pwd);
        const hasMatch   = pwd.length > 0 && pwd === confirm;

        updateRuleBadge('ruleLength', hasLength, 'Min. 8 characters');
        updateRuleBadge('ruleUpper', hasUpper, 'Uppercase letter (A-Z)');
        updateRuleBadge('ruleLower', hasLower, 'Lowercase letter (a-z)');
        updateRuleBadge('ruleNumber', hasNumber, 'Number (0-9)');
        updateRuleBadge('ruleSpecial', hasSpecial, 'Special char (@#$!%...)');
        updateRuleBadge('ruleMatch', hasMatch, 'Passwords match');

        return hasLength && hasUpper && hasLower && hasNumber && hasSpecial && hasMatch;
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

    function handleNameInput(el) {
        // Numbers and unauthorized special characters are prohibited
        el.value = el.value.replace(/[0-9]/g, '').replace(/[^a-zA-ZñÑ\s\.\'\-]/g, '');
        // Prohibit more than 2 consecutive identical letters (e.g. max 2 letters, prevent "aaa", "zzzz")
        el.value = el.value.replace(/([a-zA-ZñÑ])\1{2,}/gi, '$1$1');
    }

    let emailCheckTimeout = null;
    let emailCheckState = 'idle'; // 'idle' | 'typing' | 'checking' | 'available' | 'registered' | 'invalid'
    let emailFeedbackMessage = '';

    function handleRegEmailInput() {
        const emailInput = document.getElementById('regEmail');
        const feedback = document.getElementById('regEmailFeedback');
        if (!emailInput || !feedback) return;

        const email = emailInput.value.trim();
        emailInput.classList.remove('is-valid', 'is-invalid');

        if (!email) {
            feedback.innerHTML = '';
            emailCheckState = 'idle';
            emailFeedbackMessage = '';
            return;
        }

        // Basic format check
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const parts = email.toLowerCase().split('@');
        const local = parts[0] || '';
        const dom = parts[1] || '';

        // Immediate client-side typo detection
        const commonTypos = {
            'gamil.com': 'gmail.com', 'gmaill.com': 'gmail.com', 'gmial.com': 'gmail.com', 'gmai.com': 'gmail.com', 'gmal.com': 'gmail.com',
            'yaho.com': 'yahoo.com', 'yahooo.com': 'yahoo.com', 'yhaoo.com': 'yahoo.com', 'hotmial.com': 'hotmail.com', 'outlok.com': 'outlook.com'
        };
        if (commonTypos[dom]) {
            emailInput.classList.add('is-invalid');
            feedback.innerHTML = `<span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> Did you mean <u>@${commonTypos[dom]}</u>? Please check your email spelling.</span>`;
            emailCheckState = 'invalid';
            emailFeedbackMessage = `Did you mean @${commonTypos[dom]}? Please check your email spelling.`;
            return;
        }

        // Immediate client-side disposable detection
        const disposableList = ['mailinator.com', 'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'guerrillamail.com', '10minutemail.com', 'trashmail.com', 'yopmail.com', 'dispostable.com', 'getnada.com', 'throwawaymail.com', 'fakeinbox.com', 'burnermail.io'];
        if (disposableList.includes(dom) || dom.includes('tempmail') || dom.includes('disposable') || dom.includes('throwaway')) {
            emailInput.classList.add('is-invalid');
            feedback.innerHTML = `<span class="text-danger fw-bold"><i class="bi bi-x-circle-fill me-1"></i> Disposable/temporary email (@${dom}) is prohibited. Please use a permanent personal email (e.g. Gmail, Yahoo).</span>`;
            emailCheckState = 'invalid';
            emailFeedbackMessage = 'Disposable email services are prohibited. Please use a permanent personal email address.';
            return;
        }

        // Obvious dummy email check
        const dummyPatterns = ['test', 'fake', 'dummy', 'sample', 'asdf', 'asdasd', 'qwerty', '123456', 'noreply', 'admin'];
        if (dummyPatterns.includes(local) && ['test.com', 'example.com', 'fake.com', 'sample.com', 'asdasd.com'].includes(dom)) {
            emailInput.classList.add('is-invalid');
            feedback.innerHTML = '<span class="text-danger fw-bold"><i class="bi bi-x-circle-fill me-1"></i> Test or dummy email is not permitted. Please enter your real personal email.</span>';
            emailCheckState = 'invalid';
            emailFeedbackMessage = 'Test or dummy email is not permitted. Please enter your real personal email.';
            return;
        }

        if (!emailRegex.test(email)) {
            feedback.innerHTML = '<span class="text-muted"><i class="bi bi-pencil me-1"></i> Please complete your email address (e.g. juan.delacruz@gmail.com)</span>';
            emailCheckState = 'typing';
            return;
        }

        // Show live checking state
        feedback.innerHTML = '<span class="text-muted"><i class="spinner-border spinner-border-sm me-1" style="width: 0.75rem; height: 0.75rem;"></i> Verifying email legitimacy & availability...</span>';
        emailCheckState = 'checking';

        // Debounced API check
        if (emailCheckTimeout) clearTimeout(emailCheckTimeout);
        emailCheckTimeout = setTimeout(() => {
            fetch('api/check_email.php?email=' + encodeURIComponent(email))
                .then(res => res.json())
                .then(data => {
                    if (emailInput.value.trim().toLowerCase() !== email.toLowerCase()) {
                        return; // Stale request
                    }
                    if (data.status === 'registered') {
                        emailInput.classList.add('is-invalid');
                        emailInput.classList.remove('is-valid');
                        feedback.innerHTML = `
                            <div class="p-2 rounded bg-danger-subtle text-danger border border-danger-subtle fw-bold mt-1">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-1">
                                    <span><i class="bi bi-x-circle-fill me-1"></i> This email is <u>already registered</u> to an existing account!</span>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <button type="button" onclick="switchToSignIn('${email}')" class="btn btn-danger btn-sm py-0 px-2 fw-bold shadow-none" style="font-size: 0.72rem;">Sign In</button>
                                        <a href="forgot_password" class="btn btn-link btn-sm py-0 px-1 text-danger fw-bold text-decoration-underline" style="font-size: 0.72rem;">Forgot Password?</a>
                                    </span>
                                </div>
                            </div>`;
                        emailCheckState = 'registered';
                        emailFeedbackMessage = data.message;
                    } else if (data.status === 'invalid') {
                        emailInput.classList.add('is-invalid');
                        emailInput.classList.remove('is-valid');
                        feedback.innerHTML = `<span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> ${data.message}</span>`;
                        emailCheckState = 'invalid';
                        emailFeedbackMessage = data.message;
                    } else if (data.status === 'available') {
                        emailInput.classList.remove('is-invalid');
                        emailInput.classList.add('is-valid');
                        feedback.innerHTML = `<span class="text-success fw-bold"><i class="bi bi-check-circle-fill me-1"></i> Email is legitimate, verified, and available.</span>`;
                        emailCheckState = 'available';
                        emailFeedbackMessage = '';
                    }
                })
                .catch(err => {
                    console.error('Email check error:', err);
                    feedback.innerHTML = '';
                    emailCheckState = 'idle';
                });
        }, 350);
    }

    function handleRegEmailBlur() {
        const emailInput = document.getElementById('regEmail');
        if (emailInput && emailInput.value.trim() && (emailCheckState === 'typing' || emailCheckState === 'idle')) {
            handleRegEmailInput();
        }
    }

    function switchToSignIn(prefillEmail) {
        const signinTab = document.getElementById('signin-tab');
        if (signinTab) signinTab.click();
        const loginEmail = document.getElementById('loginEmail');
        if (loginEmail && prefillEmail) {
            loginEmail.value = prefillEmail;
            const loginPwd = document.getElementById('loginPassword');
            if (loginPwd) loginPwd.focus();
        }
    }

    function showModalFloatingError(msg, targetInputId) {
        const alertEl = document.getElementById('modalFloatingAlert');
        const textEl = document.getElementById('modalFloatingAlertText');
        if (alertEl && textEl) {
            textEl.textContent = msg;
            alertEl.style.removeProperty('display');
            alertEl.style.display = 'flex';
            alertEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        if (targetInputId) {
            const target = document.getElementById(targetInputId);
            if (target) {
                target.classList.add('is-invalid');
                target.focus();
                target.addEventListener('input', function handler() {
                    target.classList.remove('is-invalid');
                    target.removeEventListener('input', handler);
                });
            }
        }
    }

    function closeFloatingAlert() {
        const alertEl = document.getElementById('modalFloatingAlert');
        if (alertEl) {
            alertEl.style.display = 'none';
        }
    }

    function validateLoginForm(e) {
        const ident = document.getElementById('loginEmail').value.trim();
        const pwd = document.getElementById('loginPassword').value;
        if (!ident || !pwd) {
            showModalFloatingError('Please enter your Email or Phone Number and Password.', !ident ? 'loginEmail' : 'loginPassword');
            e.preventDefault();
            return false;
        }

        const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ident);
        const cleanedPhone = ident.replace(/[^0-9]/g, '');
        const isPhone = /^(09|08)\d{9}$/.test(cleanedPhone) || /^(639|638)\d{9}$/.test(cleanedPhone);

        if (!isEmail && !isPhone) {
            showModalFloatingError('Please enter a valid Email address or 11-digit Mobile Number (e.g. 09171234567).', 'loginEmail');
            e.preventDefault();
            return false;
        }

        return true;
    }

    function isValidPersonNameJs(val, minLen = 2) {
        if (!val) return false;
        const lettersOnly = val.replace(/[^a-zA-ZñÑ]/g, '');
        if (lettersOnly.length < minLen) return false;
        if (/[0-9]/.test(val)) return false;
        if (!/^[a-zA-ZñÑ\s\.\'\-]+$/.test(val)) return false;
        if (/([a-zA-ZñÑ])\1{2,}/i.test(val)) return false;
        return true;
    }

    function validateSignupForm(e) {
        const mobile = document.getElementById('regMobile').value;
        if (!/^(09|08)[0-9]{9}$/.test(mobile)) {
            showModalFloatingError('Mobile number must be a valid 11-digit Philippine mobile number starting with 09 or 08 (e.g. 09171234567).', 'regMobile');
            e.preventDefault();
            return false;
        }

        const email = document.getElementById('regEmail').value.trim();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            showModalFloatingError('Please enter a valid email address (e.g. juan.delacruz@gmail.com).', 'regEmail');
            e.preventDefault();
            return false;
        }

        if (emailCheckState === 'registered') {
            showModalFloatingError('The email entered is already registered to an existing account. Please sign in or use Forgot Password.', 'regEmail');
            e.preventDefault();
            return false;
        }

        if (emailCheckState === 'invalid') {
            showModalFloatingError(emailFeedbackMessage || 'Please enter a legitimate, active email address.', 'regEmail');
            e.preventDefault();
            return false;
        }

        const emailParts = email.toLowerCase().split('@');
        const emailLocal = emailParts[0] || '';
        const emailDom   = emailParts[1] || '';

        // Obvious fake dummy patterns
        const dummyPatterns = ['test', 'fake', 'dummy', 'sample', 'asdf', 'asdasd', 'qwerty', '123456', 'noreply', 'admin'];
        if (dummyPatterns.includes(emailLocal) && ['test.com', 'example.com', 'fake.com', 'sample.com', 'asdasd.com'].includes(emailDom)) {
            showModalFloatingError('The email entered is a dummy or test email. Please provide your real personal email.', 'regEmail');
            e.preventDefault();
            return false;
        }

        // Common typos
        const commonTypos = {
            'gamil.com': 'gmail.com', 'gmaill.com': 'gmail.com', 'gmial.com': 'gmail.com', 'gmai.com': 'gmail.com',
            'yaho.com': 'yahoo.com', 'yahooo.com': 'yahoo.com', 'hotmial.com': 'hotmail.com', 'outlok.com': 'outlook.com'
        };
        if (commonTypos[emailDom]) {
            showModalFloatingError('Did you mean @' + commonTypos[emailDom] + '? Please verify your email spelling.', 'regEmail');
            e.preventDefault();
            return false;
        }

        // Common disposable domains
        const disposableList = ['mailinator.com', 'tempmail.com', 'temp-mail.org', 'guerrillamail.com', '10minutemail.com', 'trashmail.com', 'yopmail.com', 'dispostable.com', 'getnada.com', 'throwawaymail.com', 'fakeinbox.com', 'burnermail.io'];
        if (disposableList.includes(emailDom) || emailDom.includes('tempmail') || emailDom.includes('disposable')) {
            showModalFloatingError('Disposable or temporary email services (@' + emailDom + ') are not permitted. Please use a permanent email address (e.g. Gmail, Yahoo, Outlook).', 'regEmail');
            e.preventDefault();
            return false;
        }

        const firstName = document.getElementById('regFirstName').value.trim();
        const lastName  = document.getElementById('regLastName').value.trim();
        const middleName = document.getElementById('regMiddleName') ? document.getElementById('regMiddleName').value.trim() : '';

        if (!isValidPersonNameJs(firstName, 2)) {
            showModalFloatingError('Invalid First Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters and max 2 consecutive identical letters.', 'regFirstName');
            e.preventDefault();
            return false;
        }

        if (middleName && !isValidPersonNameJs(middleName, 2)) {
            showModalFloatingError('Invalid Middle Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters and max 2 consecutive identical letters.', 'regMiddleName');
            e.preventDefault();
            return false;
        }

        if (!isValidPersonNameJs(lastName, 2)) {
            showModalFloatingError('Invalid Last Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters and max 2 consecutive identical letters.', 'regLastName');
            e.preventDefault();
            return false;
        }

        const birthDate = document.getElementById('regBirthDate') ? document.getElementById('regBirthDate').value : '';
        if (!birthDate) {
            showModalFloatingError('Please select your birth date.', 'regBirthDate');
            e.preventDefault();
            return false;
        }
        const todayStr = new Date().toISOString().split('T')[0];
        if (birthDate > todayStr) {
            showModalFloatingError('Invalid birth date: Future dates are not allowed.', 'regBirthDate');
            e.preventDefault();
            return false;
        }
        if (birthDate < '1900-01-01') {
            showModalFloatingError('Invalid birth date: Year must not be earlier than 1900.', 'regBirthDate');
            e.preventDefault();
            return false;
        }

        const sitio = document.getElementById('regSitio').value;
        const validAreas = ['Asia 1', 'Asia 2', 'Carmel Housing', 'Ceris 1 & 2', 'GK', 'Manfil', 'Mcdc', 'Paikit', 'Palao'];
        if (!sitio || !validAreas.includes(sitio)) {
            showModalFloatingError('Please select an official area in Canlubang (Asia 1, Asia 2, Carmel Housing, Ceris 1 & 2, GK, Manfil, Mcdc, Paikit, or Palao).', 'regSitio');
            e.preventDefault();
            return false;
        }

        const username = document.getElementById('regUsername') ? document.getElementById('regUsername').value.trim() : '';
        if (!username) {
            showModalFloatingError('Please enter your desired username.', 'regUsername');
            e.preventDefault();
            return false;
        }

        if (!checkPasswordRules()) {
            showModalFloatingError('Please meet all password requirements (at least 8 characters, uppercase A-Z, lowercase a-z, number 0-9, special character, and matching confirmation).', 'regPassword');
            e.preventDefault();
            return false;
        }
        return true;
    }

    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        } else {
            input.type = 'password';
            if (icon) {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        const hasError = <?= (!empty($error) && !empty($errorScope)) ? 'true' : 'false' ?>;
        const errorScope = '<?= $errorScope ?? '' ?>';
        const urlParams = new URLSearchParams(window.location.search);
        const shouldOpenLogin = urlParams.get('action') === 'login' || urlParams.get('login') === '1';

        if (hasError || shouldOpenLogin) {
            const modalEl = document.getElementById('loginModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
                if (errorScope === 'signup') {
                    const tab = document.getElementById('signup-tab');
                    if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
                } else {
                    const tab = document.getElementById('signin-tab');
                    if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
                }
            }
        }

        const regMobile = document.getElementById('regMobile');
        if (regMobile && regMobile.value) {
            handleMobileInput(regMobile);
        }

        const regBirthDate = document.getElementById('regBirthDate');
        if (regBirthDate) {
            const todayStr = new Date().toISOString().split('T')[0];
            regBirthDate.setAttribute('max', todayStr);
            regBirthDate.setAttribute('min', '1900-01-01');
            regBirthDate.addEventListener('change', function() {
                if (this.value > todayStr) {
                    showModalFloatingError('Invalid birth date: Future dates are not allowed.', 'regBirthDate');
                    this.value = '';
                } else if (this.value && this.value < '1900-01-01') {
                    showModalFloatingError('Invalid birth date: Year must not be earlier than 1900.', 'regBirthDate');
                    this.value = '';
                }
            });
        }

        const welcomeModalEl = document.getElementById('welcomeMsgModal');
        if (welcomeModalEl) {
            const welcomeModal = bootstrap.Modal.getOrCreateInstance(welcomeModalEl);
            welcomeModal.show();
            if (window.history.replaceState) {
                const url = new URL(window.location);
                url.searchParams.delete('msg');
                window.history.replaceState({}, document.title, url.toString());
            }
        }
    });
</script>
</body>
</html>
