<?php
/**
 * staff/dashboard.php - Staff Operations & Queue Management
 * Paikit Health Center Information System
 * STRICT RESTRICTION: Zero Access to Patient Diagnostic Notes (RA 10173 Compliance)
 */

require_once __DIR__ . '/../db.php';
$pdo = get_db();
require_once __DIR__ . '/../config/mail.php';
require_role(['Staff']);

$user = get_auth_user();
require_once __DIR__ . '/../includes/settings_handlers.php';
$mailConfig = get_mail_config();
$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd   = $today . ' 23:59:59';

$msg   = $_GET['msg'] ?? null;
$error = $_GET['error'] ?? null;
$activeTab = $_GET['tab'] ?? 'queue';

// 1. Fetch Today's Duty Schedule & Quota
$stmtSched = $pdo->prepare("SELECT * FROM doctor_schedules WHERE duty_date = ? ORDER BY schedule_id DESC LIMIT 1");
$stmtSched->execute([$today]);
$schedule = $stmtSched->fetch();

// 2. Queue Statistics & Analytics (Zero Diagnostic Notes Queried)
$stmtStats = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_today,
        SUM(CASE WHEN encounter_type = 'Walk-in' THEN 1 ELSE 0 END) AS walkin_count,
        SUM(CASE WHEN encounter_type = 'Appointment' THEN 1 ELSE 0 END) AS appointment_count,
        SUM(CASE WHEN queue_status = 'Waiting' AND check_in_status = 'Arrived' THEN 1 ELSE 0 END) AS waiting_count,
        SUM(CASE WHEN queue_status = 'Waiting' AND check_in_status = 'Booked' THEN 1 ELSE 0 END) AS booked_count,
        SUM(CASE WHEN queue_status = 'Serving' THEN 1 ELSE 0 END) AS serving_count,
        SUM(CASE WHEN queue_status = 'Completed' THEN 1 ELSE 0 END) AS completed_count
    FROM encounters
    WHERE visit_date BETWEEN ? AND ?
");
$stmtStats->execute([$todayStart, $todayEnd]);
$stats = $stmtStats->fetch() ?: ['total_today' => 0, 'walkin_count' => 0, 'appointment_count' => 0, 'waiting_count' => 0, 'booked_count' => 0, 'serving_count' => 0, 'completed_count' => 0];

$maxSlots = $schedule ? (int)$schedule['max_slots'] : 50;
$slotsTaken = (int)$stats['total_today'];
$slotsRemaining = max(0, $maxSlots - $slotsTaken);

// 3. Fetch Booked Appointments Awaiting Arrival/Check-In Today (Only Approved Requests)
$stmtBookedAppts = $pdo->prepare("
    SELECT 
        e.encounter_id, e.patient_id, e.queue_number, e.encounter_type, 
        e.visit_date, e.queue_status, e.check_in_status, e.complaint, e.is_priority, e.priority_reason,
        p.full_name, p.mobile_number, p.gender, p.birth_date, p.sitio, p.street_address,
        t.transaction_code
    FROM encounters e
    JOIN patients p ON e.patient_id = p.patient_id
    LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
    WHERE e.visit_date BETWEEN ? AND ? 
      AND e.encounter_type = 'Appointment'
      AND e.check_in_status = 'Booked'
      AND e.queue_status = 'Waiting'
      AND (e.approval_status = 'Approved' OR e.approval_status IS NULL)
    ORDER BY e.encounter_id ASC
");
$stmtBookedAppts->execute([$todayStart, $todayEnd]);
$bookedAppointments = $stmtBookedAppts->fetchAll();

// 3b. Fetch Pending Client Appointment Requests for Staff Review
$stmtPending = $pdo->prepare("
    SELECT 
        e.encounter_id, e.patient_id, e.queue_number, e.encounter_type, 
        e.visit_date, e.queue_status, e.check_in_status, e.complaint, e.approval_status,
        p.full_name, p.mobile_number, p.gender, p.birth_date, p.sitio, p.street_address,
        COALESCE(p.email, u.email) as patient_email
    FROM encounters e
    JOIN patients p ON e.patient_id = p.patient_id
    LEFT JOIN users u ON u.patient_id = p.patient_id
    WHERE e.encounter_type = 'Appointment'
      AND e.approval_status = 'Pending'
      AND e.queue_status = 'Waiting'
    ORDER BY e.visit_date ASC, e.encounter_id ASC
");
$stmtPending->execute();
$pendingAppointments = $stmtPending->fetchAll();
$pendingCount = count($pendingAppointments);

// Fetch Quota information for each date in pending requests
$pendingDateQuotas = [];
foreach ($pendingAppointments as $pa) {
    $vDate = date('Y-m-d', strtotime($pa['visit_date']));
    if (!isset($pendingDateQuotas[$vDate])) {
        $stmtQ = $pdo->prepare("SELECT max_slots, is_available FROM doctor_schedules WHERE duty_date = ?");
        $stmtQ->execute([$vDate]);
        $sRow = $stmtQ->fetch();
        $maxS = $sRow ? (int)$sRow['max_slots'] : 50;
        $docAvail = $sRow ? (int)$sRow['is_available'] : 1;

        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM encounters WHERE visit_date BETWEEN ? AND ? AND queue_status != 'Cancelled' AND (approval_status = 'Approved' OR approval_status IS NULL)");
        $stmtC->execute([$vDate . ' 00:00:00', $vDate . ' 23:59:59']);
        $taken = (int)$stmtC->fetchColumn();

        $pendingDateQuotas[$vDate] = [
            'max_slots' => $maxS,
            'taken' => $taken,
            'available' => $docAvail
        ];
    }
}

// 3c. Fetch Recently Reviewed Appointments (History of Staff Decisions)
$stmtReviewed = $pdo->prepare("
    SELECT 
        e.encounter_id, e.patient_id, e.queue_number, e.encounter_type, 
        e.visit_date, e.approval_status, e.decline_reason, e.reviewed_at, e.complaint,
        p.full_name, p.mobile_number, p.sitio,
        u.full_name as reviewer_name
    FROM encounters e
    JOIN patients p ON e.patient_id = p.patient_id
    LEFT JOIN users u ON e.reviewed_by = u.user_id
    WHERE e.encounter_type = 'Appointment'
      AND e.approval_status IN ('Approved', 'Declined')
      AND e.reviewed_at IS NOT NULL
    ORDER BY e.reviewed_at DESC
    LIMIT 20
");
$stmtReviewed->execute();
$recentReviewed = $stmtReviewed->fetchAll();

// 4. Fetch Interleaved Waiting Queue (2 Appointments : 1 Walk-in, Priority First)
$interleavedWaiting = get_interleaved_queue($pdo, $todayStart, $todayEnd);

// 4. Fetch Weekly Consultation Settings (Mon-Fri) & Current Week Roster
$stmtWeekly = $pdo->query("SELECT * FROM weekly_consultation_settings ORDER BY day_number ASC");
$weeklySettings = $stmtWeekly->fetchAll();

$mondayTime = strtotime('monday this week');
$weekDaysList = [];
for ($i = 0; $i < 5; $i++) {
    $t = strtotime("+{$i} days", $mondayTime);
    $dStr = date('Y-m-d', $t);
    $stmtD = $pdo->prepare("SELECT max_slots, is_available, notes FROM doctor_schedules WHERE duty_date = ?");
    $stmtD->execute([$dStr]);
    $docRow = $stmtD->fetch();
    $weekDaysList[] = [
        'day_number' => $i + 1,
        'date' => $dStr,
        'day_name' => date('l', $t),
        'formatted' => date('M j, Y', $t),
        'doc_quota' => $docRow ? (int)$docRow['max_slots'] : null,
        'doc_avail' => $docRow ? (int)$docRow['is_available'] : null,
        'doc_notes' => $docRow ? $docRow['notes'] : null
    ];
}

// 5. Fetch Today's Queue (Explicitly EXCLUDING diagnostic_notes under RA 10173 data minimization)
$stmtQueue = $pdo->prepare("
    SELECT 
        e.encounter_id, e.patient_id, e.queue_number, e.encounter_type, 
        e.visit_date, e.queue_status, e.complaint, e.vital_signs,
        p.full_name, p.mobile_number, p.gender, p.birth_date, p.sitio, p.street_address, p.account_type,
        t.transaction_code
    FROM encounters e
    JOIN patients p ON e.patient_id = p.patient_id
    LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
    WHERE e.visit_date BETWEEN ? AND ?
    ORDER BY 
        CASE 
            WHEN e.queue_status = 'Serving' THEN 1 
            WHEN e.queue_status = 'Waiting' THEN 2 
            WHEN e.queue_status = 'Completed' THEN 3 
            ELSE 4 
        END, e.encounter_id ASC
");
$stmtQueue->execute([$todayStart, $todayEnd]);
$queueList = $stmtQueue->fetchAll();

// 4. Fetch Prescriptions for Dispensing Window (Read-only medication details & dispense status)
$stmtRx = $pdo->prepare("
    SELECT 
        pr.prescription_id, pr.encounter_id, pr.medication_details, pr.dispense_status, pr.created_at,
        e.queue_number, p.full_name, p.mobile_number
    FROM prescriptions pr
    JOIN encounters e ON pr.encounter_id = e.encounter_id
    JOIN patients p ON e.patient_id = p.patient_id
    WHERE e.visit_date BETWEEN ? AND ?
    ORDER BY pr.dispense_status ASC, pr.prescription_id DESC
");
$stmtRx->execute([$todayStart, $todayEnd]);
$prescriptionsList = $stmtRx->fetchAll();

// 5. Fetch Doctor & Staff Accounts for Personnel Management
$stmtPersonnel = $pdo->query("SELECT user_id, username, email, full_name, role, created_at FROM users WHERE role IN ('Doctor', 'Staff') ORDER BY role ASC, user_id ASC");
$personnelList = $stmtPersonnel->fetchAll();

// 6. If ticket_id is requested for modal printing
$ticketEncounter = null;
if (isset($_GET['ticket_id'])) {
    $tId = (int)$_GET['ticket_id'];
    $stmtT = $pdo->prepare("
        SELECT e.encounter_id, e.queue_number, e.encounter_type, e.visit_date, e.complaint,
               p.full_name, p.mobile_number, p.birth_date, p.gender,
               t.transaction_code
        FROM encounters e
        JOIN patients p ON e.patient_id = p.patient_id
        LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
        WHERE e.encounter_id = ?
    ");
    $stmtT->execute([$tId]);
    $ticketEncounter = $stmtT->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Operations & Queue Management - Paikit Health Center</title>
    <link rel="icon" type="image/png" href="../img/logo.png">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/modern-theme.css">
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/qrcode.min.js"></script>
    <script src="../js/accessibility.js"></script>
    <style>
        .privacy-alert {
            background-color: #f0fdf4;
            border-left: 4px solid var(--primary);
            border-radius: 8px;
        }
        .ticket-receipt {
            font-family: 'Courier New', Courier, monospace;
            background: #fff;
            padding: 24px;
            border: 2px dashed #86efac;
            border-radius: 12px;
        }
    </style>
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark staff-header shadow-sm sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard">
            <img src="../img/logo.png" alt="Paikit Logo" class="brand-logo-circle">
            <div>
                <span class="d-block lh-1 text-white fw-bold fs-5">Paikit Health Center</span>
                <span class="small text-white-50" style="font-size: 0.72rem; letter-spacing: 0.5px;">Staff Triage & Clinic Operations</span>
            </div>
        </a>
        <div class="d-flex align-items-center gap-2">
            <!-- Senior Text Toggle Button -->
            <button type="button" class="senior-toggle-btn me-1" onclick="toggleSeniorMode()" title="Toggle larger text for seniors">
                <i class="bi bi-fonts fs-6"></i> <strong>A+</strong> Senior Text
            </button>
            <button type="button" class="btn btn-outline-light btn-sm d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#smtpModal" title="Configure PHPMailer & Outgoing SMTP Server">
                <i class="bi bi-envelope-gear text-warning"></i>
                <span>Email & SMTP</span>
                <?php if (empty($mailConfig['username']) || empty($mailConfig['password'])): ?>
                    <span class="badge bg-danger rounded-pill">Setup</span>
                <?php else: ?>
                    <span class="badge bg-success rounded-pill">Active</span>
                <?php endif; ?>
            </button>
            <a href="../queue_monitor" target="_blank" class="btn btn-outline-light btn-sm">
                <i class="bi bi-display me-1 text-warning"></i> Public Queue Monitor
            </a>
            <button type="button" class="btn btn-outline-light btn-sm ms-1 px-3 py-1 rounded-pill d-flex align-items-center gap-1 shadow-sm" onclick="openSettingsModal('profile')" data-bs-toggle="modal" data-bs-target="#settingsModal" title="Staff Account Settings & Security">
                <i class="bi bi-person-circle fs-6 text-info"></i>
                <span class="fw-bold text-white"><?= sanitize($user['full_name']) ?></span>
                <i class="bi bi-gear-fill ms-1 text-warning" style="font-size: 0.8rem;"></i>
            </button>
            <a href="../logout" class="btn btn-outline-light btn-sm ms-2">
                <i class="bi bi-box-arrow-right me-1"></i> Log Out
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid my-4">

    <!-- Flash Messages (Errors as alert, Messages as Welcome Modal) -->
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
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= sanitize($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Staff Operations Console Hero Banner -->
    <div class="dash-hero mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-bold">
                    <i class="bi bi-hospital-fill me-1"></i> Staff Triage & Operations Desk
                </span>
                <span class="text-secondary small fw-semibold"><i class="bi bi-calendar-event me-1 text-primary"></i> <?= date('l, F j, Y') ?></span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                Hello, <?= sanitize($user['full_name']) ?>! 📋
            </h3>
            <div class="text-secondary small">
                Manage walk-in registrations, appointment approvals, triage vital signs, and pharmacy dispensing under RA 10173 data privacy rules.
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#smtpModal">
                <i class="bi bi-envelope-gear text-warning me-1"></i> Email & SMTP
            </button>
            <button class="btn btn-primary btn-sm rounded-pill fw-bold px-3 shadow-sm" onclick="document.getElementById('register-tab').click();">
                <i class="bi bi-person-plus-fill me-1"></i> Register Walk-in
            </button>
        </div>
    </div>

    <!-- Mandatory RA 10173 Data Privacy Guard Banner -->
    <div class="alert privacy-alert shadow-sm py-2 px-3 mb-4 rounded-3 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
            <i class="bi bi-shield-lock-fill fs-4 text-warning me-3"></i>
            <div>
                <strong class="text-dark">Data Privacy Act of 2012 (RA 10173) Role Restriction:</strong>
                <span class="text-muted small ms-1">
                    Staff accounts are strictly restricted to demographic registration, triage vitals, slot management, and dispensing verification. 
                    <strong>Zero access</strong> to patient diagnostic notes and confidential clinical histories is permitted.
                </span>
            </div>
        </div>
        <span class="badge bg-danger">Clinical Notes Blocked</span>
    </div>

    <!-- Analytics & Capacity Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="stat-label">Daily Capacity Quota</div>
                        <div class="stat-value text-dark"><?= $slotsTaken ?> <span class="fs-5 text-muted fw-normal">/ <?= $maxSlots ?></span></div>
                    </div>
                    <div class="stat-icon-box stat-icon-green">
                        <i class="bi bi-ticket-perforated-fill"></i>
                    </div>
                </div>
                <div class="stat-sub text-success fw-bold">
                    <i class="bi bi-check-circle me-1"></i> <?= $slotsRemaining ?> slots remaining
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="stat-label">Waiting in Queue</div>
                        <div class="stat-value text-warning"><?= (int)$stats['waiting_count'] ?></div>
                    </div>
                    <div class="stat-icon-box stat-icon-amber">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
                <div class="stat-sub text-muted">
                    <span class="live-pulse-dot me-1"></span> Serving now: <strong><?= (int)$stats['serving_count'] ?></strong>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="stat-label">Today's Walk-ins</div>
                        <div class="stat-value text-primary"><?= (int)$stats['walkin_count'] ?></div>
                    </div>
                    <div class="stat-icon-box stat-icon-blue">
                        <i class="bi bi-person-walking"></i>
                    </div>
                </div>
                <div class="stat-sub text-muted">
                    <i class="bi bi-calendar3 me-1"></i> Booked Appts: <strong><?= (int)$stats['appointment_count'] ?></strong>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="stat-label">Completed Consultations</div>
                        <div class="stat-value text-success"><?= (int)$stats['completed_count'] ?></div>
                    </div>
                    <div class="stat-icon-box stat-icon-purple">
                        <i class="bi bi-check-all"></i>
                    </div>
                </div>
                <div class="stat-sub text-muted">
                    <i class="bi bi-capsule me-1 text-primary"></i> Prescriptions: <strong><?= count($prescriptionsList) ?></strong>
                </div>
            </div>
        </div>
    </div>

    <?php if ($pendingCount > 0): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-4 mb-4 p-3 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <span class="badge bg-warning text-dark p-2 rounded-circle me-3 fs-5">
                    <i class="bi bi-bell-fill"></i>
                </span>
                <div>
                    <strong class="text-dark fs-6">Pending Appointment Requests (<?= $pendingCount ?>)</strong>
                    <div class="small text-muted">There are <?= $pendingCount ?> client appointment request(s) awaiting Staff review and approval.</div>
                </div>
            </div>
            <button class="btn btn-warning btn-sm fw-bold px-3 py-2 rounded-pill text-dark" onclick="document.getElementById('approvals-tab').click();">
                <i class="bi bi-clipboard-check-fill me-1"></i> Review Requests
            </button>
        </div>
    <?php endif; ?>

    <!-- Main Workspace Tabs -->
    <ul class="nav nav-tabs nav-tabs-modern mb-4" id="staffTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'queue' ? 'active fw-bold' : '' ?>" id="queue-tab" data-bs-toggle="tab" data-bs-target="#queuePane">
                <i class="bi bi-list-task me-1"></i> Live Triage & Queue List (<?= count($interleavedWaiting) ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'approvals' ? 'active fw-bold text-success' : '' ?>" id="approvals-tab" data-bs-toggle="tab" data-bs-target="#approvalsPane">
                <i class="bi bi-clipboard-check-fill me-1"></i> Appointment Approvals
                <?php if ($pendingCount > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-1"><?= $pendingCount ?></span>
                <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary rounded-pill ms-1">0</span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'checkin' ? 'active fw-bold text-primary' : '' ?>" id="checkin-tab" data-bs-toggle="tab" data-bs-target="#checkinPane">
                <i class="bi bi-qr-code-scan me-1"></i> QR / 1-Click Fast Check-in (<?= count($bookedAppointments) ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'register' ? 'active fw-bold' : '' ?>" id="register-tab" data-bs-toggle="tab" data-bs-target="#registerPane">
                <i class="bi bi-person-plus-fill me-1"></i> Walk-in Registration
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'schedule' ? 'active fw-bold' : '' ?>" id="schedule-tab" data-bs-toggle="tab" data-bs-target="#schedulePane">
                <i class="bi bi-calendar-week me-1"></i> Weekly Consultation Schedule (Mon-Fri)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'pharmacy' ? 'active fw-bold' : '' ?>" id="pharmacy-tab" data-bs-toggle="tab" data-bs-target="#pharmacyPane">
                <i class="bi bi-capsule me-1"></i> Pharmacy Dispensing Desk (<?= count($prescriptionsList) ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'users' ? 'active fw-bold' : '' ?>" id="users-tab" data-bs-toggle="tab" data-bs-target="#usersPane">
                <i class="bi bi-person-gear me-1"></i> Personnel & Accounts (<?= count($personnelList) ?>)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="staffTabsContent">

        <!-- TAB 1: LIVE TRIAGE & FAIR INTERLEAVED QUEUE LIST -->
        <div class="tab-pane fade <?= $activeTab === 'queue' ? 'show active' : '' ?>" id="queuePane">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-clock-history text-success me-2"></i> Active Clinic Queue (<?= date('F j, Y') ?>)
                        </h5>
                        <small class="text-muted">
                            Fair Calling Ratio: <strong>2 Appointments : 1 Walk-in</strong> &bull; Priority (Senior / PWD / Pregnant / Emergency) always served first.
                        </small>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm rounded-pill fw-bold" onclick="document.getElementById('checkin-tab').click()">
                            <i class="bi bi-qr-code-scan me-1"></i> Fast Check-in (<?= count($bookedAppointments) ?>)
                        </button>
                        <button class="btn btn-success btn-sm rounded-pill fw-bold" onclick="document.getElementById('register-tab').click()">
                            <i class="bi bi-plus-lg me-1"></i> New Walk-in
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr class="small text-muted">
                                    <th>POS</th>
                                    <th>QUEUE #</th>
                                    <th>PATIENT NAME</th>
                                    <th>MOBILE</th>
                                    <th>TYPE & PRIORITY</th>
                                    <th>CHIEF COMPLAINT</th>
                                    <th>TIME IN</th>
                                    <th class="text-end">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($interleavedWaiting)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                            No patients waiting who are marked as "Arrived" at this time.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($interleavedWaiting as $q): ?>
                                        <?php 
                                            $isNext = ($q['queue_position'] === 1);
                                        ?>
                                        <tr class="<?= $isNext ? 'table-warning' : '' ?>">
                                            <td>
                                                <span class="badge <?= $isNext ? 'bg-warning text-dark' : 'bg-secondary' ?> fs-6">
                                                    #<?= $q['queue_position'] ?><?= $isNext ? ' NEXT' : '' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="fs-5 fw-bold text-dark font-monospace"><?= sanitize($q['queue_number']) ?></span>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= sanitize($q['full_name']) ?></div>
                                                <small class="text-muted"><?= sanitize($q['gender']) ?> &bull; <?= sanitize($q['birth_date']) ?></small><br>
                                                <small class="text-primary"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= sanitize($q['sitio'] ?: 'Paikit') ?></small>
                                            </td>
                                            <td><?= sanitize($q['mobile_number']) ?></td>
                                            <td>
                                                <?php if (!empty($q['is_priority'])): ?>
                                                    <span class="badge bg-danger mb-1 d-inline-block">
                                                        <i class="bi bi-lightning-fill"></i> PRIORITY
                                                    </span><br>
                                                    <small class="text-danger fw-bold">(<?= sanitize($q['priority_reason'] ?: 'Priority') ?>)</small>
                                                <?php else: ?>
                                                    <span class="badge <?= $q['encounter_type'] === 'Walk-in' ? 'bg-primary-subtle text-primary border' : 'bg-info-subtle text-info border' ?>">
                                                        <?= sanitize($q['encounter_type']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-secondary">
                                                <?= !empty($q['complaint']) ? sanitize($q['complaint']) : '<span class="text-muted fst-italic">General Consultation</span>' ?>
                                            </td>
                                            <td class="small text-muted">
                                                <?= !empty($q['check_in_time']) ? date('g:i A', strtotime($q['check_in_time'])) : date('g:i A', strtotime($q['visit_date'])) ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-inline-flex align-items-center gap-1">
                                                    <!-- Toggle Priority Switch Form -->
                                                    <form action="walkin_process" method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                        <input type="hidden" name="action" value="toggle_priority">
                                                        <input type="hidden" name="encounter_id" value="<?= (int)$q['encounter_id'] ?>">
                                                        <button type="submit" class="btn btn-sm <?= !empty($q['is_priority']) ? 'btn-danger' : 'btn-outline-danger' ?> py-1 px-2" title="Toggle Priority or Regular status">
                                                            <i class="bi bi-lightning-charge-fill"></i> <?= !empty($q['is_priority']) ? 'Priority' : 'Prioritize' ?>
                                                        </button>
                                                    </form>
                                                    <a href="dashboard?ticket_id=<?= $q['encounter_id'] ?>" class="btn btn-outline-secondary btn-sm py-1 px-2" title="Print Queue Slip">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: APPOINTMENT APPROVALS (STAFF REVIEW GATEKEEPER) -->
        <div class="tab-pane fade <?= $activeTab === 'approvals' ? 'show active' : '' ?>" id="approvalsPane">
            <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
                <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">
                            <i class="bi bi-clipboard-check-fill text-success me-2"></i> Client Appointment Approvals
                        </h5>
                        <p class="text-muted small mb-0">
                            Review and verify incoming client appointment requests. Approved appointments receive their QR Check-in Pass and are placed into the Doctor's queue upon arrival.
                        </p>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill">
                        <i class="bi bi-shield-check me-1"></i> Staff Review Gatekeeper
                    </span>
                </div>
                <div class="card-body p-4">
                    <?php if (empty($pendingAppointments)): ?>
                        <div class="text-center py-5">
                            <div class="text-muted mb-2">
                                <i class="bi bi-check2-circle text-success fs-1"></i>
                            </div>
                            <h6 class="fw-bold text-dark mb-1">No Pending Appointment Requests</h6>
                            <p class="text-muted small mb-0">All submitted client appointment bookings have been reviewed and processed.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-uppercase small text-muted">
                                    <tr>
                                        <th style="width: 130px;">Queue Ticket</th>
                                        <th>Patient Demographic Info</th>
                                        <th>Requested Consultation Date</th>
                                        <th>Service / Complaint</th>
                                        <th style="width: 170px;">Date Capacity</th>
                                        <th style="width: 190px;" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingAppointments as $pa): 
                                        $vDate = date('Y-m-d', strtotime($pa['visit_date']));
                                        $qInfo = $pendingDateQuotas[$vDate] ?? ['max_slots' => 50, 'taken' => 0, 'available' => 1];
                                        $quotaPercent = min(100, round(($qInfo['taken'] / max(1, $qInfo['max_slots'])) * 100));
                                        $isNearFull = ($qInfo['taken'] >= $qInfo['max_slots']);
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="fs-5 fw-bold text-primary font-monospace"><?= sanitize($pa['queue_number']) ?></div>
                                                <span class="badge bg-warning text-dark small" style="font-size: 0.7rem;">Pending Review</span>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= sanitize($pa['full_name']) ?></div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-telephone text-secondary me-1"></i> <?= sanitize($pa['mobile_number'] ?: 'N/A') ?>
                                                    <span class="mx-1">&bull;</span>
                                                    <i class="bi bi-geo-alt text-danger me-1"></i> Sitio <?= sanitize($pa['sitio'] ?: 'Paikit') ?>
                                                </div>
                                                <?php if (!empty($pa['birth_date'])): ?>
                                                    <div class="small text-secondary" style="font-size: 0.75rem;">
                                                        Age: <?= date_diff(date_create($pa['birth_date']), date_create('today'))->y ?> yrs &bull; <?= sanitize($pa['gender'] ?: 'Other') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark">
                                                    <i class="bi bi-calendar3 text-success me-1"></i> <?= date('l, M j, Y', strtotime($pa['visit_date'])) ?>
                                                </div>
                                                <div class="small text-muted">Time: Morning Clinic (8:00 AM - 12:00 PM)</div>
                                                <?php if (!$qInfo['available']): ?>
                                                    <span class="badge bg-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Doctor marked off-duty</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border px-2 py-1">
                                                    <i class="bi bi-heart-pulse text-danger me-1"></i> <?= sanitize($pa['complaint'] ?: 'General Consultation') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex justify-content-between small mb-1">
                                                    <span class="text-muted">Booked:</span>
                                                    <strong class="<?= $isNearFull ? 'text-danger' : 'text-dark' ?>"><?= $qInfo['taken'] ?> / <?= $qInfo['max_slots'] ?></strong>
                                                </div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar <?= $isNearFull ? 'bg-danger' : 'bg-success' ?>" style="width: <?= $quotaPercent ?>%;"></div>
                                                </div>
                                                <small class="text-muted" style="font-size: 0.7rem;"><?= max(0, $qInfo['max_slots'] - $qInfo['taken']) ?> slots available</small>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-inline-flex gap-2">
                                                    <form action="walkin_process" method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                        <input type="hidden" name="action" value="approve_appointment">
                                                        <input type="hidden" name="encounter_id" value="<?= (int)$pa['encounter_id'] ?>">
                                                        <button type="submit" class="btn btn-success btn-sm fw-bold px-3 shadow-sm" title="Approve this appointment request">
                                                            <i class="bi bi-check-lg me-1"></i> Approve
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-outline-danger btn-sm fw-bold px-2" 
                                                            onclick="openDeclineModal(<?= (int)$pa['encounter_id'] ?>, '<?= addslashes(sanitize($pa['full_name'])) ?>', '<?= addslashes(sanitize($pa['queue_number'])) ?>', '<?= addslashes(date('M j, Y', strtotime($pa['visit_date']))) ?>')">
                                                        <i class="bi bi-x-lg me-1"></i> Decline
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recently Reviewed Appointments Table -->
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-header bg-white border-bottom p-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold text-dark mb-0">
                        <i class="bi bi-clock-history text-secondary me-2"></i> Recently Reviewed Appointments History
                    </h6>
                    <small class="text-muted">Last 20 Decisions</small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentReviewed)): ?>
                        <div class="text-center py-4 text-muted small">No reviewed appointment records yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.88rem;">
                                <thead class="table-light small text-muted">
                                    <tr>
                                        <th>Ticket</th>
                                        <th>Patient Name</th>
                                        <th>Requested Date</th>
                                        <th>Service</th>
                                        <th>Status</th>
                                        <th>Reason / Remarks</th>
                                        <th>Reviewed By</th>
                                        <th>Decision Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentReviewed as $rv): ?>
                                        <tr>
                                            <td class="fw-bold font-monospace text-primary"><?= sanitize($rv['queue_number']) ?></td>
                                            <td class="fw-bold text-dark"><?= sanitize($rv['full_name']) ?></td>
                                            <td><?= date('M j, Y', strtotime($rv['visit_date'])) ?></td>
                                            <td><?= sanitize($rv['complaint'] ?: 'General Consultation') ?></td>
                                            <td>
                                                <?php if ($rv['approval_status'] === 'Approved'): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill">
                                                        <i class="bi bi-check-circle me-1"></i> Approved
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill">
                                                        <i class="bi bi-x-circle me-1"></i> Declined
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted">
                                                <?= sanitize($rv['decline_reason'] ?: 'Approved for consultation') ?>
                                            </td>
                                            <td class="small text-secondary">
                                                <?= sanitize($rv['reviewer_name'] ?: 'Staff Desk') ?>
                                            </td>
                                            <td class="small text-muted">
                                                <?= $rv['reviewed_at'] ? date('M j, g:i A', strtotime($rv['reviewed_at'])) : 'N/A' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB 1B: FAST-SCAN CHECK-IN FOR APPOINTMENTS -->
        <div class="tab-pane fade <?= $activeTab === 'checkin' ? 'show active' : '' ?>" id="checkinPane">
            <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white p-4">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <h5 class="fw-bold text-dark mb-1">
                            <i class="bi bi-qr-code-scan text-primary me-2"></i> Fast-Scan Check-In for Appointments
                        </h5>
                        <p class="text-muted small mb-0">
                            Scan the patient's mobile QR code or enter Ticket # (e.g. <code>A-001</code>) or name to confirm they have <strong>Arrived</strong> at the Health Center before being called by the Doctor.
                        </p>
                    </div>
                    <div class="col-md-5">
                        <form action="walkin_process" method="POST" class="d-flex flex-column gap-2">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="checkin_appointment">
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white"><i class="bi bi-upc-scan text-primary fs-5"></i></span>
                                <input type="text" name="search_term" class="form-control form-control-lg font-monospace" placeholder="Scan QR or enter Ticket # / Name..." required autofocus autocomplete="off">
                                <button class="btn btn-primary fw-bold px-3" type="submit">
                                    <i class="bi bi-geo-alt-fill me-1"></i> Check In
                                </button>
                            </div>
                            <div class="form-check form-switch ms-1">
                                <input class="form-check-input" type="checkbox" name="is_priority" value="1" id="fastScanPriorityCheck">
                                <label class="form-check-label small fw-bold text-danger" for="fastScanPriorityCheck">
                                    <i class="bi bi-lightning-fill"></i> Check in as Priority (Senior Citizen / PWD / Pregnant / Emergency)
                                </label>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Scheduled Appointments Awaiting Arrival Table -->
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-calendar-check text-primary me-2"></i> Reserved Appointments Today (Awaiting Arrival: <?= count($bookedAppointments) ?>)
                        </h6>
                        <small class="text-muted">Only patients marked as <em>"Arrived"</em> will appear in the Doctor's consultation queue.</small>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr class="small text-muted">
                                    <th>QUEUE #</th>
                                    <th>PATIENT NAME</th>
                                    <th>MOBILE</th>
                                    <th>ADDRESS</th>
                                    <th>CHIEF COMPLAINT</th>
                                    <th>STATUS</th>
                                    <th class="text-end">CHECK-IN ACTION</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bookedAppointments)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">
                                            <i class="bi bi-check-circle-fill fs-3 text-success d-block mb-1"></i>
                                            No pending appointments awaiting check-in at this time. All scheduled patients have arrived or none are booked.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($bookedAppointments as $b): ?>
                                        <tr>
                                            <td>
                                                <span class="fs-5 fw-bold text-primary font-monospace"><?= sanitize($b['queue_number']) ?></span>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= sanitize($b['full_name']) ?></div>
                                                <small class="text-muted"><?= sanitize($b['gender']) ?> &bull; <?= sanitize($b['birth_date']) ?></small>
                                            </td>
                                            <td><?= sanitize($b['mobile_number']) ?></td>
                                            <td>
                                                <span class="small text-muted"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= sanitize($b['sitio'] ?: 'Paikit') ?></span>
                                            </td>
                                            <td>
                                                <span class="small text-secondary"><?= sanitize(mb_strimwidth($b['complaint'] ?: 'General Consultation', 0, 35, '...')) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-clock-history me-1"></i> Awaiting Check-In</span>
                                            </td>
                                            <td class="text-end">
                                                <form action="walkin_process" method="POST" class="d-inline-flex align-items-center gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="checkin_appointment">
                                                    <input type="hidden" name="encounter_id" value="<?= (int)$b['encounter_id'] ?>">
                                                    <div class="form-check form-check-inline m-0">
                                                        <input class="form-check-input" type="checkbox" name="is_priority" value="1" id="priRow<?= $b['encounter_id'] ?>" title="Check if Senior, PWD, Pregnant, or Emergency">
                                                        <label class="form-check-label small text-danger fw-semibold" for="priRow<?= $b['encounter_id'] ?>">Priority</label>
                                                    </div>
                                                    <button type="submit" class="btn btn-success btn-sm fw-bold shadow-sm">
                                                        <i class="bi bi-geo-alt-fill me-1"></i> Mark as Arrived
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: DETERMINISTIC WALKIN REGISTRATION -->
        <div class="tab-pane fade <?= $activeTab === 'register' ? 'show active' : '' ?>" id="registerPane">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                        <div class="card-header bg-white py-3 px-4 border-bottom">
                            <h5 class="fw-bold mb-0 text-success">
                                <i class="bi bi-person-plus-fill me-2"></i> Deterministic Walk-in Registration & Triage
                            </h5>
                            <div class="text-muted small mt-1">
                                System uses mobile number as the primary deterministic anchor. Duplicate profiles are automatically avoided.
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <form action="walkin_process" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="register_walkin">

                                <!-- Deterministic Anchor: Mobile Number -->
                                <div class="mb-3 p-3 bg-light rounded-3 border border-success">
                                    <label class="form-label fw-bold text-success mb-1">
                                        <i class="bi bi-telephone-fill me-1"></i> Patient Mobile Number (Unique Deterministic Key)
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-phone"></i></span>
                                        <input type="tel" name="mobile_number" class="form-control" placeholder="09171234567" required maxlength="11" minlength="11" pattern="09[0-9]{9}" title="Must be exactly 11 digits starting with 09" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)">
                                    </div>
                                    <div class="form-text small text-muted">
                                        If mobile number exists in database, the new encounter is automatically linked to the existing patient file.
                                    </div>
                                </div>

                                <!-- Demographic Profile -->
                                <div class="row g-3 mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">First Name</label>
                                        <input type="text" name="first_name" class="form-control" placeholder="e.g. Maria Clara" required minlength="2" maxlength="50" oninput="handleNameInput(this)">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">Middle Name <span class="text-muted fw-normal">(Opt)</span></label>
                                        <input type="text" name="middle_name" class="form-control" placeholder="e.g. Santos" maxlength="50" oninput="handleNameInput(this)">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Last Name</label>
                                        <input type="text" name="last_name" class="form-control" placeholder="e.g. De Los Reyes" required minlength="2" maxlength="50" oninput="handleNameInput(this)">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-bold">Suffix <span class="text-muted fw-normal">(Opt)</span></label>
                                        <select name="suffix" class="form-select">
                                            <option value="" selected>None</option>
                                            <option value="Jr.">Jr.</option>
                                            <option value="Sr.">Sr.</option>
                                            <option value="II">II</option>
                                            <option value="III">III</option>
                                            <option value="IV">IV</option>
                                            <option value="V">V</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Birth Date</label>
                                        <input type="date" name="birth_date" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Gender</label>
                                        <select name="gender" class="form-select" required>
                                            <option value="Female">Female</option>
                                            <option value="Male">Male</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Patient Address (Canlubang Area Only) -->
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">
                                            <i class="bi bi-geo-alt-fill text-danger me-1"></i> Canlubang Area
                                        </label>
                                        <select name="sitio" class="form-select" required>
                                            <option value="Asia 1">Asia 1</option>
                                            <option value="Asia 2">Asia 2</option>
                                            <option value="Carmel Housing">Carmel Housing</option>
                                            <option value="Ceris 1 & 2">Ceris 1 & 2</option>
                                            <option value="GK">GK (Gawad Kalinga)</option>
                                            <option value="Manfil">Manfil</option>
                                            <option value="Mcdc">Mcdc</option>
                                            <option value="Paikit" selected>Paikit (Catchment)</option>
                                            <option value="Palao">Palao</option>
                                        </select>
                                        <div class="form-text small">Restricted to Barangay Canlubang areas under Health Center catchment.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">House / Street Address (Optional)</label>
                                        <input type="text" name="street_address" class="form-control" placeholder="e.g. Blk 3 Lot 8, Purok 2">
                                    </div>
                                </div>

                                <!-- Chief Complaint -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Chief Complaint / Primary Health Service</label>
                                    <textarea name="complaint" id="walkinComplaint" class="form-control" rows="2" placeholder="e.g. General Consultation, Immunization, Fever, cough..."></textarea>
                                    <div class="mt-2 d-flex flex-wrap gap-1 align-items-center">
                                        <span class="small text-muted me-1" style="font-size: 0.75rem;">Health Services:</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('walkinComplaint').value = 'General Consultation'">General Consultation</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('walkinComplaint').value = 'Immunization'">Immunization</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('walkinComplaint').value = 'Pediatric Care'">Pediatric Care</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('walkinComplaint').value = 'Prenatal Check up'">Prenatal Check up</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('walkinComplaint').value = 'Well-Baby Check up'">Well-Baby Check up</button>
                                    </div>
                                </div>

                                <!-- Priority Walk-in Switch (Senior Citizen / PWD / Pregnant / Emergency) -->
                                <div class="row g-2 mb-3 align-items-center p-3 rounded-3" style="background-color: #fff1f2; border: 1px solid #fecdd3;">
                                    <div class="col-md-6 col-12">
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" name="is_priority" value="1" id="walkinPrioritySwitch" onchange="document.getElementById('priorityReasonWrap').style.display = this.checked ? 'block' : 'none';">
                                            <label class="form-check-label fw-bold text-danger" for="walkinPrioritySwitch">
                                                <i class="bi bi-lightning-fill me-1"></i> Priority Walk-in (Senior / PWD / Pregnant / Emergency)
                                            </label>
                                        </div>
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">Automatically prioritized in queue in accordance with clinic policy.</small>
                                    </div>
                                    <div class="col-md-6 col-12" id="priorityReasonWrap" style="display: none;">
                                        <label class="form-label fw-bold small text-dark mb-1">Priority Classification</label>
                                        <select name="priority_reason" class="form-select form-select-sm">
                                            <option value="Senior Citizen (60+ yo)">Senior Citizen (60+ years old)</option>
                                            <option value="Person with Disability (PWD)">Person with Disability (PWD)</option>
                                            <option value="Pregnant Patient">Pregnant Mother</option>
                                            <option value="Acute / Emergency Distress">Acute Distress / Emergency</option>
                                            <option value="Infant / Pedia (<1 yo)">Infant / Pediatric (&lt;1 year old)</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Clinical Separation Notice (RA 10173) -->
                                <div class="p-3 bg-light rounded-3 border mb-4 d-flex align-items-center">
                                    <i class="bi bi-shield-lock-fill text-primary fs-4 me-3"></i>
                                    <div class="small text-muted">
                                        <strong class="text-dark d-block mb-1">Clinical Vitals & Triage Policy:</strong>
                                        In accordance with health center standard operating procedures, <strong>Triage Vital Signs</strong> (BP, Temperature, Weight, Height, Pulse, and Respiratory Rate) and Clinical Diagnoses are exclusively evaluated and recorded by the <strong>Attending Physician</strong> in the Solo Doctor Console.
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end gap-2">
                                    <button type="reset" class="btn btn-light border">Reset</button>
                                    <button type="submit" class="btn btn-success fw-bold px-4">
                                        <i class="bi bi-ticket-perforated me-1"></i> Register Walk-in & Generate Ticket
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: WEEKLY CONSULTATION SCHEDULE (MONDAY TO FRIDAY ONLY) -->
        <div class="tab-pane fade <?= $activeTab === 'schedule' ? 'show active' : '' ?>" id="schedulePane">
            <div class="card border-0 shadow-sm rounded-4 bg-white p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">
                            <i class="bi bi-calendar-week-fill text-primary me-2"></i> Weekly Consultation Schedule (Staff Configuration)
                        </h5>
                        <div class="text-muted small">
                            Configure which days from <strong>Monday to Friday</strong> are open for patient consultations.
                        </div>
                    </div>
                    <span class="badge bg-danger-subtle text-danger border border-danger px-3 py-2 fw-semibold">
                        <i class="bi bi-ban me-1"></i> Saturday & Sunday: Not Included (Closed)
                    </span>
                </div>

                <!-- Info Alert -->
                <div class="alert alert-info border-0 shadow-sm d-flex align-items-center gap-2 mb-4 py-2 px-3 rounded-3">
                    <i class="bi bi-info-circle-fill text-primary fs-5 flex-shrink-0"></i>
                    <div class="small">
                        <strong>Schedule Policy:</strong> Only days configured as <em>Available</em> will be eligible for appointment booking. 
                        Note that the <strong>daily patient quota</strong> is determined and configured exclusively by the <strong>Attending Physician (Doctor)</strong>.
                    </div>
                </div>

                <form action="walkin_process" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="update_weekly_schedule">

                    <div class="table-responsive mb-4">
                        <table class="table table-hover align-middle border rounded-3 overflow-hidden">
                            <thead class="table-light">
                                <tr class="small text-muted text-uppercase">
                                    <th style="width: 22%;">Day of Week</th>
                                    <th style="width: 25%;">Availability Status</th>
                                    <th style="width: 23%;">Consultation Hours</th>
                                    <th style="width: 30%;">Remarks / Focus</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($weeklySettings as $wk): ?>
                                    <?php $dNum = (int)$wk['day_number']; ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark fs-6"><?= sanitize($wk['day_name']) ?></div>
                                            <small class="text-muted">Weekday #<?= $dNum ?></small>
                                        </td>
                                        <td>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" name="available_days[]" value="<?= $dNum ?>" id="daySwitch<?= $dNum ?>" <?= (int)$wk['is_available'] ? 'checked' : '' ?> style="cursor: pointer; width: 2.4em; height: 1.25em;">
                                                <label class="form-check-label fw-bold ms-2 small <?= (int)$wk['is_available'] ? 'text-success' : 'text-secondary' ?>" for="daySwitch<?= $dNum ?>">
                                                    <?= (int)$wk['is_available'] ? '<i class="bi bi-check-circle-fill me-1 text-success"></i> Available (Open)' : '<i class="bi bi-dash-circle me-1 text-secondary"></i> Unavailable (Closed)' ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <input type="time" name="start_time[<?= $dNum ?>]" class="form-control form-control-sm" value="<?= date('H:i', strtotime($wk['start_time'])) ?>">
                                                <span class="text-muted small">-</span>
                                                <input type="time" name="end_time[<?= $dNum ?>]" class="form-control form-control-sm" value="<?= date('H:i', strtotime($wk['end_time'])) ?>">
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" name="remarks[<?= $dNum ?>]" class="form-control form-control-sm" value="<?= sanitize($wk['remarks']) ?>" placeholder="e.g. Outpatient Consultation, Maternal Care, etc.">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Strictly Excluded Weekends (Saturday & Sunday) -->
                                <tr class="table-light text-muted">
                                    <td>
                                        <div class="fw-bold text-danger">Saturday</div>
                                        <small class="text-muted">Weekend</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger-subtle text-danger border border-danger"><i class="bi bi-x-circle me-1"></i> Closed / Excluded</span>
                                    </td>
                                    <td>
                                        <span class="small text-muted font-monospace">--:-- - --:--</span>
                                    </td>
                                    <td>
                                        <span class="small text-muted fst-italic">Health center is closed on Saturdays (No consultations)</span>
                                    </td>
                                </tr>
                                <tr class="table-light text-muted">
                                    <td>
                                        <div class="fw-bold text-danger">Sunday</div>
                                        <small class="text-muted">Weekend</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger-subtle text-danger border border-danger"><i class="bi bi-x-circle me-1"></i> Closed / Excluded</span>
                                    </td>
                                    <td>
                                        <span class="small text-muted font-monospace">--:-- - --:--</span>
                                    </td>
                                    <td>
                                        <span class="small text-muted fst-italic">Health center is closed on Sundays (No consultations)</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-3">
                        <div class="small text-muted">
                            <i class="bi bi-shield-check text-success me-1"></i>
                            Consultations and online appointments are available Monday to Friday only.
                        </div>
                        <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm">
                            <i class="bi bi-save me-1"></i> Save Weekly Schedule
                        </button>
                    </div>
                </form>

                <!-- Current Week Overview (Monday to Friday with Doctor's Quota Display) -->
                <div class="mt-4 pt-3 border-top">
                    <h6 class="fw-bold text-dark mb-3">
                        <i class="bi bi-calendar-event me-2 text-info"></i> Current Week (Monday – Friday Overview)
                    </h6>
                    <div class="row g-2">
                        <?php foreach ($weekDaysList as $wDay): ?>
                            <?php 
                                $isToday = ($wDay['date'] === date('Y-m-d'));
                                $matchedWk = array_values(array_filter($weeklySettings, fn($x) => (int)$x['day_number'] === (int)$wDay['day_number']))[0] ?? null;
                                $isDayAvail = $matchedWk ? (int)$matchedWk['is_available'] : 1;
                            ?>
                            <div class="col-md col-12">
                                <div class="p-3 rounded-3 text-center border <?= $isToday ? 'border-primary bg-primary-subtle' : 'bg-light' ?>">
                                    <div class="text-uppercase small fw-bold <?= $isToday ? 'text-primary' : 'text-muted' ?>">
                                        <?= $wDay['day_name'] ?> <?= $isToday ? '<span class="badge bg-primary ms-1">TODAY</span>' : '' ?>
                                    </div>
                                    <div class="fw-bold fs-6 text-dark my-1"><?= $wDay['formatted'] ?></div>
                                    <div>
                                        <?php if ($wDay['doc_avail'] !== null && (int)$wDay['doc_avail'] === 0): ?>
                                            <span class="badge bg-danger text-white small mb-1" title="<?= sanitize($wDay['doc_notes'] ?: 'Doctor Unavailable') ?>">
                                                <i class="bi bi-x-circle me-1"></i>Doctor Off
                                            </span>
                                            <div class="text-danger small fw-semibold" style="font-size: 0.72rem;"><?= sanitize($wDay['doc_notes'] ?: 'Unavailable') ?></div>
                                        <?php elseif ($isDayAvail): ?>
                                            <span class="badge bg-success-subtle text-success border border-success small mb-1">Available</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary text-white small mb-1">Unavailable</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-2 text-muted" style="font-size: 0.72rem;">
                                        Doctor's Daily Quota: <strong><?= $wDay['doc_quota'] !== null ? $wDay['doc_quota'] . ' slots' : 'Set by Doctor' ?></strong>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 4: PHARMACY DISPENSING WINDOW -->
        <div class="tab-pane fade <?= $activeTab === 'pharmacy' ? 'show active' : '' ?>" id="pharmacyPane">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0 text-primary">
                            <i class="bi bi-capsule-pill text-primary me-2"></i> Pharmacy Dispensing Verification Desk
                        </h5>
                        <div class="text-muted small">Verify prescription fulfillment status. Clinical diagnoses remain masked for patient privacy.</div>
                    </div>
                    <span class="badge bg-secondary"><?= count($prescriptionsList) ?> Prescriptions Recorded</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-muted">
                                <tr>
                                    <th>QUEUE #</th>
                                    <th>PATIENT</th>
                                    <th>MOBILE</th>
                                    <th style="width: 45%;">PRESCRIBED MEDICINES</th>
                                    <th>DISPENSE STATUS</th>
                                    <th class="text-end">ACTION</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($prescriptionsList)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="bi bi-capsule fs-2 d-block mb-2"></i>
                                            No active prescriptions issued by physician yet today.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($prescriptionsList as $rx): ?>
                                        <?php 
                                            $meds = json_decode($rx['medication_details'], true) ?: [];
                                            $isDispensed = ($rx['dispense_status'] === 'Dispensed');
                                        ?>
                                        <tr>
                                            <td><span class="fs-6 fw-bold"><?= sanitize($rx['queue_number']) ?></span></td>
                                            <td class="fw-bold text-dark"><?= sanitize($rx['full_name']) ?></td>
                                            <td><?= sanitize($rx['mobile_number']) ?></td>
                                            <td>
                                                <ul class="list-unstyled mb-0 small">
                                                    <?php foreach ($meds as $m): ?>
                                                        <li class="mb-1">
                                                            <i class="bi bi-check2 text-success me-1"></i>
                                                            <strong><?= sanitize($m['medication'] ?? '') ?></strong> &bull;
                                                            <span>Qty: <?= sanitize($m['quantity'] ?? '1') ?></span>
                                                            <div class="text-muted" style="font-size: 0.75rem;"><?= sanitize($m['dosage'] ?? '') ?> (<?= sanitize($m['duration'] ?? '') ?>)</div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </td>
                                            <td>
                                                <span class="badge <?= $isDispensed ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                    <?= sanitize($rx['dispense_status']) ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <form action="walkin_process" method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="update_dispense_status">
                                                    <input type="hidden" name="prescription_id" value="<?= $rx['prescription_id'] ?>">
                                                    <input type="hidden" name="status" value="<?= $isDispensed ? 'Pending' : 'Dispensed' ?>">
                                                    <button type="submit" class="btn btn-sm <?= $isDispensed ? 'btn-outline-secondary' : 'btn-success fw-bold' ?>">
                                                        <i class="bi <?= $isDispensed ? 'bi-arrow-counterclockwise' : 'bi-check-circle' ?> me-1"></i>
                                                        <?= $isDispensed ? 'Mark Pending' : 'Mark Dispensed' ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 4: PERSONNEL & MEDICAL STAFF MANAGEMENT -->
        <div class="tab-pane fade <?= $activeTab === 'users' ? 'show active' : '' ?>" id="usersPane">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-people-fill text-primary me-2"></i> Authorized Medical & Health Personnel
                        </h5>
                        <div class="text-muted small">Only Doctor and Staff accounts can be created here by authorized personnel.</div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#createPersonnelModal">
                        <i class="bi bi-person-plus-fill me-1"></i> Create Doctor / Staff Account
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-muted">
                                <tr>
                                    <th>USER ID</th>
                                    <th>FULL NAME</th>
                                    <th>EMAIL (SIGN-IN)</th>
                                    <th>ASSIGNED ROLE</th>
                                    <th>PROVISION DATE</th>
                                    <th class="text-end">SECURITY CLEARANCE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($personnelList as $u): ?>
                                    <?php $isDoc = ($u['role'] === 'Doctor'); ?>
                                    <tr>
                                        <td>#<?= (int)$u['user_id'] ?></td>
                                        <td class="fw-bold text-dark">
                                            <i class="bi <?= $isDoc ? 'bi-heart-pulse-fill text-primary' : 'bi-hospital text-success' ?> me-1"></i>
                                            <?= sanitize($u['full_name'] ?: $u['username']) ?>
                                        </td>
                                        <td><code><?= sanitize($u['email'] ?: ($u['username'] . '@paikit.health')) ?></code></td>
                                        <td>
                                            <span class="badge <?= $isDoc ? 'bg-primary' : 'bg-success' ?> px-2 py-1">
                                                <?= sanitize($u['role']) ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted"><?= date('M j, Y - g:i A', strtotime($u['created_at'])) ?></td>
                                        <td class="text-end">
                                            <span class="badge bg-light text-dark border small">
                                                <?= $isDoc ? 'Full Clinical Clearance' : 'Administrative / Triage' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light py-2 px-3 small text-muted">
                    <i class="bi bi-shield-check text-success me-1"></i>
                    <strong>RA 10173 Security Notice:</strong> Public self-registration is strictly restricted to Patients (Clients). Medical practitioners and health center staff can only be created by an authenticated administrator.
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Create Doctor or Staff Account -->
<div class="modal fade" id="createPersonnelModal" tabindex="-1" aria-labelledby="createPersonnelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="createPersonnelModalLabel">
                    <i class="bi bi-person-plus-fill me-2"></i> Provision Personnel Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="walkin_process" method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="create_user">

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">Role to Assign</label>
                        <select name="role" class="form-select" required>
                            <option value="Doctor">🩺 Solo Doctor (Full Clinical Access)</option>
                            <option value="Staff" selected>📋 Admin / Staff (Triage & Queue Desk)</option>
                        </select>
                        <div class="form-text small">Select clearance level under RA 10173 regulations.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">Full Name & Title</label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Dr. Jose Mercado, MD or Nurse Elena Ramos, RN" required minlength="2" maxlength="100" oninput="handleNameInput(this)">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">Official Email Address (Used for Sign In)</label>
                        <input type="email" name="email" class="form-control" placeholder="dr.mercado@paikit.health" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">System Username</label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. dr_mercado or nurse_elena" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">Initial Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="staffCreatePwd" class="form-control" placeholder="Assign secure password" required minlength="6">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('staffCreatePwd', this)" title="Show / Hide Password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-bold px-4">
                        <i class="bi bi-check-circle me-1"></i> Provision Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>



<!-- Modal: Printable Queue Ticket Slip -->
<?php if ($ticketEncounter): ?>
    <div class="modal fade show d-block" id="ticketModal" tabindex="-1" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-0">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-ticket-perforated-fill me-2"></i> Official Queue Ticket Slip
                    </h5>
                    <a href="dashboard" class="btn-close btn-close-white"></a>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="ticket-receipt" id="printableTicket">
                        <img src="../img/logo.png" alt="Paikit Logo" style="width: 64px; height: 64px; border-radius: 50%; margin-bottom: 8px;">
                        <h5 class="fw-bold mb-0 text-success">PAIKIT HEALTH CENTER</h5>
                        <div class="small text-muted mb-2">Barangay Canlubang &bull; Queue Slip</div>
                        <hr class="my-2">

                        <div class="text-muted small fw-bold">YOUR QUEUE NUMBER</div>
                        <div class="ticket-number-display my-1"><?= sanitize($ticketEncounter['queue_number']) ?></div>

                        <div class="my-2">
                            <strong class="fs-6"><?= sanitize($ticketEncounter['full_name']) ?></strong>
                            <div class="small text-muted">Mobile: <?= sanitize($ticketEncounter['mobile_number']) ?></div>
                        </div>

                        <div class="small text-muted mb-3">
                            Date: <?= date('Y-m-d h:i A', strtotime($ticketEncounter['visit_date'])) ?>
                        </div>

                        <!-- Deterministic QR Code -->
                        <div class="d-flex justify-content-center my-3">
                            <div id="ticketQrcode"></div>
                        </div>

                        <div class="p-2 bg-light border rounded small">
                            <div class="text-muted" style="font-size: 0.75rem;">ONLINE LOOKUP SLIP CODE:</div>
                            <strong class="fs-6 text-danger"><?= sanitize($ticketEncounter['transaction_code'] ?: 'PK-ONLINE') ?></strong>
                            <div class="text-muted" style="font-size: 0.7rem; margin-top: 2px;">
                                Use this code to securely view your diagnosis & e-prescription online at <code>localhost/paikit</code>
                            </div>
                        </div>

                        <div class="mt-3 small text-muted" style="font-size: 0.75rem;">
                            Protected under RA 10173 (Data Privacy Act of 2012).
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light d-flex justify-content-between">
                    <a href="dashboard" class="btn btn-secondary">Close</a>
                    <button type="button" class="btn btn-primary fw-bold" onclick="window.print()">
                        <i class="bi bi-printer-fill me-1"></i> Print Queue Slip
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            const qrText = "<?= 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/paikit/client/view_record?code=' . ($ticketEncounter['transaction_code'] ?? '') ?>";
            new QRCode(document.getElementById("ticketQrcode"), {
                text: qrText,
                width: 120,
                height: 120,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        });
    </script>
<?php endif; ?>

<!-- Email & SMTP Configuration Modal -->
<div class="modal fade" id="smtpModal" tabindex="-1" aria-labelledby="smtpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold" id="smtpModalLabel">
                    <i class="bi bi-envelope-gear me-2"></i> PHPMailer & Outgoing SMTP Configuration
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Info Alert -->
                <div class="alert alert-primary-subtle text-primary border border-primary small mb-3">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    Configure your outgoing email server (Gmail, Yahoo, or Custom SMTP). This enables real email delivery for password reset links and official clinic notices.
                </div>

                <!-- Google App Password Quick Guide -->
                <div class="card border rounded-3 p-3 bg-light mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <strong class="text-dark small"><i class="bi bi-google me-1 text-danger"></i> How to use Gmail SMTP:</strong>
                        <a href="https://myaccount.google.com/apppasswords" target="_blank" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.75rem;">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Open Google App Passwords
                        </a>
                    </div>
                    <ol class="small text-muted mb-0 ps-3" style="font-size: 0.8rem; line-height: 1.5;">
                        <li>Go to your <strong>Google Account Security</strong> settings and ensure <strong>2-Step Verification</strong> is ON.</li>
                        <li>Search for <strong>"App Passwords"</strong> (or visit the button above).</li>
                        <li>Create a new App Password named <em>"Paikit Health Center"</em>.</li>
                        <li>Copy the generated <strong>16-letter password</strong> and paste it into the <em>SMTP Password</em> field below.</li>
                    </ol>
                </div>

                <!-- Configuration Form -->
                <form action="walkin_process" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="save_smtp_settings">

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold small">SMTP Server Host</label>
                            <input type="text" name="smtp_host" class="form-control" value="<?= sanitize($mailConfig['host'] ?? 'smtp.gmail.com') ?>" required placeholder="e.g. smtp.gmail.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Port</label>
                            <input type="number" name="smtp_port" class="form-control" value="<?= (int)($mailConfig['port'] ?? 587) ?>" required placeholder="587">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">SMTP Email / Username</label>
                            <input type="email" name="smtp_username" class="form-control" value="<?= sanitize($mailConfig['username'] ?? '') ?>" required placeholder="e.g. paikithealthcenter@gmail.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">SMTP Password / 16-Letter App Password</label>
                            <div class="input-group">
                                <input type="password" name="smtp_password" id="smtpPassInput" class="form-control" value="<?= sanitize($mailConfig['password'] ?? '') ?>" placeholder="Enter 16-letter App Password">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('smtpPassInput', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Encryption</label>
                            <select name="smtp_encryption" class="form-select">
                                <option value="tls" <?= ($mailConfig['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Port 587 - Recommended)</option>
                                <option value="ssl" <?= ($mailConfig['encryption'] ?? 'tls') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold small">Sender Display Name</label>
                            <input type="text" name="smtp_from_name" class="form-control" value="<?= sanitize($mailConfig['from_name'] ?? 'Paikit Health Center') ?>" required placeholder="Paikit Health Center">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mb-4">
                        <button type="submit" class="btn btn-primary fw-bold shadow-sm">
                            <i class="bi bi-check-circle me-1"></i> Save SMTP Settings
                        </button>
                    </div>
                </form>

                <!-- Test Email Section -->
                <div class="card border rounded-3 p-3 bg-light">
                    <h6 class="fw-bold text-dark mb-2">
                        <i class="bi bi-send-check text-success me-1"></i> Test Real Email Delivery
                    </h6>
                    <p class="text-muted small mb-2" style="font-size: 0.8rem;">
                        Send a live test email to verify that PHPMailer can successfully connect and deliver messages to inboxes.
                    </p>
                    <form action="walkin_process" method="POST" class="d-flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="test_smtp_email">
                        <input type="email" name="test_email" class="form-control form-control-sm" placeholder="Enter recipient email (e.g. your personal email)" required>
                        <button type="submit" class="btn btn-success btn-sm fw-bold text-nowrap">
                            <i class="bi bi-send me-1"></i> Send Test Email
                        </button>
                    </form>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Decline Appointment Modal -->
<div class="modal fade" id="declineModal" tabindex="-1" aria-labelledby="declineModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-danger text-white p-3">
                <h6 class="modal-title fw-bold" id="declineModalLabel">
                    <i class="bi bi-calendar-x-fill me-2"></i> Decline Appointment Request
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="walkin_process" method="POST" id="declineForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="decline_appointment">
                <input type="hidden" name="encounter_id" id="declineEncounterId" value="">

                <div class="modal-body p-4">
                    <div class="alert alert-light border rounded-3 mb-3 p-3">
                        <div class="small text-muted">Patient Request:</div>
                        <div class="fw-bold text-dark fs-6" id="declinePatientName">Patient Name</div>
                        <div class="small text-secondary mt-1">
                            <span id="declineQueueNumber" class="fw-bold text-primary font-monospace">Ticket #</span> &bull; <span id="declineVisitDate">Date</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark small">Select Quick Reason or Type Custom:</label>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2 rounded-pill" onclick="setDeclineReason('Daily patient quota has been reached for this date.')">Quota Full</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2 rounded-pill" onclick="setDeclineReason('Attending physician is on official leave or off-duty on this date.')">Doctor Unavailable</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2 rounded-pill" onclick="setDeclineReason('Health Center is closed due to official holiday / non-working day.')">Public Holiday</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2 rounded-pill" onclick="setDeclineReason('Duplicate appointment request detected. Please attend your existing booking.')">Duplicate Booking</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2 rounded-pill" onclick="setDeclineReason('Patient record requires physical verification at the health center.')">Verification Required</button>
                        </div>
                        <textarea name="decline_reason" id="declineReasonText" class="form-control rounded-3" rows="3" required placeholder="State clear reason to explain to the patient why this request was declined..."></textarea>
                        <div class="form-text small text-muted">This explanation will be shown on the patient's portal and dispatched via email.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm fw-bold rounded-pill px-4">
                        <i class="bi bi-x-circle me-1"></i> Confirm Decline Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../js/bootstrap.bundle.min.js"></script>
<script>
    function openDeclineModal(encounterId, patientName, queueNumber, visitDate) {
        document.getElementById('declineEncounterId').value = encounterId;
        document.getElementById('declinePatientName').textContent = patientName;
        document.getElementById('declineQueueNumber').textContent = 'Ticket #' + queueNumber;
        document.getElementById('declineVisitDate').textContent = visitDate;
        document.getElementById('declineReasonText').value = '';
        const modal = new bootstrap.Modal(document.getElementById('declineModal'));
        modal.show();
    }

    function setDeclineReason(text) {
        document.getElementById('declineReasonText').value = text;
    }
    function handleNameInput(el) {
        // Numbers and unauthorized special characters are prohibited
        el.value = el.value.replace(/[0-9]/g, '').replace(/[^a-zA-ZñÑ\s\.\'\-]/g, '');
        // Prohibit more than 2 consecutive identical letters (e.g. max 2 letters, prevent "aaa", "zzzz")
        el.value = el.value.replace(/([a-zA-ZñÑ])\1{2,}/gi, '$1$1');
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
<?php require_once __DIR__ . '/../includes/settings_modal.php'; ?>
</body>
</html>
