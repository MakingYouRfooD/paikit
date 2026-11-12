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

// Database Credentials & Environment Configuration
// Automatically detects Supabase (PostgreSQL) or local XAMPP (MySQL)
define('ENV_DB_URL', getenv('DATABASE_URL') ?: (getenv('SUPABASE_DB_URL') ?: ''));
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'paikit_health_center');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Custom PDO wrapper to ensure seamless lastInsertId() support on PostgreSQL / Supabase
 */
class AppPDO extends PDO {
    public function lastInsertId(?string $name = null): string|false {
        $driver = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            if ($name !== null && $name !== '') {
                return parent::lastInsertId($name);
            }
            try {
                $val = $this->query("SELECT LASTVAL()")->fetchColumn();
                return $val !== false ? (string)$val : false;
            } catch (Throwable $e) {
                return parent::lastInsertId();
            }
        }
        return parent::lastInsertId($name);
    }
}

/**
 * Returns singleton PDO connection instance.
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dbUrl = ENV_DB_URL;
        $driver = 'mysql';

        if (!empty($dbUrl)) {
            // Parse complete connection URI (e.g. Supabase postgresql://...)
            $parsed = parse_url($dbUrl);
            $driver = 'pgsql';
            $host   = $parsed['host'] ?? '127.0.0.1';
            $port   = $parsed['port'] ?? 5432;
            $user   = isset($parsed['user']) ? urldecode($parsed['user']) : 'postgres';
            $pass   = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
            $dbname = ltrim($parsed['path'] ?? 'postgres', '/');
            $dsn    = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
        } else {
            $envDriver = getenv('DB_DRIVER');
            $port = DB_PORT;
            if (!empty($envDriver)) {
                $driver = strtolower($envDriver);
            } elseif ($port === '5432' || $port === '6543') {
                $driver = 'pgsql';
            }

            if ($driver === 'pgsql') {
                $portNum = !empty($port) ? $port : 5432;
                $sslmode = getenv('DB_SSLMODE') ?: 'require';
                $dsn = "pgsql:host=" . DB_HOST . ";port={$portNum};dbname=" . DB_NAME . ";sslmode={$sslmode}";
            } else {
                $portNum = !empty($port) ? $port : 3306;
                $dsn = "mysql:host=" . DB_HOST . ";port={$portNum};dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            }
            $user = DB_USER;
            $pass = DB_PASS;
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new AppPDO($dsn, $user, $pass, $options);
            // Synchronize database session timezone with Philippine Standard Time (PST / UTC+8)
            try {
                if ($driver === 'pgsql') {
                    $pdo->exec("SET TIME ZONE 'Asia/Manila'");
                } else {
                    $pdo->exec("SET time_zone = '+08:00'");
                }
            } catch (Throwable $tzErr) {
                // Non-critical: continue even if timezone table is not populated
            }
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
 * Ensures email verification schema exists in MySQL and PostgreSQL environments.
 */
function ensure_email_verification_schema(?PDO $pdo = null): void {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;

    try {
        if (!$pdo) {
            $pdo = get_db();
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Check if is_verified and email_verified_at exist in users table
        $hasIsVerified = false;
        $hasVerifiedAt = false;
        if ($driver === 'pgsql') {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'users'");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $hasIsVerified = in_array('is_verified', $cols, true);
            $hasVerifiedAt = in_array('email_verified_at', $cols, true);
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM `users`");
            while ($col = $stmt->fetch()) {
                if ($col['Field'] === 'is_verified') $hasIsVerified = true;
                if ($col['Field'] === 'email_verified_at') $hasVerifiedAt = true;
            }
        }

        if (!$hasIsVerified) {
            if ($driver === 'pgsql') {
                $pdo->exec("ALTER TABLE users ADD COLUMN is_verified SMALLINT DEFAULT 0");
            } else {
                $pdo->exec("ALTER TABLE users ADD COLUMN `is_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`");
            }
            // Mark existing initial users (Doctor, Staff, prior accounts) as verified
            $pdo->exec("UPDATE users SET is_verified = 1 WHERE is_verified = 0 OR is_verified IS NULL");
        }

        if (!$hasVerifiedAt) {
            if ($driver === 'pgsql') {
                $pdo->exec("ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL DEFAULT NULL");
            } else {
                $pdo->exec("ALTER TABLE users ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL AFTER `is_verified`");
            }
            $pdo->exec("UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE is_verified = 1 AND email_verified_at IS NULL");
        }

        // Ensure email_verifications table exists
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS email_verifications (
                    verification_id SERIAL PRIMARY KEY,
                    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
                    email VARCHAR(100) NOT NULL,
                    otp_code VARCHAR(10) NOT NULL,
                    token VARCHAR(64) NOT NULL UNIQUE,
                    expires_at TIMESTAMP NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_email_verif_token ON email_verifications(token);
                CREATE INDEX IF NOT EXISTS idx_email_verif_code ON email_verifications(email, otp_code);
                CREATE INDEX IF NOT EXISTS idx_email_verif_user ON email_verifications(user_id);
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `email_verifications` (
                    `verification_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT NOT NULL,
                    `email` VARCHAR(100) NOT NULL,
                    `otp_code` VARCHAR(10) NOT NULL,
                    `token` VARCHAR(64) NOT NULL UNIQUE,
                    `expires_at` DATETIME NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_email_verif_token` (`token`),
                    INDEX `idx_email_verif_code` (`email`, `otp_code`),
                    INDEX `idx_email_verif_user` (`user_id`),
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    } catch (\Throwable $e) {
        error_log("ensure_email_verification_schema warning: " . $e->getMessage());
    }
}

// Automatically enforce verification schema
ensure_email_verification_schema($pdo);

/**
 * Ensures single active session / device security schema exists in MySQL and PostgreSQL.
 */
function ensure_device_session_schema(?PDO $pdo = null): void {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;

    try {
        if (!$pdo) {
            $pdo = get_db();
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Fetch existing columns in users table
        $cols = [];
        if ($driver === 'pgsql') {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'users'");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM `users`");
            while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $col['Field'];
            }
        }

        $neededCols = [
            'active_session_id'    => "VARCHAR(128) DEFAULT NULL",
            'current_device_token' => "VARCHAR(128) DEFAULT NULL",
            'current_device_ip'    => "VARCHAR(45) DEFAULT NULL",
            'current_device_name'  => "VARCHAR(255) DEFAULT NULL",
            'last_activity_at'     => ($driver === 'pgsql') ? "TIMESTAMP NULL DEFAULT NULL" : "DATETIME DEFAULT NULL"
        ];

        foreach ($neededCols as $colName => $colDef) {
            if (!in_array($colName, $cols, true)) {
                if ($driver === 'pgsql') {
                    $pdo->exec("ALTER TABLE users ADD COLUMN {$colName} {$colDef}");
                } else {
                    $pdo->exec("ALTER TABLE `users` ADD COLUMN `{$colName}` {$colDef}");
                }
            }
        }

        // Create security_alerts table
        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS security_alerts (
                    alert_id SERIAL PRIMARY KEY,
                    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
                    alert_type VARCHAR(50) NOT NULL DEFAULT 'CONCURRENT_LOGIN_BLOCKED',
                    message TEXT NOT NULL,
                    attempt_ip VARCHAR(45) DEFAULT NULL,
                    attempt_device VARCHAR(255) DEFAULT NULL,
                    is_read SMALLINT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_sec_user_read ON security_alerts(user_id, is_read);
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `security_alerts` (
                    `alert_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT NOT NULL,
                    `alert_type` VARCHAR(50) NOT NULL DEFAULT 'CONCURRENT_LOGIN_BLOCKED',
                    `message` TEXT NOT NULL,
                    `attempt_ip` VARCHAR(45) DEFAULT NULL,
                    `attempt_device` VARCHAR(255) DEFAULT NULL,
                    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_sec_user_read` (`user_id`, `is_read`),
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    } catch (\Throwable $e) {
        error_log("ensure_device_session_schema warning: " . $e->getMessage());
    }
}

// Automatically enforce device session schema
ensure_device_session_schema($pdo);

/**
 * Resolves real client IP address safely
 */
function get_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = trim($parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $candidate = trim($_SERVER['HTTP_CLIENT_IP']);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
}

/**
 * Returns human-readable device & browser label
 */
function get_device_description(?string $ua = null): string {
    $agent = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (empty($agent)) {
        return 'Unknown Device';
    }

    $browser = 'Web Browser';
    if (stripos($agent, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($agent, 'Edg') !== false) {
        $browser = 'Microsoft Edge';
    } elseif (stripos($agent, 'OPR') !== false || stripos($agent, 'Opera') !== false) {
        $browser = 'Opera';
    } elseif (stripos($agent, 'SamsungBrowser') !== false) {
        $browser = 'Samsung Internet';
    } elseif (stripos($agent, 'Chrome') !== false) {
        $browser = 'Google Chrome';
    } elseif (stripos($agent, 'Safari') !== false) {
        $browser = 'Safari';
    }

    $platform = 'Device';
    if (stripos($agent, 'iPhone') !== false) {
        $platform = 'iPhone';
    } elseif (stripos($agent, 'iPad') !== false) {
        $platform = 'iPad';
    } elseif (stripos($agent, 'Android') !== false) {
        $platform = 'Android Phone';
    } elseif (stripos($agent, 'Windows NT 10.0') !== false) {
        $platform = 'Windows 10/11 PC';
    } elseif (stripos($agent, 'Windows') !== false) {
        $platform = 'Windows PC';
    } elseif (stripos($agent, 'Macintosh') !== false || stripos($agent, 'Mac OS') !== false) {
        $platform = 'Mac';
    } elseif (stripos($agent, 'Linux') !== false) {
        $platform = 'Linux';
    }

    return "{$browser} on {$platform}";
}

/**
 * Cryptographic device token cookie management (persistent per browser)
 */
function get_device_token(): string {
    if (!empty($_COOKIE['pk_device_token']) && is_string($_COOKIE['pk_device_token']) && strlen($_COOKIE['pk_device_token']) >= 32) {
        return $_COOKIE['pk_device_token'];
    }
    $token = bin2hex(random_bytes(32));
    $_COOKIE['pk_device_token'] = $token;
    if (!headers_sent()) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        setcookie('pk_device_token', $token, [
            'expires'  => time() + (86400 * 365), // 1 year persistence
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    return $token;
}

/**
 * Checks whether an account is actively logged in on another device.
 * Enforces strictly single active session per user.
 */
function check_concurrent_device_login(PDO $pdo, int $userId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT active_session_id, current_device_token, current_device_ip, current_device_name, last_activity_at 
            FROM users 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['blocked' => false];
        }

        $activeSession     = $user['active_session_id'] ?? null;
        $lastActivity      = $user['last_activity_at'] ?? null;
        $activeDeviceToken = $user['current_device_token'] ?? null;

        // 15-minute clinical inactivity threshold (standard HIPAA / RA 10173 session lifetime)
        $timeoutSeconds = 900;

        if (!empty($activeSession) && !empty($lastActivity)) {
            $lastTime = strtotime($lastActivity);
            $diff = time() - $lastTime;

            // If last active was within the active timeout window
            if ($diff < $timeoutSeconds) {
                $thisDeviceToken = get_device_token();

                // If active device token exists and does not match the attempting device
                if (!empty($activeDeviceToken) && !hash_equals($activeDeviceToken, $thisDeviceToken)) {
                    return [
                        'blocked'       => true,
                        'device'        => $user['current_device_name'] ?: 'Another device',
                        'ip'            => $user['current_device_ip'] ?: 'Unknown IP',
                        'last_activity' => $user['last_activity_at'],
                        'seconds_ago'   => $diff
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("check_concurrent_device_login error: " . $e->getMessage());
    }

    return ['blocked' => false];
}

/**
 * Records newly authorized active device login session
 */
function record_active_login(PDO $pdo, int $userId): void {
    try {
        $deviceToken = get_device_token();
        $deviceIp    = get_client_ip();
        $deviceName  = get_device_description();
        $sessId      = session_id();
        $now         = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            UPDATE users 
            SET active_session_id = ?,
                current_device_token = ?,
                current_device_ip = ?,
                current_device_name = ?,
                last_activity_at = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$sessId, $deviceToken, $deviceIp, $deviceName, $now, $userId]);
    } catch (\Throwable $e) {
        error_log("record_active_login error: " . $e->getMessage());
    }
}

/**
 * Updates last_activity_at for active user (throttled to at most once per 30 seconds)
 */
function update_user_activity(?PDO $pdo = null): void {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return;

    $now = time();
    $lastUpdate = $_SESSION['last_activity_ping'] ?? 0;
    if (($now - $lastUpdate) >= 30) {
        $_SESSION['last_activity_ping'] = $now;
        try {
            if (!$pdo) {
                $pdo = get_db();
            }
            $stmt = $pdo->prepare("UPDATE users SET last_activity_at = ? WHERE user_id = ?");
            $stmt->execute([date('Y-m-d H:i:s', $now), (int)$userId]);
        } catch (\Throwable $e) {
            // Fail silently
        }
    }
}

/**
 * Clears active login session on explicit user logout
 */
function clear_active_login(PDO $pdo, int $userId): void {
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET active_session_id = NULL,
                current_device_token = NULL,
                last_activity_at = NULL
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
    } catch (\Throwable $e) {
        error_log("clear_active_login error: " . $e->getMessage());
    }
}

/**
 * Inserts in-app security alert notification
 */
function record_security_alert(
    PDO $pdo, 
    int $userId, 
    string $type, 
    string $message, 
    ?string $attemptIp = null, 
    ?string $attemptDevice = null
): int {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO security_alerts (user_id, alert_type, message, attempt_ip, attempt_device, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, ?)
        ");
        $stmt->execute([$userId, $type, $message, $attemptIp, $attemptDevice, date('Y-m-d H:i:s')]);
        return (int)$pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log("record_security_alert error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Fetches unread security alerts for current user
 */
function get_unread_security_alerts(PDO $pdo, int $userId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM security_alerts 
            WHERE user_id = ? AND is_read = 0 
            ORDER BY alert_id DESC 
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Marks security alert as read
 */
function mark_security_alert_read(PDO $pdo, int $alertId, int $userId): bool {
    try {
        $stmt = $pdo->prepare("UPDATE security_alerts SET is_read = 1 WHERE alert_id = ? AND user_id = ?");
        return $stmt->execute([$alertId, $userId]);
    } catch (\Throwable $e) {
        return false;
    }
}

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
    update_user_activity();
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
function generate_queue_number(PDO $pdo, string $prefix = 'W', ?string $targetDate = null): string {
    $date = $targetDate ?: date('Y-m-d');
    $dayStart = $date . ' 00:00:00';
    $dayEnd   = $date . ' 23:59:59';

    $maxNum = 0;
    try {
        $driver = 'mysql';
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {}

        if ($driver === 'pgsql') {
            // PostgreSQL / Supabase ANSI cast
            $stmt = $pdo->prepare("
                SELECT COALESCE(MAX(CAST(NULLIF(SUBSTRING(queue_number FROM 3), '') AS INTEGER)), 0)
                FROM encounters 
                WHERE visit_date BETWEEN ?::timestamp AND ?::timestamp 
                  AND queue_number LIKE ?
            ");
        } else {
            // MySQL / MariaDB CAST as INTEGER
            $stmt = $pdo->prepare("
                SELECT COALESCE(MAX(CAST(SUBSTRING(queue_number, 3) AS INTEGER)), 0) 
                FROM encounters 
                WHERE visit_date BETWEEN ? AND ? 
                  AND queue_number LIKE ?
            ");
        }
        $stmt->execute([$dayStart, $dayEnd, $prefix . '-%']);
        $maxNum = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Robust PHP regex extraction fallback: ensures booking never breaks even if SQL dialect differs
        try {
            $stmt = $pdo->prepare("SELECT queue_number FROM encounters WHERE visit_date BETWEEN ? AND ? AND queue_number LIKE ?");
            $stmt->execute([$dayStart, $dayEnd, $prefix . '-%']);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $qNum = $row['queue_number'] ?? '';
                if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', $qNum, $m)) {
                    $val = (int)$m[1];
                    if ($val > $maxNum) {
                        $maxNum = $val;
                    }
                }
            }
        } catch (Throwable $e2) {
            // Keep default 0
        }
    }

    $nextNum = $maxNum + 1;
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
 * Strict Deliverable Email Validator:
 * 1. Validates standard RFC email syntax (filter_var).
 * 2. Blocks known disposable / throwaway temporary email services (e.g. mailinator, tempmail, guerrillamail, yopmail, 10minutemail).
 * 3. Catches common typo domains (e.g. gamil.com, yaho.com, outlok.com, hotmial.com).
 * 4. Rejects obvious fake/dummy inputs (e.g. test@test.com, fake@fake.com, asdasd@asdasd.com).
 * 5. Verifies active DNS MX or A records to ensure domain is real and can receive emails.
 */
function is_valid_deliverable_email(string $email, ?string &$errorMessage = null): bool {
    $email = trim($email);

    // 1. Basic empty & syntax check
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $errorMessage = "Please enter a valid email address format (e.g. juan.delacruz@gmail.com).";
        return false;
    }

    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        $errorMessage = "Invalid email format.";
        return false;
    }

    $localPart = strtolower(trim($parts[0]));
    $domain    = strtolower(trim($parts[1]));

    // 2. Reject obvious fake local parts or gibberish
    $dummyLocalPatterns = [
        'test', 'testing', 'fake', 'dummy', 'sample', 'asdf', 'asdasd', 'qwerty',
        '123456', 'noreply', 'no-reply', 'admin', 'administrator', 'root', 'null',
        'undefined', 'email', 'user', 'someone', 'nobody', 'temp'
    ];
    if (in_array($localPart, $dummyLocalPatterns, true) && in_array($domain, ['test.com', 'example.com', 'fake.com', 'domain.com', 'email.com', 'asdasd.com', 'sample.com'], true)) {
        $errorMessage = "The email address '" . sanitize($email) . "' is an invalid test or dummy address. Please use your real, active personal email.";
        return false;
    }

    // Reject repetitive single-character or keyboard smash local parts (e.g. "aaaaa", "asdfghjk")
    if (preg_match('/^(.)\1{4,}$/', $localPart)) {
        $errorMessage = "The email address contains an invalid repetitive username. Please enter your real email.";
        return false;
    }

    // 3. Common domain typo detection with helpful suggestion
    $domainTypos = [
        'gamil.com'   => 'gmail.com',
        'gmaill.com'  => 'gmail.com',
        'gmial.com'   => 'gmail.com',
        'gmai.com'    => 'gmail.com',
        'gmal.com'    => 'gmail.com',
        'gmaul.com'   => 'gmail.com',
        'yaho.com'    => 'yahoo.com',
        'yahooo.com'  => 'yahoo.com',
        'yhaoo.com'   => 'yahoo.com',
        'yaho.com.ph' => 'yahoo.com.ph',
        'hotmial.com' => 'hotmail.com',
        'hotmai.com'  => 'hotmail.com',
        'outlok.com'  => 'outlook.com',
        'outloo.com'  => 'outlook.com',
    ];
    if (isset($domainTypos[$domain])) {
        $suggested = $domainTypos[$domain];
        $errorMessage = "It looks like you typed '@{$domain}'. Did you mean '@{$suggested}'? Please verify and correct your email address.";
        return false;
    }

    // 4. Known Disposable / Temporary / Throwaway Email Domains
    $disposableDomains = [
        'mailinator.com', 'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'guerrillamail.com',
        'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com', 'sharklasers.com',
        'grr.la', '10minutemail.com', '10minutemail.net', 'trashmail.com', 'trashmail.net',
        'yopmail.com', 'yopmail.fr', 'yopmail.net', 'dispostable.com', 'getnada.com',
        'throwawaymail.com', 'fakeinbox.com', 'burnermail.io', 'mohmal.com', 'mytemp.email',
        'generator.email', 'maildrop.cc', 'inboxkitten.com', 'crazymailing.com', 'armyspy.com',
        'cuvox.de', 'dayrep.com', 'einrot.com', 'fleckens.hu', 'gustr.com', 'jourrapide.com',
        'rhyta.com', 'superrito.com', 'teleworm.us', 'emailfake.com', 'fakemailgenerator.com',
        'discard.email', 'spambog.com', 'tempail.com', 'throwaway.email', 'mailsac.com',
        'trashmail.io', 'emailondeck.com', 'tempr.email', 'minuteinbox.com', 'dropmail.me'
    ];
    if (in_array($domain, $disposableDomains, true) || str_contains($domain, 'tempmail') || str_contains($domain, 'disposable') || str_contains($domain, 'throwaway')) {
        $errorMessage = "Disposable or temporary email services (@{$domain}) are strictly prohibited. Paikit Health Center requires a permanent email address (e.g. Gmail, Yahoo, Outlook) for official patient records.";
        return false;
    }

    // 5. Whitelist check for top trusted providers (fast-path)
    $trustedProviders = [
        'gmail.com', 'yahoo.com', 'yahoo.com.ph', 'outlook.com', 'hotmail.com',
        'live.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com',
        'zoho.com', 'deped.gov.ph', 'doh.gov.ph', 'up.edu.ph', 'ust.edu.ph',
        'dlsu.edu.ph', 'ateneo.edu', 'plm.edu.ph', 'pup.edu.ph', 'paikit.health'
    ];
    if (in_array($domain, $trustedProviders, true)) {
        return true;
    }

    // 6. Live DNS MX/A Record Verification (Ensures domain actually exists & can receive mail)
    if (function_exists('checkdnsrr')) {
        $hasMx = @checkdnsrr($domain, 'MX');
        $hasA  = $hasMx ? true : @checkdnsrr($domain, 'A');

        if (!$hasMx && !$hasA) {
            $errorMessage = "The email domain '@{$domain}' does not exist or has no active mail server to receive emails. Please enter a real, active email address.";
            return false;
        }
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
 * Audit Logging Function (RA 10173 Security Tracking & Activity Auditing)
 * 
 * Supports legacy signature audit_log($action, $details) as well as structured
 * audit trails with entity tracking and changes diff payload.
 */
function audit_log(
    string $action, 
    string $details = '', 
    ?string $entityType = null, 
    ?string $entityId = null, 
    $changes = null
): void {
    try {
        $pdo = get_db();
        $userId   = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'Guest';
        $role     = $_SESSION['role'] ?? 'Guest';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $payload = null;
        if ($changes !== null) {
            $payload = is_string($changes) ? $changes : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, role, action, entity_type, entity_id, details, changes_payload, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $username, $role, $action, $entityType, $entityId, $details, $payload, $ip]);
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
