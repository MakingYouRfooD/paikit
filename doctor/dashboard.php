<?php
/**
 * doctor/dashboard.php - Solo Doctor Console
 * Paikit Health Center Information System
 * Strict Role-Based Access Control: Doctor Clearance Only (RA 10173)
 */

require_once __DIR__ . '/../db.php';
$pdo = get_db();
require_role(['Doctor']);

$user = get_auth_user();
require_once __DIR__ . '/../includes/settings_handlers.php';
$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd   = $today . ' 23:59:59';

$msg   = $_GET['msg'] ?? null;
$error = $_GET['error'] ?? null;

// Fetch Today's Duty Schedule
$stmtSched = $pdo->prepare("SELECT * FROM doctor_schedules WHERE duty_date = ? ORDER BY schedule_id DESC LIMIT 1");
$stmtSched->execute([$today]);
$schedule = $stmtSched->fetch();

// Fetch All Configured Schedules (Today + Future) for Quick Inspection & Editing
$stmtUpcomingSched = $pdo->prepare("
    SELECT * FROM doctor_schedules 
    WHERE duty_date >= CURDATE() AND is_available = 1
    ORDER BY duty_date ASC
");
$stmtUpcomingSched->execute();
$upcomingConfiguredSchedules = $stmtUpcomingSched->fetchAll();

$schedulesJson = [];
foreach ($upcomingConfiguredSchedules as $sc) {
    $schedulesJson[$sc['duty_date']] = [
        'max_slots' => (int)$sc['max_slots'],
        'start_time' => substr($sc['start_time'], 0, 5),
        'end_time' => substr($sc['end_time'], 0, 5),
        'notes' => $sc['notes'] ?? ''
    ];
}

// Fetch Doctor's Upcoming Unavailable Dates (Leaves, Seminars, Off Days)
$stmtUnavail = $pdo->prepare("
    SELECT * FROM doctor_schedules 
    WHERE is_available = 0 AND duty_date >= CURDATE() 
    ORDER BY duty_date ASC
");
$stmtUnavail->execute();
$upcomingUnavailable = $stmtUnavail->fetchAll();

// Queue Statistics
$stmtStats = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_today,
        SUM(CASE WHEN queue_status = 'Waiting' AND check_in_status = 'Arrived' THEN 1 ELSE 0 END) AS waiting_count,
        SUM(CASE WHEN queue_status = 'Waiting' AND check_in_status = 'Booked' THEN 1 ELSE 0 END) AS booked_pending_count,
        SUM(CASE WHEN queue_status = 'Serving' THEN 1 ELSE 0 END) AS serving_count,
        SUM(CASE WHEN queue_status = 'Completed' THEN 1 ELSE 0 END) AS completed_count
    FROM encounters
    WHERE visit_date BETWEEN ? AND ?
");
$stmtStats->execute([$todayStart, $todayEnd]);
$stats = $stmtStats->fetch() ?: ['total_today' => 0, 'waiting_count' => 0, 'booked_pending_count' => 0, 'serving_count' => 0, 'completed_count' => 0];

// Interleaved Queue (Ratio: 2 Appointments : 1 Walk-in, Priority First)
$interleavedWaiting = get_interleaved_queue($pdo, $todayStart, $todayEnd);
$nextInLine = !empty($interleavedWaiting) ? $interleavedWaiting[0] : null;

// Determine Active Encounter to display in Consultation Room
$selectedEncounterId = isset($_GET['encounter_id']) ? (int)$_GET['encounter_id'] : 0;

if ($selectedEncounterId > 0) {
    $stmtCurrent = $pdo->prepare("
        SELECT e.*, p.full_name, p.mobile_number, p.birth_date, p.gender, p.sitio, p.street_address, p.account_type, t.transaction_code
        FROM encounters e
        JOIN patients p ON e.patient_id = p.patient_id
        LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
        WHERE e.encounter_id = ?
    ");
    $stmtCurrent->execute([$selectedEncounterId]);
    $activeEncounter = $stmtCurrent->fetch();
} else {
    // Default to the encounter currently marked 'Serving'
    $stmtServing = $pdo->prepare("
        SELECT e.*, p.full_name, p.mobile_number, p.birth_date, p.gender, p.sitio, p.street_address, p.account_type, t.transaction_code
        FROM encounters e
        JOIN patients p ON e.patient_id = p.patient_id
        LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
        WHERE e.visit_date BETWEEN ? AND ? AND e.queue_status = 'Serving'
        ORDER BY e.encounter_id DESC LIMIT 1
    ");
    $stmtServing->execute([$todayStart, $todayEnd]);
    $activeEncounter = $stmtServing->fetch();

    // If none serving, preview the next in line from the fair arrived queue
    if (!$activeEncounter && $nextInLine) {
        $stmtWaiting = $pdo->prepare("
            SELECT e.*, p.full_name, p.mobile_number, p.birth_date, p.gender, p.sitio, p.street_address, p.account_type, t.transaction_code
            FROM encounters e
            JOIN patients p ON e.patient_id = p.patient_id
            LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
            WHERE e.encounter_id = ?
        ");
        $stmtWaiting->execute([$nextInLine['encounter_id']]);
        $activeEncounter = $stmtWaiting->fetch();
    }
}

// Fetch Appointments Scheduled Today but Not Yet Arrived (Standby)
$stmtBooked = $pdo->prepare("
    SELECT e.*, p.full_name, p.mobile_number, p.gender, p.sitio
    FROM encounters e
    JOIN patients p ON e.patient_id = p.patient_id
    WHERE e.visit_date BETWEEN ? AND ? AND e.queue_status = 'Waiting' AND e.check_in_status = 'Booked'
    ORDER BY e.encounter_id ASC
");
$stmtBooked->execute([$todayStart, $todayEnd]);
$bookedStandby = $stmtBooked->fetchAll();

// Fetch Completed encounters for today
$stmtCompleted = $pdo->prepare("
    SELECT e.*, p.full_name, p.mobile_number, p.gender, p.sitio
    FROM encounters e
    JOIN patients p ON e.patient_id = p.patient_id
    WHERE e.visit_date BETWEEN ? AND ? AND e.queue_status = 'Completed'
    ORDER BY e.encounter_id DESC
");
$stmtCompleted->execute([$todayStart, $todayEnd]);
$completedQueue = $stmtCompleted->fetchAll();

// Decode active encounter details
$activeVitals = [];
$activePrescriptions = [];
$pastEncounters = [];

if ($activeEncounter) {
    if (!empty($activeEncounter['vital_signs'])) {
        $activeVitals = json_decode($activeEncounter['vital_signs'], true) ?: [];
    }

    // Load active prescriptions
    $stmtRx = $pdo->prepare("SELECT * FROM prescriptions WHERE encounter_id = ?");
    $stmtRx->execute([$activeEncounter['encounter_id']]);
    $rxRecord = $stmtRx->fetch();
    if ($rxRecord && !empty($rxRecord['medication_details'])) {
        $activePrescriptions = json_decode($rxRecord['medication_details'], true) ?: [];
    }

    // Load Past Medical History for this patient (Solo Doctor has full clinical clearance)
    $stmtPast = $pdo->prepare("
        SELECT e.*, p.medication_details, p.dispense_status
        FROM encounters e
        LEFT JOIN prescriptions p ON e.encounter_id = p.encounter_id
        WHERE e.patient_id = ? AND e.encounter_id != ?
        ORDER BY e.visit_date DESC
    ");
    $stmtPast->execute([$activeEncounter['patient_id'], $activeEncounter['encounter_id']]);
    $pastEncounters = $stmtPast->fetchAll();
}

// Today's Queue List
$stmtQueue = $pdo->prepare("
    SELECT e.*, p.full_name, p.mobile_number, p.gender, t.transaction_code
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
$todayQueue = $stmtQueue->fetchAll();

// Patient Search handling
$searchQuery = trim($_GET['search_patient'] ?? '');
$searchResults = [];
if (!empty($searchQuery)) {
    $stmtSearch = $pdo->prepare("
        SELECT p.*, COUNT(e.encounter_id) AS visit_count, MAX(e.visit_date) AS last_visit
        FROM patients p
        LEFT JOIN encounters e ON p.patient_id = e.patient_id
        WHERE p.full_name LIKE ? OR p.mobile_number LIKE ?
        GROUP BY p.patient_id
        ORDER BY p.full_name ASC LIMIT 10
    ");
    $stmtSearch->execute(["%$searchQuery%", "%$searchQuery%"]);
    $searchResults = $stmtSearch->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solo Doctor Console - Paikit Health Center</title>
    <link rel="icon" type="image/png" href="../img/logo.png">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/modern-theme.css">
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/accessibility.js"></script>
    <style>
        .patient-card {
            background-color: #f1f5f9;
            border-left: 4px solid var(--primary);
            border-radius: 10px;
        }
        .vitals-box {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 16px;
        }
        .rx-table th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #14532d;
            background-color: #f0fdf4 !important;
            font-weight: 700;
        }
        .timeline-item {
            border-left: 2px solid #86efac;
            padding-left: 18px;
            margin-bottom: 24px;
            position: relative;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: var(--primary);
        }
    </style>
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark doctor-header shadow-sm sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard">
            <img src="../img/logo.png" alt="Paikit Logo" class="brand-logo-circle">
            <div>
                <span class="d-block lh-1 text-white fw-bold fs-5">Paikit Health Center</span>
                <span class="small text-white-50" style="font-size: 0.72rem; letter-spacing: 0.5px;">Solo Doctor Consultation Console</span>
            </div>
        </a>
        <div class="d-flex align-items-center gap-2">
            <!-- Senior Text Toggle Button -->
            <button type="button" class="senior-toggle-btn me-1" onclick="toggleSeniorMode()" title="Toggle larger text for seniors">
                <i class="bi bi-fonts fs-6"></i> <strong>A+</strong> Senior Text
            </button>
            <button type="button" class="btn btn-outline-light btn-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#dutyModal">
                <span><i class="bi bi-clock-history me-1 text-info"></i> Duty: <strong><?= $schedule ? date('g:i A', strtotime($schedule['start_time'])) . ' - ' . date('g:i A', strtotime($schedule['end_time'])) : '08:00 AM - 05:00 PM' ?></strong></span>
                <span class="badge bg-warning text-dark"><i class="bi bi-sliders me-1"></i>Quota: <?= $schedule ? (int)$schedule['max_slots'] : 50 ?> patients/day</span>
            </button>
            <button type="button" class="btn btn-outline-light btn-sm d-flex align-items-center gap-1" onclick="openUnavailableDatesTab()">
                <i class="bi bi-calendar-x text-warning"></i>
                <span>Doctor Off Dates</span>
                <?php if (!empty($upcomingUnavailable)): ?>
                    <span class="badge bg-danger rounded-pill"><?= count($upcomingUnavailable) ?></span>
                <?php endif; ?>
            </button>
            <a href="../queue_monitor" target="_blank" class="btn btn-outline-light btn-sm">
                <i class="bi bi-display me-1 text-warning"></i> Public Monitor
            </a>
            <button type="button" class="btn btn-outline-light btn-sm ms-1 px-3 py-1 rounded-pill d-flex align-items-center gap-1 shadow-sm" onclick="openSettingsModal('profile')" data-bs-toggle="modal" data-bs-target="#settingsModal" title="Doctor Account Settings & Security">
                <i class="bi bi-person-circle fs-6 text-info"></i>
                <span class="fw-bold text-white"><?= sanitize($user['full_name'] ?? 'Doctor') ?></span>
                <i class="bi bi-gear-fill ms-1 text-warning" style="font-size: 0.8rem;"></i>
            </button>
            <a href="../logout" class="btn btn-danger btn-sm ms-2">
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

    <!-- Doctor Console Hero Banner -->
    <div class="dash-hero mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-bold">
                    <i class="bi bi-heart-pulse-fill me-1"></i> Solo Physician Console
                </span>
                <span class="text-secondary small fw-semibold"><i class="bi bi-clock me-1 text-primary"></i> <?= date('l, F j, Y') ?></span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                Welcome back, <?= sanitize($user['full_name'] ?? 'Doctor') ?>! 🩺
            </h3>
            <div class="text-secondary small">
                Clinical Consultation Room & Diagnostic Workstation. Data Privacy Act (RA 10173) Clinical Cryptography Active.
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#dutyModal">
                <i class="bi bi-sliders me-1 text-warning"></i> Set Patient Quota & Hours
            </button>
            <button class="btn btn-primary btn-sm rounded-pill fw-bold px-3 shadow-sm" onclick="openUnavailableDatesTab()">
                <i class="bi bi-calendar-x-fill me-1"></i> Doctor Off Dates
            </button>
        </div>
    </div>

    <!-- Top Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="stat-label">Total Registered Today</div>
                        <div class="stat-value text-dark"><?= (int)$stats['total_today'] ?></div>
                    </div>
                    <div class="stat-icon-box stat-icon-blue">
                        <i class="bi bi-people-fill"></i>
                    </div>
                </div>
                <div class="stat-sub text-muted">
                    <i class="bi bi-calendar-check me-1 text-primary"></i> Outpatient visits today
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="stat-label">Now Serving Queue</div>
                        <div class="stat-value text-danger">
                            <?= $activeEncounter && $activeEncounter['queue_status'] === 'Serving' ? sanitize($activeEncounter['queue_number']) : '<span class="fs-4 text-muted">Standby</span>' ?>
                        </div>
                    </div>
                    <div class="stat-icon-box stat-icon-rose">
                        <i class="bi bi-broadcast"></i>
                    </div>
                </div>
                <div class="stat-sub text-muted">
                    <span class="live-pulse-dot me-1"></span> Live Consultation Room 1
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="stat-label">Waiting in Clinic</div>
                        <div class="stat-value text-warning"><?= (int)$stats['waiting_count'] ?></div>
                    </div>
                    <div class="stat-icon-box stat-icon-amber">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
                <div class="stat-sub text-muted">
                    <i class="bi bi-clock-history me-1"></i> Awaiting Arrival: <strong><?= (int)$stats['booked_pending_count'] ?></strong>
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
                    <div class="stat-icon-box stat-icon-green">
                        <i class="bi bi-check2-all"></i>
                    </div>
                </div>
                <div class="stat-sub text-success fw-bold">
                    <i class="bi bi-capsule me-1"></i> Diagnoses & eRx issued
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- LEFT COLUMN: ACTIVE CONSULTATION & ENCOUNTER ENCODER -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary px-3 py-2 fs-6">
                            <i class="bi bi-clipboard2-pulse me-1"></i> Clinical Consultation Room
                        </span>
                        <?php if ($activeEncounter): ?>
                            <span class="badge <?= $activeEncounter['queue_status'] === 'Serving' ? 'bg-danger' : ($activeEncounter['queue_status'] === 'Completed' ? 'bg-success' : 'bg-warning text-dark') ?>">
                                <?= sanitize($activeEncounter['queue_status']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2">
                        <!-- Call Next Patient Form (Fair 2:1 Interleaved Queue) -->
                        <form action="update_encounter" method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="call_next">
                            <button type="submit" class="btn btn-warning btn-sm fw-bold shadow-sm" <?= empty($interleavedWaiting) ? 'disabled title="No patients waiting who are marked as Arrived"' : '' ?>>
                                <i class="bi bi-megaphone-fill me-1"></i> Call Next <?= $nextInLine ? "({$nextInLine['queue_number']})" : '' ?>
                            </button>
                        </form>

                        <?php if ($activeEncounter && $activeEncounter['queue_status'] !== 'Completed'): ?>
                            <form action="update_encounter" method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="toggle_priority">
                                <input type="hidden" name="encounter_id" value="<?= (int)$activeEncounter['encounter_id'] ?>">
                                <input type="hidden" name="priority_reason" value="Senior Citizen / PWD / Emergency">
                                <button type="submit" class="btn btn-sm <?= !empty($activeEncounter['is_priority']) ? 'btn-danger' : 'btn-outline-danger' ?> fw-bold" title="Toggle Priority status (Senior, PWD, Pregnant, Emergency)">
                                    <i class="bi bi-lightning-charge-fill me-1"></i> <?= !empty($activeEncounter['is_priority']) ? 'Priority Patient' : 'Set as Priority' ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($activeEncounter): ?>
                            <a href="../client/view_record?encounter_id=<?= $activeEncounter['encounter_id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-printer me-1"></i> View / Print Slip
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body p-4">
                    <?php if (!$activeEncounter): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                            <h5>No active patient selected</h5>
                            <p class="small">Click "Call Next Patient" above or choose a patient from today's queue list.</p>
                        </div>
                    <?php else: ?>
                        <!-- Patient Header Card -->
                        <div class="patient-card p-3 rounded-3 mb-4 shadow-sm">
                            <div class="row align-items-center">
                                <div class="col-md-7">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <h4 class="fw-bold text-dark mb-0"><?= sanitize($activeEncounter['full_name']) ?></h4>
                                        <span class="badge bg-secondary"><?= sanitize($activeEncounter['queue_number']) ?></span>
                                        <span class="badge bg-light text-dark border"><?= sanitize($activeEncounter['account_type'] === 'registered_client' ? 'Registered Client' : 'Walk-in Guest') ?></span>
                                    </div>
                                    <div class="text-secondary small">
                                        <?php 
                                            $birthDate = new DateTime($activeEncounter['birth_date']);
                                            $now = new DateTime();
                                            $age = $now->diff($birthDate)->y;
                                        ?>
                                        <span>Age: <strong><?= $age ?> yrs</strong> (<?= sanitize($activeEncounter['birth_date']) ?>)</span> &bull;
                                        <span>Gender: <strong><?= sanitize($activeEncounter['gender']) ?></strong></span> &bull;
                                        <span>Mobile: <strong><?= sanitize($activeEncounter['mobile_number']) ?></strong></span><br>
                                        <span><i class="bi bi-geo-alt-fill text-danger me-1"></i>Address: <strong><?= sanitize($activeEncounter['sitio'] ?: 'Paikit') ?><?= !empty($activeEncounter['street_address']) ? ' (' . sanitize($activeEncounter['street_address']) . ')' : '' ?>, Canlubang</strong></span>
                                    </div>
                                </div>
                                <div class="col-md-5 text-md-end mt-2 mt-md-0">
                                    <div class="small text-muted">Transaction Code:</div>
                                    <code class="fs-6 fw-bold text-primary"><?= sanitize($activeEncounter['transaction_code'] ?: 'Auto-Generated') ?></code>
                                    <div class="small text-muted mt-1">Arrival: <?= date('h:i A', strtotime($activeEncounter['visit_date'])) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Clinical Encoding Form -->
                        <form action="update_encounter" method="POST" id="encounterForm">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="save_encounter">
                            <input type="hidden" name="encounter_id" value="<?= $activeEncounter['encounter_id'] ?>">

                            <!-- Chief Complaint -->
                            <div class="mb-3">
                                <label class="form-label fw-bold text-dark">
                                    <i class="bi bi-chat-left-text me-1 text-primary"></i> Chief Complaint / Reason for Consultation
                                </label>
                                <textarea name="complaint" class="form-control" rows="2" placeholder="e.g. Cough for 3 days, low-grade fever, body aches..."><?= sanitize($activeEncounter['complaint'] ?? '') ?></textarea>
                            </div>

                            <!-- Clinical Triage & Vital Signs Examination (Solo Doctor Exclusive) -->
                            <div class="card border-primary-subtle bg-light p-3 rounded-3 mb-4 shadow-sm">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label fw-bold text-dark mb-0 fs-6">
                                        <i class="bi bi-heart-pulse-fill text-danger me-2"></i> Clinical & Triage Vitals Examination
                                    </label>
                                    <span class="badge bg-primary text-white">
                                        <i class="bi bi-person-badge-fill me-1"></i> Attending Doctor Only
                                    </span>
                                </div>
                                <div class="text-muted small mb-3">
                                    <i class="bi bi-info-circle text-primary me-1"></i>
                                    Eksklusibong tungkulin ng Attending Doctor ang pagsusuri at pag-encode ng official patient vital signs bago maglabas ng diagnosis at prescription.
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-2 col-6">
                                        <label class="small fw-bold text-dark mb-1">BP (mmHg)</label>
                                        <input type="text" name="vital_bp" class="form-control form-control-sm" placeholder="120/80" value="<?= sanitize($activeVitals['bp'] ?? '') ?>">
                                        <small class="text-muted" style="font-size: 0.7rem;">Blood Pressure</small>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <label class="small fw-bold text-dark mb-1">Temp (°C)</label>
                                        <input type="text" name="vital_temp" class="form-control form-control-sm" placeholder="36.8" value="<?= sanitize($activeVitals['temp'] ?? '') ?>">
                                        <small class="text-muted" style="font-size: 0.7rem;">Body Temperature</small>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <label class="small fw-bold text-dark mb-1">Weight (kg)</label>
                                        <input type="text" name="vital_weight" id="docWeight" class="form-control form-control-sm" placeholder="65" value="<?= sanitize($activeVitals['weight'] ?? '') ?>" oninput="calcBMI()">
                                        <small class="text-muted" style="font-size: 0.7rem;">Timbang</small>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <label class="small fw-bold text-dark mb-1">Height (cm)</label>
                                        <input type="text" name="vital_height" id="docHeight" class="form-control form-control-sm" placeholder="165" value="<?= sanitize($activeVitals['height'] ?? '') ?>" oninput="calcBMI()">
                                        <small class="text-muted" style="font-size: 0.7rem;">Tangkad</small>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <label class="small fw-bold text-dark mb-1">Pulse (bpm)</label>
                                        <input type="text" name="vital_pulse" class="form-control form-control-sm" placeholder="78" value="<?= sanitize($activeVitals['pulse'] ?? '') ?>">
                                        <small class="text-muted" style="font-size: 0.7rem;">Heart Rate</small>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <label class="small fw-bold text-dark mb-1">Resp Rate (cpm)</label>
                                        <input type="text" name="vital_rr" class="form-control form-control-sm" placeholder="18" value="<?= sanitize($activeVitals['rr'] ?? '') ?>">
                                        <small class="text-muted" style="font-size: 0.7rem;">Respiratory Rate</small>
                                    </div>
                                </div>
                                <div class="mt-2 pt-2 border-top d-flex justify-content-between align-items-center small text-muted">
                                    <span><i class="bi bi-calculator me-1"></i> Auto BMI Indicator: <strong id="docBmiResult" class="text-dark">Enter Wt & Ht</strong></span>
                                    <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i> Saved to Patient EHR History</span>
                                </div>
                            </div>

                            <!-- Diagnostic Notes (Restricted to Doctor under RA 10173) -->
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-bold text-dark mb-0">
                                        <i class="bi bi-file-earmark-medical-fill me-1 text-primary"></i> Doctor's Diagnostic Assessment & Clinical Notes
                                    </label>
                                    <span class="badge bg-danger-subtle text-danger small">
                                        <i class="bi bi-shield-lock-fill me-1"></i> RA 10173 Doctor Only Clearance
                                    </span>
                                </div>
                                <div class="text-muted small mb-2">Subjective findings, physical examination, clinical diagnosis/ICD assessment, and doctor's advice.</div>
                                <textarea name="diagnostic_notes" class="form-control" rows="5" placeholder="ASSESSMENT: Upper Respiratory Tract Infection (URTI)&#10;PHYSICAL EXAM: Pharynx hyperemic, clear breath sounds.&#10;PLAN: Increase fluids, rest, symptomatic relief."><?= sanitize($activeEncounter['diagnostic_notes'] ?? '') ?></textarea>
                            </div>

                            <!-- E-Prescription Generator -->
                            <div class="card border mb-4">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                                    <div class="fw-bold text-primary">
                                        <i class="bi bi-prescription fs-5 me-1"></i> Electronic Prescription (Rx)
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addMedRow()">
                                        <i class="bi bi-plus-circle me-1"></i> Add Medication
                                    </button>
                                </div>
                                <div class="card-body p-2">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-borderless align-middle rx-table mb-0">
                                            <thead class="text-muted">
                                                <tr>
                                                    <th style="width: 35%;">Generic / Brand Medicine</th>
                                                    <th style="width: 30%;">Dosage & Instructions</th>
                                                    <th style="width: 15%;">Duration</th>
                                                    <th style="width: 15%;">Qty</th>
                                                    <th style="width: 5%;"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="medsContainer">
                                                <?php if (!empty($activePrescriptions)): ?>
                                                    <?php foreach ($activePrescriptions as $index => $item): ?>
                                                        <tr>
                                                            <td>
                                                                <input type="text" name="med_name[]" class="form-control form-control-sm" value="<?= sanitize($item['medication'] ?? '') ?>" placeholder="e.g. Amoxicillin 500mg" required>
                                                            </td>
                                                            <td>
                                                                <input type="text" name="med_dosage[]" class="form-control form-control-sm" value="<?= sanitize($item['dosage'] ?? '') ?>" placeholder="e.g. 1 cap every 8 hrs after meals" required>
                                                            </td>
                                                            <td>
                                                                <input type="text" name="med_duration[]" class="form-control form-control-sm" value="<?= sanitize($item['duration'] ?? '') ?>" placeholder="e.g. 7 days">
                                                            </td>
                                                            <td>
                                                                <input type="text" name="med_quantity[]" class="form-control form-control-sm" value="<?= sanitize($item['quantity'] ?? '') ?>" placeholder="e.g. 21 caps">
                                                            </td>
                                                            <td>
                                                                <button type="button" class="btn btn-outline-danger btn-sm p-1" onclick="this.closest('tr').remove()">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td>
                                                            <input type="text" name="med_name[]" class="form-control form-control-sm" placeholder="e.g. Paracetamol 500mg Tablet">
                                                        </td>
                                                        <td>
                                                            <input type="text" name="med_dosage[]" class="form-control form-control-sm" placeholder="1 tablet every 4-6 hrs for fever">
                                                        </td>
                                                        <td>
                                                            <input type="text" name="med_duration[]" class="form-control form-control-sm" placeholder="3 days">
                                                        </td>
                                                        <td>
                                                            <input type="text" name="med_quantity[]" class="form-control form-control-sm" placeholder="10 tablets">
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-outline-danger btn-sm p-1" onclick="this.closest('tr').remove()">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Quick Formulary Presets -->
                                    <div class="mt-2 pt-2 border-top d-flex gap-2 align-items-center flex-wrap">
                                        <span class="small text-muted">Quick Presets:</span>
                                        <button type="button" class="btn btn-sm btn-light border" onclick="quickAddRx('Paracetamol 500mg Tablet', '1 tablet every 4 to 6 hrs as needed for fever', '3 days', '10 tablets')">+ Paracetamol</button>
                                        <button type="button" class="btn btn-sm btn-light border" onclick="quickAddRx('Amoxicillin 500mg Capsule', '1 capsule every 8 hours after meals', '7 days', '21 capsules')">+ Amoxicillin</button>
                                        <button type="button" class="btn btn-sm btn-light border" onclick="quickAddRx('Oral Rehydration Salts (ORS)', 'Dissolve 1 sachet in 1 liter clean drinking water; drink after each loose stool', '3 days', '6 sachets')">+ ORS</button>
                                        <button type="button" class="btn btn-sm btn-light border" onclick="quickAddRx('Losartan 50mg Tablet', '1 tablet once daily in the morning', '30 days', '30 tablets')">+ Losartan</button>
                                    </div>
                                </div>
                            </div>

                            <!-- Action & Status Selection -->
                            <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                                <div class="d-flex align-items-center gap-2">
                                    <label class="form-label mb-0 fw-bold small text-muted">CONSULTATION OUTCOME:</label>
                                    <select name="queue_status" class="form-select form-select-sm" style="width: 170px;">
                                        <option value="Serving" <?= $activeEncounter['queue_status'] === 'Serving' ? 'selected' : '' ?>>Keep In Progress</option>
                                        <option value="Completed" <?= $activeEncounter['queue_status'] === 'Completed' ? 'selected' : '' ?>>Mark Completed</option>
                                        <option value="Cancelled" <?= $activeEncounter['queue_status'] === 'Cancelled' ? 'selected' : '' ?>>Mark Cancelled</option>
                                    </select>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary fw-bold px-4">
                                        <i class="bi bi-save2-fill me-1"></i> Save Encounter & E-Prescription
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Patient Past Medical History Tab (Doctor Clearance) -->
            <?php if ($activeEncounter && !empty($pastEncounters)): ?>
                <div class="card border-0 shadow-sm rounded-3 mt-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-journal-medical text-primary me-2"></i> Patient Longitudinal Medical History
                        </h5>
                        <span class="badge bg-info-subtle text-info border">
                            <?= count($pastEncounters) ?> Previous Consultation(s)
                        </span>
                    </div>
                    <div class="card-body p-4">
                        <?php foreach ($pastEncounters as $past): ?>
                            <div class="timeline-item">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-dark">Visit on <?= date('F j, Y - g:i A', strtotime($past['visit_date'])) ?></strong>
                                    <span class="badge bg-secondary"><?= sanitize($past['queue_number']) ?></span>
                                </div>
                                <div class="small text-muted mb-2">
                                    <?php if (!empty($past['vital_signs'])): ?>
                                        <?php $pv = json_decode($past['vital_signs'], true) ?: []; ?>
                                        <span>BP: <strong><?= sanitize($pv['bp'] ?? 'N/A') ?></strong></span> &bull;
                                        <span>Temp: <strong><?= sanitize($pv['temp'] ?? 'N/A') ?> °C</strong></span> &bull;
                                        <span>Weight: <strong><?= sanitize($pv['weight'] ?? 'N/A') ?> kg</strong></span>
                                    <?php endif; ?>
                                </div>
                                <div class="p-3 bg-light rounded-2 mb-2">
                                    <div class="fw-bold small text-primary mb-1">Doctor's Diagnosis & Notes:</div>
                                    <div class="small text-dark" style="white-space: pre-line;"><?= sanitize($past['diagnostic_notes'] ?: 'No notes encoded.') ?></div>
                                </div>
                                <?php if (!empty($past['medication_details'])): ?>
                                    <div class="small text-muted">
                                        <i class="bi bi-capsule me-1 text-success"></i> <strong>Prescribed:</strong>
                                        <?php 
                                            $pm = json_decode($past['medication_details'], true) ?: [];
                                            $medList = array_map(fn($m) => sanitize($m['medication'] ?? ''), $pm);
                                            echo implode(', ', $medList);
                                        ?>
                                        (Status: <span class="badge bg-light text-dark border"><?= sanitize($past['dispense_status']) ?></span>)
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN: TODAY'S QUEUE & PATIENT RECORD LOOKUP -->
        <div class="col-lg-4">
            <!-- Queue List Card: Fair 2:1 Interleaved Queue & Standby Appointments -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-list-ol text-primary me-2"></i> Clinic Queue (Fair 2:1)
                    </h5>
                    <span class="badge bg-primary"><?= count($interleavedWaiting) ?> Waiting</span>
                </div>
                <div class="card-body p-0">
                    <div class="p-2 bg-light border-bottom small text-muted">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        Active Queue of <strong>Arrived</strong> Patients (Ratio: 2 Appt : 1 Walk-in; Priority First).
                    </div>
                    <div class="list-group list-group-flush" style="max-height: 480px; overflow-y: auto;">
                        <?php if (empty($interleavedWaiting)): ?>
                            <div class="p-4 text-center text-muted small">
                                <i class="bi bi-check2-circle fs-3 text-success d-block mb-1"></i>
                                No patients waiting who are marked as "Arrived".
                            </div>
                        <?php else: ?>
                            <?php foreach ($interleavedWaiting as $q): ?>
                                <?php 
                                    $isActive = ($activeEncounter && $activeEncounter['encounter_id'] == $q['encounter_id']);
                                    $isNext = ($q['queue_position'] === 1);
                                ?>
                                <div class="list-group-item p-3 <?= $isActive ? 'bg-primary-subtle border-start border-primary border-4' : '' ?> <?= $isNext ? 'border-start border-warning border-3' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div>
                                            <span class="badge <?= $isNext ? 'bg-warning text-dark' : 'bg-secondary' ?> me-1">
                                                #<?= $q['queue_position'] ?><?= $isNext ? ' (NEXT)' : '' ?>
                                            </span>
                                            <a href="dashboard?encounter_id=<?= $q['encounter_id'] ?>" class="fw-bold text-dark text-decoration-none">
                                                <?= sanitize($q['queue_number']) ?> &bull; <?= sanitize($q['full_name']) ?>
                                            </a>
                                        </div>
                                        <div>
                                            <?php if (!empty($q['is_priority'])): ?>
                                                <span class="badge bg-danger"><i class="bi bi-lightning-fill"></i> PRIORITY</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center small text-muted">
                                        <div>
                                            <span class="badge <?= $q['encounter_type'] === 'Appointment' ? 'bg-info-subtle text-info border' : 'bg-primary-subtle text-primary border' ?>">
                                                <?= sanitize($q['encounter_type']) ?>
                                            </span>
                                            &bull; <?= sanitize($q['gender']) ?>
                                            <?php if (!empty($q['priority_reason'])): ?>
                                                <span class="text-danger small">(<?= sanitize($q['priority_reason']) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        <form action="update_encounter" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="toggle_priority">
                                            <input type="hidden" name="encounter_id" value="<?= (int)$q['encounter_id'] ?>">
                                            <button type="submit" class="btn btn-link btn-sm text-decoration-none p-0 <?= !empty($q['is_priority']) ? 'text-danger fw-bold' : 'text-muted' ?>" title="Toggle Priority status">
                                                <i class="bi bi-lightning-charge-fill"></i> <?= !empty($q['is_priority']) ? 'Priority' : 'Prioritize' ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Standby Appointments (Not Yet Arrived) Accordion/Box -->
                <?php if (!empty($bookedStandby)): ?>
                    <div class="card-footer bg-light p-2 border-top">
                        <div class="small fw-bold text-secondary mb-1">
                            <i class="bi bi-clock-history me-1"></i> Awaiting Check-In (<?= count($bookedStandby) ?> Standby)
                        </div>
                        <div class="small text-muted" style="font-size: 0.75rem;">
                            These patients have not yet arrived at the clinic. They will appear in the call list once marked as "Arrived" by Staff.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Patient Longitudinal Search Box -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-search text-primary me-2"></i> Search Medical Files
                    </h5>
                </div>
                <div class="card-body p-3">
                    <form action="dashboard" method="GET">
                        <div class="input-group mb-2">
                            <input type="text" name="search_patient" class="form-control form-control-sm" placeholder="Patient Name or Mobile..." value="<?= sanitize($searchQuery) ?>">
                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i></button>
                        </div>
                    </form>

                    <?php if (!empty($searchResults)): ?>
                        <div class="list-group list-group-flush mt-2 small">
                            <?php foreach ($searchResults as $patient): ?>
                                <div class="list-group-item p-2">
                                    <div class="fw-bold text-dark"><?= sanitize($patient['full_name']) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;">
                                        Mobile: <?= sanitize($patient['mobile_number']) ?> &bull; <?= $patient['visit_count'] ?> visits
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif (!empty($searchQuery)): ?>
                        <div class="text-muted small text-center py-2">No matching patient record found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Duty Hours, Quota & Unavailable Dates Modal -->
<div class="modal fade" id="dutyModal" tabindex="-1" aria-labelledby="dutyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold" id="dutyModalLabel">
                    <i class="bi bi-calendar2-range me-2"></i> Doctor Schedule & Availability Console
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <!-- Navigation Tabs -->
            <ul class="nav nav-tabs nav-fill bg-light border-bottom px-3 pt-2" id="dutyModalTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold py-2 text-dark" id="tab-today-btn" data-bs-toggle="tab" data-bs-target="#paneTodayDuty" type="button" role="tab">
                        <i class="bi bi-sliders text-primary me-1"></i> Daily Quota & Duty Hours
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold py-2 text-dark" id="tab-offdates-btn" data-bs-toggle="tab" data-bs-target="#paneOffDates" type="button" role="tab">
                        <i class="bi bi-calendar-x text-danger me-1"></i> Doctor Unavailable Dates
                        <?php if (!empty($upcomingUnavailable)): ?>
                            <span class="badge bg-danger rounded-pill ms-1"><?= count($upcomingUnavailable) ?></span>
                        <?php endif; ?>
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- TAB 1: DAILY QUOTA & DUTY HOURS (TODAY & FUTURE DATES) -->
                <div class="tab-pane fade show active p-4" id="paneTodayDuty" role="tabpanel">
                    <form action="update_encounter" method="POST" id="quotaDutyForm">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="update_schedule">

                        <div class="alert alert-primary-subtle text-primary border border-primary small mb-3">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            As the attending physician, you can set the <strong>daily patient consultation quota</strong> and consultation hours for today or any upcoming consultation date.
                        </div>

                        <!-- Date Selector with Quick Chips -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-bold mb-0">Duty Date</label>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" onclick="setDoctorDutyDate('<?= $today ?>')">Today</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" onclick="setDoctorDutyDate('<?= date('Y-m-d', strtotime('+1 day')) ?>')">Tomorrow</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" onclick="setDoctorDutyDate('<?= date('Y-m-d', strtotime('next monday')) ?>')">Next Monday</button>
                                </div>
                            </div>
                            <input type="date" name="duty_date" id="doctorDutyDateInput" class="form-control form-control-lg fw-bold text-dark" value="<?= $today ?>" min="<?= $today ?>" required onchange="handleDutyDateChange(this.value)">
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <small class="text-muted">Selected: <strong id="dutyDateFormatted" class="text-primary"><?= date('l, F j, Y', strtotime($today)) ?></strong></small>
                                <span id="dateConfigStatus" class="badge bg-secondary-subtle text-secondary small">Default (50)</span>
                            </div>
                        </div>

                        <!-- Daily Patient Capacity (Quota Format 50) -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-bold mb-0">Daily Patient Capacity (Daily Patient Quota)</label>
                                <span class="badge bg-success text-white">Default: 50</span>
                            </div>
                            <div class="input-group input-group-lg shadow-sm">
                                <span class="input-group-text bg-light text-primary fw-bold"><i class="bi bi-people-fill me-1"></i> Quota</span>
                                <input type="number" name="max_slots" id="doctorQuotaInput" class="form-control form-control-lg fw-bold text-primary fs-3" value="<?= $schedule ? (int)$schedule['max_slots'] : 50 ?>" placeholder="50" min="1" max="250" required>
                                <span class="input-group-text bg-white text-muted">patients / day</span>
                            </div>
                            <!-- Quick Quota Preset Buttons -->
                            <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
                                <small class="text-muted fw-bold">Quick Presets:</small>
                                <button type="button" class="btn btn-sm btn-outline-success fw-bold rounded-pill px-3" onclick="setDoctorQuota(50)">
                                    <i class="bi bi-check-circle me-1"></i> 50 Patients (Standard)
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDoctorQuota(40)">40</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDoctorQuota(60)">60</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDoctorQuota(75)">75</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDoctorQuota(100)">100</button>
                            </div>
                            <small class="text-muted d-block mt-1">Total consultation capacity accepted on this date across combined walk-ins and booked appointments.</small>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold small">Start Time</label>
                                <input type="time" name="start_time" id="doctorStartTimeInput" class="form-control" value="<?= $schedule ? substr($schedule['start_time'], 0, 5) : '08:00' ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold small">End Time</label>
                                <input type="time" name="end_time" id="doctorEndTimeInput" class="form-control" value="<?= $schedule ? substr($schedule['end_time'], 0, 5) : '17:00' ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Physician Notes / Announcement</label>
                            <textarea name="notes" id="doctorNotesInput" class="form-control" rows="2" placeholder="e.g. Priority for senior citizens, pregnant patients, and PWDs."><?= sanitize($schedule['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="d-flex justify-content-end gap-2 border-top pt-3">
                            <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-pill px-4">
                                <i class="bi bi-check-circle me-1"></i> Save Daily Quota & Schedule
                            </button>
                        </div>
                    </form>

                    <!-- Configured Future Consultation Dates & Quotas Table -->
                    <?php if (!empty($upcomingConfiguredSchedules)): ?>
                        <div class="mt-4 pt-3 border-top">
                            <h6 class="fw-bold text-dark mb-2">
                                <i class="bi bi-calendar-check text-success me-1"></i> Configured Consultation Dates & Quotas
                            </h6>
                            <div class="table-responsive" style="max-height: 190px; overflow-y: auto;">
                                <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                    <thead class="table-light sticky-top small text-muted">
                                        <tr>
                                            <th>Date</th>
                                            <th>Quota Capacity</th>
                                            <th>Hours</th>
                                            <th>Notes</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcomingConfiguredSchedules as $usc): ?>
                                            <tr>
                                                <td class="fw-bold <?= $usc['duty_date'] === $today ? 'text-primary' : 'text-dark' ?>">
                                                    <?= date('M j, Y (D)', strtotime($usc['duty_date'])) ?>
                                                    <?= $usc['duty_date'] === $today ? '<span class="badge bg-primary-subtle text-primary ms-1">Today</span>' : '' ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                        <?= (int)$usc['max_slots'] ?> patients
                                                    </span>
                                                </td>
                                                <td class="text-muted small">
                                                    <?= date('g:i A', strtotime($usc['start_time'])) ?> - <?= date('g:i A', strtotime($usc['end_time'])) ?>
                                                </td>
                                                <td class="small text-secondary text-truncate" style="max-width: 140px;">
                                                    <?= sanitize($usc['notes'] ?: '-') ?>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 rounded-pill" onclick="setDoctorDutyDate('<?= $usc['duty_date'] ?>')">
                                                        <i class="bi bi-pencil me-1"></i> Load
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 2: DOCTOR UNAVAILABLE DATES (OFF-DUTY / LEAVE / CONFERENCES) -->
                <div class="tab-pane fade p-4" id="paneOffDates" role="tabpanel">
                    <div class="alert alert-danger-subtle text-danger border border-danger small mb-3">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        Dates marked as <strong>Doctor Unavailable</strong> will be displayed in red on the patient calendar and will automatically block any appointment booking for that day.
                    </div>

                    <!-- Add Unavailable Date Form -->
                    <div class="card border rounded-3 p-3 bg-light mb-4">
                        <h6 class="fw-bold text-dark mb-3">
                            <i class="bi bi-calendar-plus text-danger me-1"></i> Set a Date When Doctor is Not Available
                        </h6>
                        <form action="update_encounter" method="POST" id="unavailForm">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="set_unavailability">

                            <div class="row g-3 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Unavailable Date</label>
                                    <input type="date" name="duty_date" id="unavailDateInput" class="form-control" min="<?= date('Y-m-d') ?>" required onchange="checkDoctorUnavailDay(this)">
                                    <small class="text-muted" style="font-size: 0.72rem;">Mon-Fri (Weekends closed by default)</small>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label fw-bold small">Reason / Physician Note</label>
                                    <input type="text" name="notes" id="unavailNoteInput" class="form-control" placeholder="e.g. Attending Regional Medical Conference" required>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-danger w-100 fw-bold shadow-sm">
                                        <i class="bi bi-calendar-x me-1"></i> Mark Unavailable
                                    </button>
                                </div>
                            </div>

                            <!-- Quick Preset Reason Buttons -->
                            <div class="mt-2 d-flex flex-wrap gap-1 align-items-center">
                                <span class="small text-muted me-1" style="font-size: 0.75rem;">Quick Reasons:</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="setUnavailReason('Attending Regional Medical Conference')">Medical Conference</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="setUnavailReason('Official LGU / Health Office Business')">Official Business</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="setUnavailReason('Official Vacation / Personal Leave')">Personal Leave</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="setUnavailReason('Sick Leave / Medical Recovery')">Sick Leave</button>
                            </div>
                        </form>
                    </div>

                    <!-- List of Registered Upcoming Unavailable Dates -->
                    <h6 class="fw-bold text-dark mb-2">
                        <i class="bi bi-clock-history me-1 text-primary"></i> Registered Upcoming Doctor Unavailable Dates
                    </h6>
                    <?php if (empty($upcomingUnavailable)): ?>
                        <div class="text-center py-4 text-muted bg-white rounded-3 border">
                            <i class="bi bi-calendar-check fs-2 text-success d-block mb-1"></i>
                            <div class="fw-bold small">No Upcoming Off Dates</div>
                            <div style="font-size: 0.8rem;">You are currently scheduled as available for all regular consultation days.</div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border rounded-3 bg-white mb-0">
                                <thead class="table-light">
                                    <tr class="small text-muted text-uppercase">
                                        <th>Date</th>
                                        <th>Day of Week</th>
                                        <th>Reason / Note</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcomingUnavailable as $unRow): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark"><?= date('M j, Y', strtotime($unRow['duty_date'])) ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border"><?= date('l', strtotime($unRow['duty_date'])) ?></span>
                                            </td>
                                            <td>
                                                <span class="text-dark small"><?= sanitize($unRow['notes'] ?: 'Doctor Unavailable') ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-danger">Not Available</span>
                                            </td>
                                            <td class="text-end">
                                                <form action="update_encounter" method="POST" class="d-inline" onsubmit="return confirm('Re-open this date for patient appointments?');">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="remove_unavailability">
                                                    <input type="hidden" name="schedule_id" value="<?= (int)$unRow['schedule_id'] ?>">
                                                    <input type="hidden" name="duty_date" value="<?= sanitize($unRow['duty_date']) ?>">
                                                    <button type="submit" class="btn btn-outline-success btn-sm fw-bold">
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Re-open Date
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-end border-top pt-3 mt-4">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../js/bootstrap.bundle.min.js"></script>
<script>
    const knownDoctorSchedules = <?= json_encode($schedulesJson) ?>;

    function setDoctorQuota(val) {
        const qInput = document.getElementById('doctorQuotaInput');
        if (qInput) {
            qInput.value = val;
            qInput.focus();
        }
    }

    function setDoctorDutyDate(dateStr) {
        const dInput = document.getElementById('doctorDutyDateInput');
        if (dInput) {
            dInput.value = dateStr;
            handleDutyDateChange(dateStr);
        }
    }

    function handleDutyDateChange(dateStr) {
        if (!dateStr) return;
        
        // Update formatted label
        const parts = dateStr.split('-');
        if (parts.length === 3) {
            const dObj = new Date(parts[0], parts[1] - 1, parts[2]);
            const dayOfWeek = dObj.getDay();
            if (dayOfWeek === 0 || dayOfWeek === 6) {
                alert('Notice: Saturdays and Sundays are closed by default clinic policy. Please select a weekday (Monday to Friday).');
            }
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const lbl = document.getElementById('dutyDateFormatted');
            if (lbl) {
                lbl.textContent = dObj.toLocaleDateString('en-US', options);
            }
        }

        const badge = document.getElementById('dateConfigStatus');
        const qInput = document.getElementById('doctorQuotaInput');
        const sTime = document.getElementById('doctorStartTimeInput');
        const eTime = document.getElementById('doctorEndTimeInput');
        const notes = document.getElementById('doctorNotesInput');

        if (knownDoctorSchedules && knownDoctorSchedules[dateStr]) {
            const sc = knownDoctorSchedules[dateStr];
            if (qInput) qInput.value = sc.max_slots || 50;
            if (sTime) sTime.value = sc.start_time || '08:00';
            if (eTime) eTime.value = sc.end_time || '17:00';
            if (notes) notes.value = sc.notes || '';
            if (badge) {
                badge.className = 'badge bg-primary-subtle text-primary border border-primary-subtle small';
                badge.textContent = 'Existing Custom Schedule';
            }
        } else {
            // Default to quota format 50
            if (qInput) qInput.value = 50;
            if (sTime) sTime.value = '08:00';
            if (eTime) eTime.value = '17:00';
            if (notes) notes.value = '';
            if (badge) {
                badge.className = 'badge bg-success-subtle text-success border border-success-subtle small';
                badge.textContent = 'Standard Quota (50)';
            }
        }
    }

    function addMedRow(name = '', dosage = '', duration = '', qty = '') {
        const tbody = document.getElementById('medsContainer');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="med_name[]" class="form-control form-control-sm" value="${escapeHtml(name)}" placeholder="e.g. Amoxicillin 500mg" required></td>
            <td><input type="text" name="med_dosage[]" class="form-control form-control-sm" value="${escapeHtml(dosage)}" placeholder="e.g. 1 cap every 8 hrs" required></td>
            <td><input type="text" name="med_duration[]" class="form-control form-control-sm" value="${escapeHtml(duration)}" placeholder="e.g. 7 days"></td>
            <td><input type="text" name="med_quantity[]" class="form-control form-control-sm" value="${escapeHtml(qty)}" placeholder="e.g. 21 caps"></td>
            <td>
                <button type="button" class="btn btn-outline-danger btn-sm p-1" onclick="this.closest('tr').remove()">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    }

    function quickAddRx(name, dosage, duration, qty) {
        addMedRow(name, dosage, duration, qty);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, m => map[m]);
    }

    function openUnavailableDatesTab() {
        const dutyModalEl = document.getElementById('dutyModal');
        if (dutyModalEl) {
            const modal = bootstrap.Modal.getOrCreateInstance(dutyModalEl);
            modal.show();
            const offTabBtn = document.getElementById('tab-offdates-btn');
            if (offTabBtn) {
                const tabTrigger = new bootstrap.Tab(offTabBtn);
                tabTrigger.show();
            }
        }
    }

    function setUnavailReason(reason) {
        const noteInput = document.getElementById('unavailNoteInput');
        if (noteInput) {
            noteInput.value = reason;
            noteInput.focus();
        }
    }

    function checkDoctorUnavailDay(input) {
        if (!input.value) return;
        const parts = input.value.split('-');
        const dt = new Date(parts[0], parts[1] - 1, parts[2]);
        const day = dt.getDay(); // 0 is Sunday, 6 is Saturday
        if (day === 0 || day === 6) {
            alert('Notice: Saturdays and Sundays are closed by default clinic policy. Please select a weekday (Monday to Friday) for off-duty or leave scheduling.');
            input.value = '';
        }
    }

    function calcBMI() {
        const w = parseFloat(document.getElementById('docWeight')?.value);
        const h = parseFloat(document.getElementById('docHeight')?.value);
        const res = document.getElementById('docBmiResult');
        if (!res) return;
        if (w > 0 && h > 0) {
            const hm = h / 100;
            const bmi = (w / (hm * hm)).toFixed(1);
            let category = 'Normal';
            let badgeClass = 'bg-success';
            if (bmi < 18.5) { category = 'Underweight'; badgeClass = 'bg-warning text-dark'; }
            else if (bmi >= 25 && bmi < 30) { category = 'Overweight'; badgeClass = 'bg-warning text-dark'; }
            else if (bmi >= 30) { category = 'Obese'; badgeClass = 'bg-danger'; }
            res.innerHTML = `${bmi} kg/m² <span class="badge ${badgeClass} ms-1">${category}</span>`;
        } else {
            res.textContent = 'Enter Wt & Ht';
        }
    }
    window.addEventListener('DOMContentLoaded', () => {
        calcBMI();
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
