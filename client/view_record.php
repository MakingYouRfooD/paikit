<?php
/**
 * client/view_record.php - Secure View for Walk-in & Client Diagnoses & E-Prescriptions
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173) - Verifiable Token or Auth Access Only
 */

require_once __DIR__ . '/../db.php';

$code = trim($_GET['code'] ?? '');
$encounterId = isset($_GET['encounter_id']) ? (int)$_GET['encounter_id'] : 0;
$encounter = null;

// SECURITY & CLEARANCE VERIFICATION (Dual-Path Access under RA 10173)

// Path A: Verification via Cryptographic Transaction Code (Guest Walk-in Access)
if (!empty($code)) {
    $stmt = $pdo->prepare("
        SELECT e.*, p.full_name, p.mobile_number, p.birth_date, p.gender, p.sitio, p.street_address, p.account_type,
               t.transaction_code, pr.prescription_id, pr.medication_details, pr.dispense_status
        FROM walkin_access_tokens t
        JOIN encounters e ON t.encounter_id = e.encounter_id
        JOIN patients p ON e.patient_id = p.patient_id
        LEFT JOIN prescriptions pr ON e.encounter_id = pr.encounter_id
        WHERE t.transaction_code = ?
    ");
    $stmt->execute([$code]);
    $encounter = $stmt->fetch();

    if ($encounter) {
        audit_log('GUEST_RECORD_VIEWED', "Walk-in slip code '{$code}' accessed for Encounter #{$encounter['encounter_id']}.");
    }
}
// Path B: Verification via Logged-In User Session (Client accessing own record, or Doctor)
elseif ($encounterId > 0 && is_logged_in()) {
    $currentUser = get_auth_user();

    $stmt = $pdo->prepare("
        SELECT e.*, p.full_name, p.mobile_number, p.birth_date, p.gender, p.sitio, p.street_address, p.account_type,
               t.transaction_code, pr.prescription_id, pr.medication_details, pr.dispense_status
        FROM encounters e
        JOIN patients p ON e.patient_id = p.patient_id
        LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
        LEFT JOIN prescriptions pr ON e.encounter_id = pr.encounter_id
        WHERE e.encounter_id = ?
    ");
    $stmt->execute([$encounterId]);
    $encounter = $stmt->fetch();

    if ($encounter) {
        // Enforce patient ownership if role is Client
        if ($currentUser['role'] === 'Client' && (int)$encounter['patient_id'] !== (int)$currentUser['patient_id']) {
            audit_log('SECURITY_VIOLATION', "Client User #{$currentUser['user_id']} attempted to access unauthorized Encounter #{$encounterId}.");
            $encounter = null; // Deny access
        } else {
            audit_log('RECORD_VIEWED', "User '{$currentUser['username']}' viewed Encounter #{$encounterId}.");
        }
    }
}

// ACCESS DENIED IF RECORD NOT FOUND OR UNAUTHORIZED
if (!$encounter) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Record Not Accessible - Paikit Health Center</title>
        <link rel="stylesheet" href="../css/bootstrap.min.css">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center min-vh-100 p-3">
        <div class="card shadow-sm border-danger" style="max-width: 520px;">
            <div class="card-header bg-danger text-white fw-bold">
                <i class="bi bi-shield-x me-2"></i> Clinical Record Unavailable
            </div>
            <div class="card-body p-4">
                <h5 class="card-title text-danger">Invalid Slip Code or Restricted Access</h5>
                <p class="card-text text-secondary">
                    The requested medical consultation slip could not be verified. In accordance with the 
                    <strong>Data Privacy Act of 2012 (RA 10173)</strong>, medical records are strictly protected.
                </p>
                <div class="alert alert-secondary small mb-3">
                    Please ensure that you entered the exact Transaction Code printed on your walk-in slip, or log into your verified client account.
                </div>
                <div class="d-flex justify-content-between">
                    <a href="../" class="btn btn-primary">Return to Home</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Decode Data
$attendingDoctor = get_attending_physician($pdo);
$vitals = json_decode($encounter['vital_signs'] ?? '', true) ?: [];
$prescriptions = json_decode($encounter['medication_details'] ?? '', true) ?: [];

// Compute Age
$birthDate = new DateTime($encounter['birth_date']);
$visitDate = new DateTime($encounter['visit_date']);
$age = $visitDate->diff($birthDate)->y;

// Verification Link for QR Code (Clean URL)
$verifyUrl = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/paikit/client/view_record?code=" . ($encounter['transaction_code'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Consultation Record & E-Prescription - Paikit Health Center</title>
    <link rel="icon" type="image/png" href="../img/logo.png">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/modern-theme.css">
    <script src="../js/qrcode.min.js"></script>
    <script src="../js/accessibility.js"></script>
    <style>
        body {
            background-color: #f0fdf4;
        }
        .paper-record {
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            max-width: 820px;
            margin: 30px auto;
            padding: 40px;
            position: relative;
        }
        @media (max-width: 767.98px) {
            .paper-record {
                margin: 12px auto !important;
                padding: 20px 14px !important;
                border-radius: 12px !important;
            }
            .clinic-header {
                padding-bottom: 10px !important;
                margin-bottom: 16px !important;
            }
            .clinic-header img {
                width: 60px !important;
                height: 60px !important;
            }
            .rx-symbol {
                font-size: 2.2rem !important;
            }
            .signature-line {
                width: 200px !important;
                margin: 25px 0 5px auto !important;
            }
            .watermark {
                font-size: 2.4rem !important;
            }
            .no-print .container {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 0.5rem !important;
            }
            .no-print .d-flex {
                flex-wrap: wrap !important;
                justify-content: center !important;
            }
        }
        .clinic-header {
            border-bottom: 3px double #0d6efd;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .rx-symbol {
            font-family: 'Times New Roman', Times, serif;
            font-size: 3rem;
            font-weight: bold;
            color: #0d6efd;
            line-height: 1;
        }
        .signature-line {
            width: 260px;
            border-bottom: 1px solid #000;
            margin: 40px 0 5px auto;
        }
        .watermark {
            position: absolute;
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 4.5rem;
            color: rgba(13, 110, 253, 0.04);
            font-weight: 900;
            pointer-events: none;
            text-transform: uppercase;
        }
        @media print {
            body {
                background: #fff !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .paper-record {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 15px !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body>

<!-- Control Bar (Hidden when printing) -->
<div class="no-print bg-dark py-2 px-3 text-white sticky-top shadow-sm">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <img src="../img/logo.png" alt="Paikit Logo" class="brand-logo-circle" style="width: 32px; height: 32px;">
            <span class="badge bg-success">Official Medical Record</span>
            <span class="small text-white-50">Transaction Slip: <code><?= sanitize($encounter['transaction_code']) ?></code></span>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="senior-toggle-btn me-1" onclick="toggleSeniorMode()" title="Toggle larger text for seniors">
                <i class="bi bi-fonts fs-6"></i> <strong>A+</strong> Senior Text
            </button>
            <?php if (is_logged_in() && ($_SESSION['role'] ?? '') === 'Client'): ?>
                <a href="dashboard" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-speedometer2 me-1"></i> Dashboard
                </a>
            <?php else: ?>
                <a href="../" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-house-door me-1"></i> Home
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-success btn-sm fw-bold" onclick="window.print()">
                <i class="bi bi-printer-fill me-1"></i> Print / Save as PDF
            </button>
        </div>
    </div>
</div>

<div class="container">
    <div class="paper-record">
        <div class="watermark">PAIKIT HEALTH CENTER</div>

        <!-- Official Header with Brand Logo -->
        <div class="clinic-header text-center mb-3">
            <div class="d-flex justify-content-center mb-2">
                <img src="../img/logo.png" alt="Paikit Health Center Logo" style="width: 76px; height: 76px; border-radius: 50%; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
            </div>
            <div class="small text-uppercase text-secondary fw-bold" style="letter-spacing: 1px;">Republic of the Philippines</div>
            <div class="small text-secondary">Department of Health &bull; Rural Health Unit</div>
            <h3 class="fw-bold text-success mb-1 mt-1">PAIKIT HEALTH CENTER</h3>
            <div class="small text-muted">Barangay Canlubang, Calamba City, Laguna &bull; Outpatient Clinical Services</div>
            <div class="small text-muted">Clinic Hours: 08:00 AM - 05:00 PM &bull; Emergency Hotline: (02) 8123-4567</div>
        </div>

        <!-- Document Reference & Queue Ticket -->
        <div class="row align-items-center mb-4 pb-2 border-bottom">
            <div class="col-8">
                <h5 class="fw-bold text-dark mb-0">CLINICAL ENCOUNTER & E-PRESCRIPTION SLIP</h5>
                <span class="small text-muted">Consultation Date: <strong><?= date('F j, Y - g:i A', strtotime($encounter['visit_date'])) ?></strong></span>
            </div>
            <div class="col-4 text-end">
                <div class="small text-muted">Queue Ticket:</div>
                <span class="fs-4 fw-bold text-primary"><?= sanitize($encounter['queue_number']) ?></span>
                <div class="small text-muted" style="font-size: 0.75rem;">Code: <strong><?= sanitize($encounter['transaction_code']) ?></strong></div>
            </div>
        </div>

        <!-- Patient Demographics -->
        <div class="card border-0 bg-light p-3 rounded-3 mb-4">
            <div class="row g-2 small">
                <div class="col-md-4">
                    <span class="text-muted">Patient Full Name:</span>
                    <strong class="d-block text-dark fs-6"><?= sanitize($encounter['full_name']) ?></strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted">Age / Sex:</span>
                    <strong class="d-block text-dark"><?= $age ?> yrs / <?= sanitize($encounter['gender']) ?></strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted">Mobile Number:</span>
                    <strong class="d-block text-dark"><?= sanitize($encounter['mobile_number']) ?></strong>
                </div>
                <div class="col-md-4">
                    <span class="text-muted">Catchment Address:</span>
                    <strong class="d-block text-dark">
                        <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                        <?= sanitize($encounter['sitio'] ?: 'Paikit') ?><?= !empty($encounter['street_address']) ? ' (' . sanitize($encounter['street_address']) . ')' : '' ?>, Canlubang
                    </strong>
                </div>
            </div>
        </div>

        <!-- Clinical Vital Signs -->
        <?php if (!empty($vitals)): ?>
            <div class="mb-4">
                <h6 class="fw-bold text-dark border-bottom pb-1 small text-uppercase">
                    <i class="bi bi-activity text-danger me-1"></i> Recorded Vital Signs
                </h6>
                <div class="row text-center g-2 mt-1">
                    <div class="col">
                        <div class="p-2 border rounded bg-white">
                            <span class="text-muted small d-block">Blood Pressure</span>
                            <strong><?= sanitize($vitals['bp'] ?? 'N/A') ?></strong> <small class="text-muted">mmHg</small>
                        </div>
                    </div>
                    <div class="col">
                        <div class="p-2 border rounded bg-white">
                            <span class="text-muted small d-block">Temperature</span>
                            <strong><?= sanitize($vitals['temp'] ?? 'N/A') ?></strong> <small class="text-muted">°C</small>
                        </div>
                    </div>
                    <div class="col">
                        <div class="p-2 border rounded bg-white">
                            <span class="text-muted small d-block">Weight</span>
                            <strong><?= sanitize($vitals['weight'] ?? 'N/A') ?></strong> <small class="text-muted">kg</small>
                        </div>
                    </div>
                    <div class="col">
                        <div class="p-2 border rounded bg-white">
                            <span class="text-muted small d-block">Height</span>
                            <strong><?= sanitize($vitals['height'] ?? 'N/A') ?></strong> <small class="text-muted">cm</small>
                        </div>
                    </div>
                    <div class="col">
                        <div class="p-2 border rounded bg-white">
                            <span class="text-muted small d-block">Pulse Rate</span>
                            <strong><?= sanitize($vitals['pulse'] ?? 'N/A') ?></strong> <small class="text-muted">bpm</small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Physician Diagnostic Assessment -->
        <div class="mb-4">
            <h6 class="fw-bold text-dark border-bottom pb-1 small text-uppercase">
                <i class="bi bi-clipboard2-pulse text-primary me-1"></i> Clinical Diagnosis & Assessment
            </h6>
            <?php if (!empty($encounter['complaint'])): ?>
                <div class="small mb-2">
                    <strong class="text-secondary">Chief Complaint:</strong> <?= sanitize($encounter['complaint']) ?>
                </div>
            <?php endif; ?>

            <div class="p-3 bg-light rounded-3 border-start border-4 border-primary">
                <?php if (!empty($encounter['diagnostic_notes'])): ?>
                    <div class="text-dark small" style="white-space: pre-line; line-height: 1.6;">
                        <?= sanitize($encounter['diagnostic_notes']) ?>
                    </div>
                <?php else: ?>
                    <span class="text-muted small italic">Consultation pending or diagnostic assessment in progress.</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Electronic Prescription Section (Rx) -->
        <div class="mb-4">
            <div class="d-flex align-items-center border-bottom pb-1 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="rx-symbol">℞</span>
                    <h5 class="fw-bold text-dark mb-0">PRESCRIPTION ORDER</h5>
                </div>
            </div>

            <?php if (!empty($prescriptions)): ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light small">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th style="width: 45%;">Generic / Brand Medication</th>
                                <th style="width: 35%;">Dosage, Frequency & Instructions</th>
                                <th style="width: 15%;" class="text-center">Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prescriptions as $idx => $m): ?>
                                <tr>
                                    <td class="text-muted small text-center"><?= $idx + 1 ?></td>
                                    <td>
                                        <strong class="text-primary"><?= sanitize($m['medication'] ?? '') ?></strong>
                                    </td>
                                    <td class="small">
                                        <div><?= sanitize($m['dosage'] ?? '') ?></div>
                                        <?php if (!empty($m['duration'])): ?>
                                            <span class="text-muted">Duration: <?= sanitize($m['duration']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-bold small">
                                        <?= sanitize($m['quantity'] ?? '1') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-3 text-center text-muted small bg-light rounded">
                    No medication prescription recorded for this encounter.
                </div>
            <?php endif; ?>
        </div>

        <!-- Attending Physician Certification & QR Verification -->
        <div class="row align-items-end mt-4 pt-3 border-top">
            <!-- Digital Verification QR -->
            <div class="col-md-5">
                <div class="d-flex align-items-center gap-3">
                    <div id="verifyQrCode"></div>
                    <div class="small text-muted" style="font-size: 0.72rem;">
                        <strong>Digital Verification:</strong><br>
                        Scan this QR code with any mobile device to verify the authenticity of this official consultation and prescription record.
                    </div>
                </div>
            </div>

            <!-- Doctor Signature Block -->
            <div class="col-md-7 text-end">
                <div class="signature-line"></div>
                <div class="fw-bold text-dark fs-6"><?= strtoupper(sanitize($attendingDoctor['full_name'])) ?></div>
                <div class="small text-muted">Attending Solo Physician &bull; Paikit Health Center</div>
                <div class="small text-muted" style="font-size: 0.75rem;">
                    PRC License No.: <strong>0098741</strong> &bull; PTR No.: <strong>8421098</strong> &bull; S2 No.: <strong>PH-S2-48201</strong>
                </div>
            </div>
        </div>

        <!-- Legal Disclaimer & Data Privacy Act -->
        <div class="mt-4 pt-2 border-top text-center text-muted small" style="font-size: 0.7rem;">
            This electronic medical record and prescription are issued in compliance with the Department of Health (DOH) Administrative Orders, Republic Act No. 10918 (Philippine Pharmacy Act), and Republic Act No. 10173 (Data Privacy Act of 2012).
        </div>
    </div>
</div>

<script>
    window.addEventListener('DOMContentLoaded', () => {
        const verifyText = "<?= $verifyUrl ?>";
        new QRCode(document.getElementById("verifyQrCode"), {
            text: verifyText,
            width: 85,
            height: 85,
            colorDark : "#0d6efd",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.M
        });
    });
</script>
<script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>
