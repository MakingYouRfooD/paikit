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
        'host'         => 'smtp.gmail.com',
        'port'         => 587,
        'username'     => 'warcurry590@gmail.com',
        'password'     => 'best ukrq envy hxnl',
        'encryption'   => 'tls',
        'from_address' => 'warcurry590@gmail.com',
        'from_name'    => 'Paikit Health Center'
    ];

    try {
        if (function_exists('get_db')) {
            $pdo = get_db();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'smtp_%'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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

    // 0. Support for Resend HTTPS API (Port 443 - Bypasses Cloud SMTP Port Restrictions)
    $resendKey = getenv('RESEND_API_KEY') ?: 're_5SfvmZ2v_4HrkR3oTWh3yodMc3MinxUBr';
    if (!empty($resendKey)) {
        try {
            $ch = curl_init('https://api.resend.com/emails');
            $sender = 'Paikit Health Center <onboarding@resend.dev>';
            $payload = json_encode([
                'from'    => $sender,
                'to'      => [$toEmail],
                'subject' => $subject,
                'html'    => $htmlBody
            ]);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . trim($resendKey),
                'Content-Type: application/json'
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
                error_log("Resend API Delivery Failed ($code): " . $res);
            }
        } catch (\Throwable $e) {
            error_log("Resend Exception: " . $e->getMessage());
        }
    }

    // 0.1 Support for Brevo HTTPS API (Port 443 - Bypasses Cloud SMTP Port Restrictions)
    $brevoKey = getenv('BREVO_API_KEY') ?: '';
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
            }
        } catch (\Throwable $e) {}
    }

    // 1. If SMTP credentials exist, send live email over the internet
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
            $errorMsg = !empty($mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage();
            error_log("PHPMailer Live Send Error: " . $errorMsg);
            
            // Local development fallback: do not break workflow if SMTP fails on localhost
            $isLocal = (!isset($_SERVER['REMOTE_ADDR']) || in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost']) || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost'));
            if ($isLocal) {
                try {
                    $logDir = __DIR__ . '/../logs';
                    if (!is_dir($logDir)) {
                        @mkdir($logDir, 0777, true);
                    }
                    $logLine = "[" . date('Y-m-d H:i:s') . "] (FALLBACK - SMTP FAILED: {$errorMsg}) TO: {$toEmail} ({$toName}) | SUBJECT: {$subject}\n";
                    @file_put_contents($logDir . '/mail.log', $logLine, FILE_APPEND);
                    @file_put_contents($logDir . '/latest_reset_email.html', $htmlBody);
                } catch (\Throwable $t) {}

                return [
                    'success'   => true, 
                    'sent'      => false, 
                    'simulated' => true, 
                    'error'     => $errorMsg,
                    'notice'    => 'Live SMTP authentication failed. Operating in local fallback mode.'
                ];
            }

            return [
                'success'   => false, 
                'sent'      => false, 
                'simulated' => false, 
                'error'     => $errorMsg
            ];
        } catch (\Throwable $t) {
            error_log("PHPMailer Unexpected Error: " . $t->getMessage());
            $isLocal = (!isset($_SERVER['REMOTE_ADDR']) || in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost']) || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost'));
            if ($isLocal) {
                return [
                    'success'   => true, 
                    'sent'      => false, 
                    'simulated' => true, 
                    'error'     => $t->getMessage(),
                    'notice'    => 'Local fallback mode enabled.'
                ];
            }
            return [
                'success'   => false, 
                'sent'      => false, 
                'simulated' => false, 
                'error'     => $t->getMessage()
            ];
        }
    }

    // 2. If SMTP credentials are not yet configured (Localhost / Testing Mode)
    // Seamlessly simulate delivery so developers/users are never blocked by an error screen!
    try {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logLine = "[" . date('Y-m-d H:i:s') . "] TO: {$toEmail} ({$toName}) | SUBJECT: {$subject}\n";
        @file_put_contents($logDir . '/mail.log', $logLine, FILE_APPEND);
        @file_put_contents($logDir . '/latest_reset_email.html', $htmlBody);
    } catch (\Throwable $e) {}

    return [
        'success'   => true, 
        'sent'      => false, 
        'simulated' => true, 
        'error'     => null,
        'notice'    => 'Simulated delivery (SMTP credentials not yet entered in config/mail.php).'
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
