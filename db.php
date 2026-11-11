<?php
/**
 * db.php - Central Database Connection & Security Architecture
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173)
 */

// Enforce Philippine Standard Time (PST / UTC+8) for all clinical and audit timestamps
date_default_timezone_set('Asia/Manila');

// Safe Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Database Credentials (Local XAMPP Environment)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'paikit_health_center');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns singleton PDO connection instance.
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Under RA 10173, prevent disclosing database connection strings or internals to end-users
            error_log("Database Connection Error: " . $e->getMessage());
            die("Critical Error: Database connection failed. Please contact the Paikit Health Center IT administrator.");
        }
    }
    return $pdo;
}

// Initialize Global Connection
$pdo = get_db();

/**
 * Security: Authentication Check
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Returns current authenticated user record from session
 */
function get_auth_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'user_id'    => $_SESSION['user_id'] ?? null,
        'username'   => $_SESSION['username'] ?? '',
        'full_name'  => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User'),
        'role'       => $_SESSION['role'] ?? '',
        'patient_id' => $_SESSION['patient_id'] ?? null
    ];
}

/**
 * Security: Role-Based Access Control (RBAC) Enforcement
 * Strictly enforces that only authorized roles can view or execute pages.
 * Unauthorized access attempts are logged for RA 10173 audit compliance.
 */
function require_role($allowed_roles) {
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }

    if (!is_logged_in()) {
        header("Location: ../?error=" . urlencode("Authentication required to access this console."));
        exit;
    }

    $current_role = $_SESSION['role'] ?? '';
    if (!in_array($current_role, $allowed_roles, true)) {
        // Log Unauthorized Access Attempt
        audit_log('UNAUTHORIZED_ACCESS_ATTEMPT', "User '{$_SESSION['username']}' with role '$current_role' attempted to access restricted resource. Required: " . implode(',', $allowed_roles));
        
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>403 Forbidden - Paikit Health Center</title>
            <link rel="stylesheet" href="../css/bootstrap.min.css">
        </head>
        <body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
            <div class="card shadow-sm border-danger" style="max-width: 540px;">
                <div class="card-header bg-danger text-white fw-bold">
                    <i class="bi bi-shield-lock-fill me-2"></i> Access Denied (HTTP 403 Forbidden)
                </div>
                <div class="card-body p-4 text-center">
                    <img src="../img/logo.png" alt="Paikit Logo" style="width: 56px; height: 56px; border-radius: 50%; margin-bottom: 12px;">
                    <h5 class="card-title text-danger fw-bold">Strict Privacy & Role Separation Notice</h5>
                    <p class="card-text text-secondary mb-3">
                        You do not possess the required clearance level (<strong><?= htmlspecialchars(implode(', ', $allowed_roles)) ?></strong>) to access this health center module.
                    </p>
                    <div class="alert alert-secondary small mb-3">
                        <strong>Compliance Note (RA 10173 - Data Privacy Act of 2012):</strong>
                        Section 20 of RA 10173 mandates strict security controls to protect sensitive personal and health information. Clinical diagnostic notes and medical records are restricted to licensed medical practitioners.
                    </div>
                    <div class="d-flex justify-content-between">
                        <a href="../" class="btn btn-outline-secondary">Return to Portal Home</a>
                        <a href="../logout" class="btn btn-danger">Log Out</a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

/**
 * CSRF Protection Helpers
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * HTML Output Sanitizer for XSS Prevention
 */
function sanitize(?string $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Deterministic Mobile Number Normalizer
 * Cleans phone number to standard 11-digit Philippine mobile format (e.g. 09171234567)
 */
function normalize_phone(string $phone): string {
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($cleaned, '639') && strlen($cleaned) === 12) {
        $cleaned = '0' . substr($cleaned, 2);
    }
    return $cleaned;
}

/**
 * Validates strictly if phone number is an 11-digit Philippine registered mobile number
 */
function is_valid_ph_mobile(string $phone): bool {
    $cleaned = normalize_phone($phone);
    return (bool)preg_match('/^(09|08)\d{9}$/', $cleaned);
}

/**
 * Returns Philippine Network Carrier name based on NTC prefix allocations
 */
function get_ph_network_name(string $phone): ?string {
    $cleaned = normalize_phone($phone);
    if (!is_valid_ph_mobile($cleaned)) {
        return null;
    }
    $p = substr($cleaned, 0, 4);

    $globe = ['0905','0906','0915','0916','0917','0926','0927','0935','0936','0945','0953','0954','0955','0956','0965','0966','0967','0975','0977','0978','0979','0995','0996','0997','0817'];
    $smart = ['0907','0908','0909','0910','0911','0912','0913','0914','0918','0919','0920','0921','0922','0923','0924','0925','0928','0929','0930','0931','0932','0933','0934','0938','0939','0940','0941','0942','0943','0946','0947','0948','0949','0950','0951','0961','0962','0963','0964','0968','0969','0970','0971','0981','0982','0985','0989','0998','0999','0813'];
    $dito  = ['0991','0992','0993','0994','0895','0896','0897','0898'];

    if (in_array($p, $globe)) return 'Globe / TM';
    if (in_array($p, $smart)) return 'Smart / TNT / Sun';
    if (in_array($p, $dito))  return 'DITO Telecommunity';
    return 'Philippine Registered Mobile';
}

/**
 * Authorized Canlubang Communities / Areas List (A-Z)
 * Strictly restricted to official areas of Barangay Canlubang, Calamba City.
 */
function get_canlubang_sitios(): array {
    return [
        'Asia 1',
        'Asia 2',
        'Carmel Housing',
        'Ceris 1 & 2',
        'GK',
        'Manfil',
        'Mcdc',
        'Paikit',
        'Palao'
    ];
}

/**
 * Validates if an input address/area belongs to the authorized Canlubang areas.
 */
function is_valid_canlubang_sitio(?string $sitio): bool {
    if (!$sitio) return false;
    $allowed = [
        'asia 1',
        'asia 2',
        'asia 1, 2',
        'asia 1,2',
        'carmel housing',
        'ceris 1 & 2',
        'ceris 1&2',
        'ceris 1',
        'ceris 2',
        'gk',
        'manfil',
        'mcdc',
        'paikit',
        'palao'
    ];
    return in_array(strtolower(trim($sitio)), $allowed, true);
}

/**
 * Cryptographically Secure Unique Transaction Code Generator
 * Generates unique guest reference slip codes: e.g. PK-2026-A8F29C
 */
function generate_transaction_code(): string {
    return 'PK-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Sequential Queue Number Generator for the Current Day
 * e.g., W-001, W-002 (Walk-in) or A-001, A-002 (Appointment)
 */
function generate_queue_number(PDO $pdo, string $prefix = 'W'): string {
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd   = date('Y-m-d 23:59:59');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM encounters WHERE visit_date BETWEEN ? AND ? AND queue_number LIKE ?");
    $stmt->execute([$todayStart, $todayEnd, $prefix . '-%']);
    $count = (int)$stmt->fetchColumn();

    $nextNum = $count + 1;
    return sprintf("%s-%03d", $prefix, $nextNum);
}

/**
 * Strict Person Name Validator:
 * 1. Numbers (0-9) are strictly prohibited
 * 2. Ghost names or whitespace-only inputs are prohibited (must have legitimate letters)
 * 3. Minimum of 2 letters required
 * 4. Maximum of 2 consecutive identical letters (e.g. prohibits "aaa", "zzzz" ghost inputs)
 */
function is_valid_person_name(string $name, int $minLetters = 2): bool {
    $trimmed = trim($name);
    // Reject empty or whitespace-only
    if (empty($trimmed)) {
        return false;
    }
    // Reject any numeric digits (0-9)
    if (preg_match('/[0-9]/', $trimmed)) {
        return false;
    }
    // Allow only Latin/Filipino letters (A-Z, a-z, ñ, Ñ), spaces, hyphens (-), periods (.), or apostrophes (')
    if (!preg_match('/^[a-zA-ZñÑ\s\.\'\-]+$/u', $trimmed)) {
        return false;
    }
    // Count actual alphabetic letters - must meet or exceed minLetters (default 2)
    $lettersOnly = preg_replace('/[^a-zA-ZñÑ]/u', '', $trimmed);
    if (mb_strlen($lettersOnly) < $minLetters) {
        return false;
    }
    // Prohibit more than 2 consecutive identical letters (e.g. reject "aaa", "xxxx")
    if (preg_match('/([a-zA-ZñÑ])\1{2,}/ui', $trimmed)) {
        return false;
    }
    return true;
}

/**
 * Returns a time-based greeting (morning, afternoon, evening) based on Philippine Standard Time.
 */
function get_time_based_greeting(): string {
    $hour = (int)date('G');
    if ($hour >= 5 && $hour < 12) {
        return 'Good morning';
    } elseif ($hour >= 12 && $hour < 18) {
        return 'Good afternoon';
    } else {
        return 'Good evening';
    }
}

/**
 * Returns a friendly welcome message with the time-based greeting and user's name.
 */
function get_time_based_welcome(string $name = ''): string {
    $greeting = get_time_based_greeting();
    $name = trim($name);
    if (!empty($name)) {
        return "Welcome! {$greeting}, {$name}!";
    }
    return "Welcome! {$greeting}!";
}

/**
 * Fair Interleaved Calling Queue (Ratio: 2 Appointments : 1 Walk-in)
 * - Only includes patients with check_in_status = 'Arrived' and queue_status = 'Waiting'
 * - Priority patients (Senior, PWD, Pregnant, Emergency) jump to the head of the queue
 * - Returns indexed list with 1-based 'queue_position'
 */
function get_interleaved_queue(PDO $pdo, string $todayStart, string $todayEnd): array {
    $stmt = $pdo->prepare("
        SELECT e.encounter_id, e.patient_id, e.queue_number, e.encounter_type, 
               e.queue_status, e.check_in_status, e.check_in_time, e.is_priority, e.priority_reason,
               e.visit_date, e.complaint,
               p.full_name, p.gender, p.sitio, p.birth_date, p.mobile_number
        FROM encounters e
        JOIN patients p ON e.patient_id = p.patient_id
        WHERE e.visit_date BETWEEN ? AND ? 
          AND e.queue_status = 'Waiting' 
          AND e.check_in_status = 'Arrived'
        ORDER BY e.is_priority DESC, e.check_in_time ASC, e.encounter_id ASC
    ");
    $stmt->execute([$todayStart, $todayEnd]);
    $allWaiting = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $priority = [];
    $appointments = [];
    $walkins = [];

    foreach ($allWaiting as $row) {
        if (!empty($row['is_priority'])) {
            $priority[] = $row;
        } elseif ($row['encounter_type'] === 'Appointment') {
            $appointments[] = $row;
        } else {
            $walkins[] = $row;
        }
    }

    // Interleave: 2 Appointments : 1 Walk-in
    $interleaved = [];
    $aIdx = 0;
    $wIdx = 0;
    $aCount = count($appointments);
    $wCount = count($walkins);

    while ($aIdx < $aCount || $wIdx < $wCount) {
        for ($i = 0; $i < 2 && $aIdx < $aCount; $i++) {
            $interleaved[] = $appointments[$aIdx++];
        }
        if ($wIdx < $wCount) {
            $interleaved[] = $walkins[$wIdx++];
        }
    }

    $finalQueue = array_merge($priority, $interleaved);
    foreach ($finalQueue as $idx => &$item) {
        $item['queue_position'] = $idx + 1;
    }
    return $finalQueue;
}

/**
 * Returns patient's live queue status & position
 */
function get_patient_live_queue_info(PDO $pdo, int $encounterId, string $todayStart, string $todayEnd): ?array {
    // Check currently serving
    $stmtServing = $pdo->prepare("
        SELECT queue_number, encounter_id FROM encounters 
        WHERE visit_date BETWEEN ? AND ? AND queue_status = 'Serving' 
        ORDER BY encounter_id DESC LIMIT 1
    ");
    $stmtServing->execute([$todayStart, $todayEnd]);
    $serving = $stmtServing->fetch(PDO::FETCH_ASSOC);
    $servingNumber = $serving ? $serving['queue_number'] : 'Standby';

    $queue = get_interleaved_queue($pdo, $todayStart, $todayEnd);
    $totalWaiting = count($queue);

    foreach ($queue as $item) {
        if ((int)$item['encounter_id'] === $encounterId) {
            return [
                'found'          => true,
                'serving_number' => $servingNumber,
                'my_number'      => $item['queue_number'],
                'position'       => $item['queue_position'],
                'ahead_count'    => $item['queue_position'] - 1,
                'total_waiting'  => $totalWaiting,
                'is_priority'    => (int)$item['is_priority'],
                'queue_status'   => $item['queue_status'],
                'check_in_status'=> $item['check_in_status']
            ];
        }
    }

    // If not in waiting queue (could be 'Serving', 'Completed', or 'Booked')
    $stmtSelf = $pdo->prepare("SELECT queue_number, queue_status, check_in_status, is_priority FROM encounters WHERE encounter_id = ?");
    $stmtSelf->execute([$encounterId]);
    $self = $stmtSelf->fetch(PDO::FETCH_ASSOC);

    if ($self) {
        return [
            'found'          => false,
            'serving_number' => $servingNumber,
            'my_number'      => $self['queue_number'],
            'position'       => ($self['queue_status'] === 'Serving') ? 0 : null,
            'ahead_count'    => 0,
            'total_waiting'  => $totalWaiting,
            'is_priority'    => (int)$self['is_priority'],
            'queue_status'   => $self['queue_status'],
            'check_in_status'=> $self['check_in_status']
        ];
    }

    return null;
}

/**
 * Audit Logging Function (RA 10173 Security Tracking)
 */
function audit_log(string $action, string $details = ''): void {
    try {
        $pdo = get_db();
        $userId   = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'Guest';
        $role     = $_SESSION['role'] ?? 'Guest';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, username, role, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $username, $role, $action, $details, $ip]);
    } catch (Exception $e) {
        // Fail silently so logging error doesn't break primary transactions
        error_log("Audit log failed: " . $e->getMessage());
    }
}

/**
 * Retrieve the Primary Attending Physician dynamically from the database users table
 */
function get_attending_physician(?PDO $pdo = null): array {
    static $cachedDoctor = null;
    if ($cachedDoctor !== null) {
        return $cachedDoctor;
    }
    if (!$pdo) {
        $pdo = get_db();
    }
    $stmt = $pdo->prepare("SELECT user_id, username, full_name, email FROM users WHERE role = 'Doctor' ORDER BY user_id ASC LIMIT 1");
    $stmt->execute();
    $doc = $stmt->fetch();
    if ($doc && !empty($doc['full_name'])) {
        $cachedDoctor = $doc;
    } else {
        $cachedDoctor = [
            'user_id' => 0,
            'username' => 'doctor',
            'full_name' => 'Attending Physician, MD',
            'email' => 'doctor@paikit.health'
        ];
    }
    return $cachedDoctor;
}

/**
 * Mask an email address for privacy display (e.g. ju***z@gmail.com)
 */
function mask_email(?string $email): string {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'your registered email';
    }
    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = $parts[1];
    $len = strlen($name);
    if ($len <= 2) {
        $maskedName = $name[0] . '*';
    } else {
        $maskedName = substr($name, 0, 2) . str_repeat('*', max(1, $len - 3)) . substr($name, -1);
    }
    return $maskedName . '@' . $domain;
}
