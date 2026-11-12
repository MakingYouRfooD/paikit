<?php
/**
 * config/mail.php - PHPMailer Configuration & Hybrid SMTP Delivery
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173)
 */

require_once __DIR__ . '/../includes/phpmailer/Exception.php';
require_once __DIR__ . '/../includes/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../includes/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

/**
 * Returns active SMTP configuration from database or fallback constants/env vars.
 */
function get_mail_config(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'host'          => 'smtp.gmail.com',
        'port'          => 587,
        'username'      => 'warcurry590@gmail.com',
        'password'      => 'best ukrq envy hxnl',
        'encryption'    => 'tls',
        'from_address'  => 'warcurry590@gmail.com',
        'from_name'     => 'Paikit Health Center',
        'brevo_api_key' => getenv('BREVO_API_KEY') ?: ''
    ];

    try {
        if (function_exists('get_db')) {
            $pdo = get_db();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'smtp_%' OR setting_key IN ('resend_api_key', 'brevo_api_key')");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['setting_key'] === 'resend_api_key') {
                    if (!empty($row['setting_value'])) {
                        $config['resend_api_key'] = trim($row['setting_value']);
                    }
                    continue;
                }
                if ($row['setting_key'] === 'brevo_api_key') {
                    if (!empty($row['setting_value'])) {
                        $config['brevo_api_key'] = trim($row['setting_value']);
                    }
                    continue;
                }
                $key = str_replace('smtp_', '', $row['setting_key']);
                if (!empty($row['setting_value'])) {
                    if ($key === 'from_email') {
                        $config['from_address'] = trim($row['setting_value']);
                    } elseif ($key === 'port') {
                        $config['port'] = (int)$row['setting_value'];
                    } else {
                        $config[$key] = trim($row['setting_value']);
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // Fall back to constants/defaults
    }

    // Overrides via constants or environment variables if defined
    if (getenv('SMTP_HOST')) $config['host'] = getenv('SMTP_HOST');
    elseif (defined('MAIL_HOST') && !empty(MAIL_HOST)) $config['host'] = MAIL_HOST;

    if (getenv('SMTP_PORT')) $config['port'] = (int)getenv('SMTP_PORT');
    elseif (defined('MAIL_PORT') && !empty(MAIL_PORT)) $config['port'] = (int)MAIL_PORT;

    if (getenv('SMTP_USER') ?: getenv('SMTP_USERNAME')) $config['username'] = getenv('SMTP_USER') ?: getenv('SMTP_USERNAME');
    elseif (defined('MAIL_USERNAME') && !empty(MAIL_USERNAME)) $config['username'] = MAIL_USERNAME;

    if (getenv('SMTP_PASS') ?: getenv('SMTP_PASSWORD')) $config['password'] = getenv('SMTP_PASS') ?: getenv('SMTP_PASSWORD');
    elseif (defined('MAIL_PASSWORD') && !empty(MAIL_PASSWORD)) $config['password'] = MAIL_PASSWORD;

    if (getenv('SMTP_ENCRYPTION')) $config['encryption'] = getenv('SMTP_ENCRYPTION');
    elseif (defined('MAIL_ENCRYPTION') && !empty(MAIL_ENCRYPTION)) $config['encryption'] = MAIL_ENCRYPTION;

    if (getenv('SMTP_FROM_EMAIL')) $config['from_address'] = getenv('SMTP_FROM_EMAIL');
    elseif (defined('MAIL_FROM_ADDRESS') && !empty(MAIL_FROM_ADDRESS)) $config['from_address'] = MAIL_FROM_ADDRESS;

    if (getenv('SMTP_FROM_NAME')) $config['from_name'] = getenv('SMTP_FROM_NAME');
    elseif (defined('MAIL_FROM_NAME') && !empty(MAIL_FROM_NAME)) $config['from_name'] = MAIL_FROM_NAME;

    return $config;
}

/**
 * Creates and configures a PHPMailer instance using active SMTP settings.
 */
function create_mailer(): PHPMailer {
    $config = get_mail_config();
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    if (!empty($config['username']) && !empty($config['password'])) {
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = trim($config['username']);
        $mail->Password   = trim($config['password']);
        
        $port = (int)$config['port'];
        $encryption = strtolower($config['encryption'] ?? '');
        if ($port === 465 || $encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = !empty($port) ? $port : 587;
        }
        $mail->Timeout    = 15;

        // Local SSL certificate compatibility
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ];
    }

    $fromEmail = !empty($config['from_address']) ? $config['from_address'] : ($config['username'] ?: 'paikithealthcenter@gmail.com');
    $fromName  = !empty($config['from_name']) ? $config['from_name'] : 'Paikit Health Center';
    $mail->setFrom($fromEmail, $fromName);

    return $mail;
}

/**
 * Sends an email using PHPMailer.
 * Automatically falls back to simulated development mode if SMTP credentials are not yet entered.
 * Returns array: ['success' => bool, 'sent' => bool, 'simulated' => bool, 'error' => string|null]
 */
function send_system_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): array {
    $config = get_mail_config();
    $lastError = null;

    $isCloudOrRender = !empty(getenv('RENDER')) || (!empty($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'onrender.com'));
    $brevoKey = getenv('BREVO_API_KEY') ?: ($config['brevo_api_key'] ?? '');

    // 1. Cloud Host Delivery (Render): Prioritize Brevo HTTPS API (Port 443)
    // Cloud providers (such as Render) strictly block outbound SMTP ports 587 and 465.
    // Brevo HTTPS API bypasses all port blocks and delivers directly to ANY recipient without domain requirements.
    if ($isCloudOrRender && !empty($brevoKey)) {
        try {
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            $payload = json_encode([
                'sender'      => ['name' => $config['from_name'], 'email' => $config['from_address']],
                'to'          => [['email' => $toEmail, 'name' => $toName]],
                'subject'     => $subject,
                'htmlContent' => $htmlBody
            ]);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'api-key: ' . trim($brevoKey),
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                return ['success' => true, 'sent' => true, 'simulated' => false, 'error' => null];
            } else {
                $lastError = "Brevo API Failed ($code): " . $res;
                error_log($lastError);
            }
        } catch (\Throwable $e) {
            $lastError = "Brevo Exception: " . $e->getMessage();
            error_log($lastError);
        }
    }

    // 2. Primary / Local: If SMTP credentials exist, send live email via PHPMailer (Gmail SMTP / Custom SMTP)
    if (!empty($config['username']) && !empty($config['password'])) {
        try {
            $mail = create_mailer();
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody ?: strip_tags($htmlBody);

            $mail->send();
            return [
                'success'   => true, 
                'sent'      => true, 
                'simulated' => false, 
                'error'     => null
            ];
        } catch (Exception $e) {
            $lastError = !empty($mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage();
            error_log("PHPMailer Live Send Error: " . $lastError);
        } catch (\Throwable $t) {
            $lastError = $t->getMessage();
            error_log("PHPMailer Unexpected Error: " . $lastError);
        }
    }

    // 3. Fallback: Brevo HTTPS API (Port 443 - if SMTP failed or not on cloud)
    if (!empty($brevoKey)) {
        try {
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            $payload = json_encode([
                'sender'      => ['name' => $config['from_name'], 'email' => $config['from_address']],
                'to'          => [['email' => $toEmail, 'name' => $toName]],
                'subject'     => $subject,
                'htmlContent' => $htmlBody
            ]);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'api-key: ' . trim($brevoKey),
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                return ['success' => true, 'sent' => true, 'simulated' => false, 'error' => null];
            } else {
                $lastError = "Brevo API Failed ($code): " . $res;
                error_log($lastError);
            }
        } catch (\Throwable $e) {
            $lastError = "Brevo Exception: " . $e->getMessage();
            error_log($lastError);
        }
    }

    // 4. Local Development Fallback: If no delivery method succeeded on localhost
    $isLocal = (!isset($_SERVER['REMOTE_ADDR']) || in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost']) || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost') || php_sapi_name() === 'cli');
    if ($isLocal) {
        try {
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $logLine = "[" . date('Y-m-d H:i:s') . "] (FALLBACK - LIVE FAILED: {$lastError}) TO: {$toEmail} ({$toName}) | SUBJECT: {$subject}\n";
            @file_put_contents($logDir . '/mail.log', $logLine, FILE_APPEND);
            if (stripos($subject, 'Verification') !== false || stripos($subject, 'Verify') !== false) {
                @file_put_contents($logDir . '/latest_verification_email.html', $htmlBody);
            } else {
                @file_put_contents($logDir . '/latest_reset_email.html', $htmlBody);
            }
        } catch (\Throwable $t) {}

        return [
            'success'   => true, 
            'sent'      => false, 
            'simulated' => true, 
            'error'     => $lastError,
            'notice'    => 'Local simulated delivery fallback.'
        ];
    }

    return [
        'success'   => false, 
        'sent'      => false, 
        'simulated' => false, 
        'error'     => $lastError ?: 'No active mail transport available.'
    ];
}

/**
 * Sends a Password Reset email with Paikit Health Center Official Branding.
 */
function send_password_reset_email(string $recipientEmail, string $recipientName, string $resetLink, int $expiresMinutes = 60): array {
    $subject = "Paikit Health Center - Password Reset Request";

    $htmlBody = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Password Reset Request</title>
        <style>
            body { font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px; color: #1e293b; }
            .container { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05); }
            .header { background: linear-gradient(135deg, #15803d 0%, #14532d 100%); color: #ffffff; padding: 28px 24px; text-align: center; }
            .content { padding: 32px 28px; line-height: 1.6; }
            .btn-reset { display: inline-block; background-color: #15803d; color: #ffffff !important; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; margin: 20px 0; }
            .footer { background-color: #f1f5f9; padding: 18px 24px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
            .notice-box { background-color: #f0fdf4; border-left: 4px solid #22c55e; padding: 12px 16px; margin: 18px 0; border-radius: 4px; font-size: 13px; color: #166534; }
            .link-text { word-break: break-all; color: #0284c7; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2 style="margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px;">PAIKIT HEALTH CENTER</h2>
                <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Primary Care & Consultation Information System</div>
            </div>
            <div class="content">
                <h3 style="margin-top: 0; color: #0f172a;">Password Reset Request</h3>
                <p>Hello <strong>' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
                <p>We received an official request to reset the password for your account associated with <strong>' . htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8') . '</strong>.</p>
                
                <p>To choose a new password, click the button below:</p>
                
                <div style="text-align: center; margin: 26px 0;">
                    <a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" class="btn-reset" target="_blank">
                        Reset My Password
                    </a>
                </div>

                <div class="notice-box">
                    <strong>Important:</strong> This password reset link is valid for <strong>' . $expiresMinutes . ' minutes</strong>. Once expired, you will need to submit a new request.
                </div>

                <p style="font-size: 13px; color: #64748b;">If the button above does not work, copy and paste the following URL into your web browser:</p>
                <p class="link-text">' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '</p>

                <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
                
                <p style="font-size: 12px; color: #94a3b8; margin-bottom: 0;">
                    If you did not request this password reset, please ignore this email. Your account password will remain secure and unchanged.
                </p>
            </div>
            <div class="footer">
                <div><strong>Paikit Health Center</strong> &bull; Sitio Paikit, Canlubang, Calamba City, Laguna 4028</div>
                <div style="margin-top: 4px;">Protected under Republic Act No. 10173 (Data Privacy Act of 2012).</div>
            </div>
        </div>
    </body>
    </html>';

    $altBody = "PAIKIT HEALTH CENTER - PASSWORD RESET REQUEST\n\n"
             . "Hello {$recipientName},\n\n"
             . "We received a request to reset your password. Use the link below to set a new password:\n\n"
             . "{$resetLink}\n\n"
             . "This link will expire in {$expiresMinutes} minutes.\n\n"
             . "If you did not request this, please ignore this message.\n\n"
             . "Paikit Health Center, Canlubang, Calamba City, Laguna.";

    return send_system_email($recipientEmail, $recipientName, $subject, $htmlBody, $altBody);
}

/**
 * Sends an Official Email Verification email with OTP code and Instant Activation Link.
 */
function send_verification_email(string $recipientEmail, string $recipientName, string $otpCode, string $verifyLink, int $expiresMinutes = 60): array {
    $subject = "Verify Your Email Address - Paikit Health Center";

    $htmlBody = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Email Verification - Paikit Health Center</title>
        <style>
            body { font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px; color: #1e293b; }
            .container { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05); }
            .header { background: linear-gradient(135deg, #15803d 0%, #14532d 100%); color: #ffffff; padding: 28px 24px; text-align: center; }
            .content { padding: 32px 28px; line-height: 1.6; }
            .otp-box { background: #f0fdf4; border: 2px dashed #16a34a; border-radius: 10px; padding: 18px 24px; text-align: center; margin: 24px 0; }
            .otp-code { font-size: 34px; font-weight: 800; letter-spacing: 8px; color: #15803d; font-family: monospace; display: inline-block; }
            .btn-verify { display: inline-block; background-color: #15803d; color: #ffffff !important; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; margin: 16px 0; }
            .footer { background-color: #f1f5f9; padding: 18px 24px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
            .notice-box { background-color: #fefce8; border-left: 4px solid #ca8a04; padding: 12px 16px; margin: 18px 0; border-radius: 4px; font-size: 13px; color: #854d0e; }
            .link-text { word-break: break-all; color: #0284c7; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2 style="margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px;">PAIKIT HEALTH CENTER</h2>
                <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Primary Care & Consultation Information System</div>
            </div>
            <div class="content">
                <h3 style="margin-top: 0; color: #0f172a;">Verify Your Email Address</h3>
                <p>Hello <strong>' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
                <p>Thank you for registering with the <strong>Paikit Health Center Portal</strong>. Before you can sign in to your patient account, we need to verify your registered email address (<strong>' . htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8') . '</strong>).</p>
                
                <p style="margin-bottom: 6px;">You can verify your account by entering this <strong>6-digit verification code</strong> on the verification screen:</p>
                
                <div class="otp-box">
                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #166534; margin-bottom: 6px; letter-spacing: 1px;">Your Verification Code</div>
                    <div class="otp-code">' . htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8') . '</div>
                </div>

                <div style="text-align: center; margin: 20px 0;">
                    <p style="margin-bottom: 8px; font-size: 14px; color: #475569;">Or click the instant activation button below:</p>
                    <a href="' . htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8') . '" class="btn-verify" target="_blank">
                        Verify My Account Now
                    </a>
                </div>

                <div class="notice-box">
                    <strong>Note:</strong> This verification code and link will expire in <strong>' . $expiresMinutes . ' minutes</strong>. Once expired, you will need to request a new verification code.
                </div>

                <p style="font-size: 13px; color: #64748b;">If the button does not work, copy and paste this link into your browser:</p>
                <p class="link-text">' . htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8') . '</p>

                <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
                
                <p style="font-size: 12px; color: #94a3b8; margin-bottom: 0;">
                    If you did not sign up for an account at Paikit Health Center, please ignore this email. No account will be activated without your confirmation.
                </p>
            </div>
            <div class="footer">
                <div><strong>Paikit Health Center</strong> &bull; Sitio Paikit, Canlubang, Calamba City, Laguna 4028</div>
                <div style="margin-top: 4px;">Protected under Republic Act No. 10173 (Data Privacy Act of 2012).</div>
            </div>
        </div>
    </body>
    </html>';

    $altBody = "PAIKIT HEALTH CENTER - EMAIL VERIFICATION\n\n"
             . "Hello {$recipientName},\n\n"
             . "Thank you for registering. Before you can sign in, please verify your email address.\n\n"
             . "Your 6-Digit Verification Code: {$otpCode}\n\n"
             . "Or activate your account directly with this link:\n{$verifyLink}\n\n"
             . "This code expires in {$expiresMinutes} minutes.\n\n"
             . "Paikit Health Center, Canlubang, Calamba City, Laguna.";

    return send_system_email($recipientEmail, $recipientName, $subject, $htmlBody, $altBody);
}

/**
 * Sends a Security Alert email when a login attempt from another device is blocked.
 * Fully compliant with RA 10173 and provides immediate security instructions.
 */
function send_security_alert_email(string $recipientEmail, string $recipientName, array $details): array {
    $attemptIp     = $details['attempt_ip'] ?? 'Unknown IP';
    $attemptDevice = $details['attempt_device'] ?? 'Unknown Device';
    $activeDevice  = $details['active_device'] ?? 'Current Active Device';
    $attemptTime   = $details['attempt_time'] ?? date('F j, Y, g:i A \P\S\T');

    // Build Absolute Password Reset Portal URL
    $protocol = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) 
        ? $_SERVER['HTTP_X_FORWARDED_PROTO'] 
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $resetPortalLink = "{$protocol}://{$host}{$baseDir}/forgot_password?email=" . urlencode($recipientEmail);

    $subject = "🚨 Babala sa Seguridad: Hinarang na Pag-login sa Ibang Device - Paikit Health Center";

    $htmlBody = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Babala sa Seguridad - Paikit Health Center</title>
        <style>
            body { font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px; color: #1e293b; }
            .container { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #fee2e2; overflow: hidden; box-shadow: 0 4px 16px rgba(220, 38, 38, 0.08); }
            .header { background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); color: #ffffff; padding: 28px 24px; text-align: center; }
            .content { padding: 30px 28px; line-height: 1.6; }
            .alert-banner { background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 14px 18px; margin-bottom: 22px; border-radius: 6px; color: #991b1b; font-weight: 600; font-size: 14px; }
            .details-card { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
            .details-table { width: 100%; border-collapse: collapse; font-size: 13px; }
            .details-table td { padding: 7px 4px; vertical-align: top; }
            .details-table td.lbl { font-weight: 600; color: #64748b; width: 40%; }
            .details-table td.val { color: #0f172a; font-weight: 500; }
            .badge-blocked { display: inline-block; background-color: #fee2e2; color: #b91c1c; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 12px; }
            .btn-action { display: inline-block; background-color: #dc2626; color: #ffffff !important; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 700; font-size: 14px; margin: 16px 0; }
            .footer { background-color: #f1f5f9; padding: 16px 24px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
            .notice-box { background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; margin: 18px 0; border-radius: 4px; font-size: 13px; color: #92400e; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2 style="margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px;">PAIKIT HEALTH CENTER</h2>
                <div style="font-size: 13px; opacity: 0.95; margin-top: 4px;">Security & Account Protection Service</div>
            </div>
            <div class="content">
                <div class="alert-banner">
                    ⚠️ BABALA SA SEGURIDAD: Hinarang ang Pag-login sa Ibang Device
                </div>

                <p>Magandang araw, <strong>' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
                <p>Nais naming ipabatid sa iyo na may <strong>nagtangkang mag-log in</strong> sa iyong Paikit Health Center account gamit ang iyong mga credentials mula sa ibang device habang ikaw ay kasalukuyang naka-log in.</p>

                <p>Alinsunod sa <strong>Data Privacy Act of 2012 (RA 10173)</strong> at para sa kaligtasan ng iyong rekord medikal, <strong>HINARANG (BLOCKED)</strong> ng aming sistema ang pag-log in na ito dahil ipinagbabawal ang sabay na pag-access sa magkaibang device.</p>

                <div class="details-card">
                    <div style="font-weight: 700; color: #0f172a; margin-bottom: 10px; font-size: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                        Detalye ng Hinarang na Pagtatangka:
                    </div>
                    <table class="details-table">
                        <tr>
                            <td class="lbl">Petsa at Oras:</td>
                            <td class="val">' . htmlspecialchars($attemptTime, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr>
                            <td class="lbl">Device na Nagtangka:</td>
                            <td class="val">' . htmlspecialchars($attemptDevice, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr>
                            <td class="lbl">IP Address ng Nagtangka:</td>
                            <td class="val"><code>' . htmlspecialchars($attemptIp, ENT_QUOTES, 'UTF-8') . '</code></td>
                        </tr>
                        <tr>
                            <td class="lbl">Kasalukuyang Device Mo:</td>
                            <td class="val">' . htmlspecialchars($activeDevice, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr>
                            <td class="lbl">Aksyon ng System:</td>
                            <td class="val"><span class="badge-blocked">&#10006; HINARANG / BLOCKED</span></td>
                        </tr>
                    </table>
                </div>

                <div class="notice-box">
                    <strong>Gabay sa Paggamit at Seguridad:</strong>
                    <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                        <li><strong>Kung ikaw ito:</strong> Kung nais mong lumipat sa bagong cellphone o kompyuter, mangyaring mag-log out muna sa iyong lumang device bago mag-log in sa bago.</li>
                        <li><strong>Kung HINDI ikaw ito:</strong> May ibang taong may hawak ng iyong password! Mangyaring magpalit kaagad ng iyong password upang manatiling ligtas ang iyong account.</li>
                    </ul>
                </div>

                <div style="text-align: center; margin: 24px 0;">
                    <a href="' . htmlspecialchars($resetPortalLink, ENT_QUOTES, 'UTF-8') . '" class="btn-action" target="_blank">
                        Magpalit ng Password Kaagad
                    </a>
                </div>

                <p style="font-size: 12px; color: #94a3b8; margin-top: 20px;">
                    Paalala: Hinding-hindi hihingin ng mga kawani ng Paikit Health Center ang iyong password. Panatilihing kumpidensyal ang iyong login credentials.
                </p>
            </div>
            <div class="footer">
                <div><strong>Paikit Health Center</strong> &bull; Sitio Paikit, Canlubang, Calamba City, Laguna 4028</div>
                <div style="margin-top: 4px;">Protected under Republic Act No. 10173 (Data Privacy Act of 2012).</div>
            </div>
        </div>
    </body>
    </html>';

    $altBody = "PAIKIT HEALTH CENTER - BABALA SA SEGURIDAD\n\n"
             . "Kumusta {$recipientName},\n\n"
             . "May nagtangkang mag-log in sa iyong account mula sa ibang device ({$attemptDevice}, IP: {$attemptIp}) noong {$attemptTime}.\n\n"
             . "HINARANG ng aming sistema ang pag-log in na ito dahil kasalukuyan kang naka-log in sa device na: {$activeDevice}.\n\n"
             . "Kung hindi ikaw ang gumawa nito, mangyaring magpalit agad ng password gamit ang link na ito:\n"
             . "{$resetPortalLink}\n\n"
             . "Paikit Health Center, Sitio Paikit, Canlubang, Calamba City, Laguna.";

    return send_system_email($recipientEmail, $recipientName, $subject, $htmlBody, $altBody);
}
