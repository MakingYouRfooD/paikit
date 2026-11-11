<?php
/**
 * client/dashboard.php - Patient / Client Personal Medical Dashboard
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173)
 * Strict Role-Based Access Control: Authenticated Patients Only
 */

require_once __DIR__ . '/../db.php';
$pdo = get_db();
require_role(['Client']);

$user = get_auth_user();
$patientId = (int)$_SESSION['patient_id'];

$msg   = $_GET['msg'] ?? null;
$error = $_GET['error'] ?? null;

// Fetch patient demographic details
$stmtPat = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
$stmtPat->execute([$patientId]);
$clientPatient = $stmtPat->fetch();

if (!$clientPatient) {
    session_destroy();
    header("Location: ../?error=" . urlencode("Patient record not found."));
    exit;
}

$userId = (int)$_SESSION['user_id'];
$stmtUser = $pdo->prepare("SELECT user_id, username, email, full_name, password_hash, created_at FROM users WHERE user_id = ?");
$stmtUser->execute([$userId]);
$currentUser = $stmtUser->fetch() ?: [];
$userEmail = $currentUser['email'] ?: ($clientPatient['email'] ?? '');

$attendingDoctor = get_attending_physician($pdo);

// HANDLE APPOINTMENT BOOKING
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['book_appointment'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token expired. Please try again.";
    } else {
        $appointmentDate = $_POST['appointment_date'] ?? date('Y-m-d');
        $complaint       = trim($_POST['complaint'] ?? 'General Outpatient Consultation');

        // Check 1: Exclude Saturday (6) and Sunday (7 / 0)
        $dayOfWeek = (int)date('N', strtotime($appointmentDate));
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            $error = "No consultations on Saturdays and Sundays. The health center is open for consultations from Monday to Friday only.";
        } else {
            // Check 2: Check weekly availability setting set by Staff/Admin
            $stmtWk = $pdo->prepare("SELECT is_available, day_name, start_time, end_time FROM weekly_consultation_settings WHERE day_number = ?");
            $stmtWk->execute([$dayOfWeek]);
            $wkSetting = $stmtWk->fetch();

            if ($wkSetting && !(int)$wkSetting['is_available']) {
                $error = "No consultation scheduled on {$wkSetting['day_name']} according to the clinic weekly schedule. Please choose another day.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // Check if patient already has an active ticket for this date (excluding declined requests)
                    $dateStart = $appointmentDate . ' 00:00:00';
                    $dateEnd   = $appointmentDate . ' 23:59:59';

                    $stmtExist = $pdo->prepare("SELECT encounter_id FROM encounters WHERE patient_id = ? AND visit_date BETWEEN ? AND ? AND queue_status IN ('Waiting', 'Serving') AND approval_status != 'Declined'");
                    $stmtExist->execute([$patientId, $dateStart, $dateEnd]);
                    if ($stmtExist->fetch()) {
                        $error = "You already have an active appointment or queue ticket for this date.";
                    } else {
                        // Check 3: Check Doctor's availability & quota limit for this day (Doctor sets daily quota & unavailability)
                        $stmtDocSched = $pdo->prepare("SELECT is_available, max_slots, notes FROM doctor_schedules WHERE duty_date = ?");
                        $stmtDocSched->execute([$appointmentDate]);
                        $docSched = $stmtDocSched->fetch();

                        if ($docSched && isset($docSched['is_available']) && (int)$docSched['is_available'] === 0) {
                            $docReason = !empty($docSched['notes']) ? ": " . $docSched['notes'] : ".";
                            $error = "The attending physician (" . sanitize($attendingDoctor['full_name']) . ") will not be available on this date (" . date('F j, Y', strtotime($appointmentDate)) . ")" . $docReason . " Please select another consultation date from the calendar.";
                        } else {
                            $docMaxSlots = $docSched ? (int)$docSched['max_slots'] : 50;

                            $stmtCountSlots = $pdo->prepare("SELECT COUNT(*) FROM encounters WHERE visit_date BETWEEN ? AND ? AND queue_status != 'Cancelled' AND approval_status != 'Declined'");
                            $stmtCountSlots->execute([$dateStart, $dateEnd]);
                            $takenSlots = (int)$stmtCountSlots->fetchColumn();

                            if ($takenSlots >= $docMaxSlots) {
                                $error = "Consultation capacity for this date has reached the physician's quota limit ({$docMaxSlots} patients). Please choose another date.";
                            } else {
                                // Generate sequential appointment queue number (e.g. A-001)
                                $queueNumber = generate_queue_number($pdo, 'A');

                                // Insert encounter with approval_status = 'Pending' (Requires Staff Approval)
                                $stmtEnc = $pdo->prepare("
                                    INSERT INTO encounters (patient_id, queue_number, encounter_type, visit_date, queue_status, check_in_status, complaint, approval_status)
                                    VALUES (?, ?, 'Appointment', ?, 'Waiting', 'Booked', ?, 'Pending')
                                ");
                                $stmtEnc->execute([$patientId, $queueNumber, $appointmentDate . ' ' . date('H:i:s'), $complaint]);
                                $encounterId = $pdo->lastInsertId();

                                // Generate unique transaction code
                                $txCode = generate_transaction_code();
                                $stmtTx = $pdo->prepare("INSERT INTO walkin_access_tokens (encounter_id, transaction_code) VALUES (?, ?)");
                                $stmtTx->execute([$encounterId, $txCode]);

                                $pdo->commit();
                                audit_log('APPOINTMENT_REQUESTED', "Patient #{$patientId} requested appointment for {$appointmentDate}. Ticket #{$queueNumber} pending Staff review.");
                                header("Location: dashboard?msg=" . urlencode("Appointment requested successfully! Your request (Ticket #{$queueNumber}) has been submitted and is pending Staff review."));
                                exit;
                            }
                        }
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Appointment booking failed: " . $e->getMessage());
                    $error = "Unable to process appointment booking at this time. Please try again.";
                }
            }
        }
    }
}

// Load unified settings handlers (profile update, email code dispatch, change password)
require_once __DIR__ . '/../includes/settings_handlers.php';

// Fetch Active (Today's or Upcoming) Queue Ticket for this Patient
$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd   = $today . ' 23:59:59';

$stmtActive = $pdo->prepare("
    SELECT e.*, t.transaction_code 
    FROM encounters e
    LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
    WHERE e.patient_id = ? 
      AND e.queue_status IN ('Waiting', 'Serving')
      AND e.approval_status != 'Declined'
      AND DATE(e.visit_date) >= ?
    ORDER BY DATE(e.visit_date) ASC, e.encounter_id ASC 
    LIMIT 1
");
$stmtActive->execute([$patientId, $today]);
$activeTicket = $stmtActive->fetch();

// Also fetch recently declined appointment (if any within last 7 days) to explain to the patient
$stmtRecentDeclined = $pdo->prepare("
    SELECT e.*, u.full_name as reviewer_name 
    FROM encounters e
    LEFT JOIN users u ON e.reviewed_by = u.user_id
    WHERE e.patient_id = ? 
      AND e.approval_status = 'Declined'
      AND DATE(e.visit_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY e.reviewed_at DESC, e.encounter_id DESC 
    LIMIT 1
");
$stmtRecentDeclined->execute([$patientId]);
$recentDeclined = $stmtRecentDeclined->fetch();

$isTicketToday = ($activeTicket && date('Y-m-d', strtotime($activeTicket['visit_date'])) === $today);
$liveQueueInfo = null;
if ($isTicketToday && ($activeTicket['approval_status'] ?? '') === 'Approved') {
    $liveQueueInfo = get_patient_live_queue_info($pdo, (int)$activeTicket['encounter_id'], $todayStart, $todayEnd);
}

// Fetch Consultation History & Official E-Prescriptions for this Patient
$stmtPast = $pdo->prepare("
    SELECT e.*, t.transaction_code, pr.prescription_id, pr.medication_details, pr.dispense_status
    FROM encounters e
    LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
    LEFT JOIN prescriptions pr ON e.encounter_id = pr.encounter_id
    WHERE e.patient_id = ?
    ORDER BY e.visit_date DESC
");
$stmtPast->execute([$patientId]);
$pastEncounters = $stmtPast->fetchAll();

// ==========================================
// CONSULTATION CALENDAR ENGINE (MONTHLY VIEW)
// ==========================================
$calMonthParam = $_GET['cal_month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $calMonthParam)) {
    $calMonthParam = date('Y-m');
}

$calYear = (int)substr($calMonthParam, 0, 4);
$calMonthNum = (int)substr($calMonthParam, 5, 2);

$firstDayOfMonthTs = strtotime("{$calMonthParam}-01");
$daysInMonth = (int)date('t', $firstDayOfMonthTs);
$firstDayOfWeek = (int)date('w', $firstDayOfMonthTs); // 0 (Sun) to 6 (Sat)
$monthLabel = date('F Y', $firstDayOfMonthTs);

$prevMonthParam = date('Y-m', strtotime("{$calMonthParam}-01 -1 month"));
$nextMonthParam = date('Y-m', strtotime("{$calMonthParam}-01 +1 month"));

// 1. Fetch Doctor Schedules for this Month (Quota, Leaves, Unavailability)
$stmtCalDoc = $pdo->prepare("
    SELECT duty_date, is_available, max_slots, notes 
    FROM doctor_schedules 
    WHERE duty_date BETWEEN ? AND ?
");
$stmtCalDoc->execute(["{$calMonthParam}-01", "{$calMonthParam}-{$daysInMonth}"]);
$calDocSchedules = [];
while ($row = $stmtCalDoc->fetch()) {
    $calDocSchedules[$row['duty_date']] = $row;
}

// 2. Fetch Weekly Consultation Settings (Days 1 to 5)
$stmtCalWk = $pdo->query("SELECT day_number, day_name, is_available, remarks FROM weekly_consultation_settings");
$calWeeklySettings = [];
while ($row = $stmtCalWk->fetch()) {
    $calWeeklySettings[(int)$row['day_number']] = $row;
}

// 3. Fetch Patient's Booked/Waiting Appointments for this Month
$stmtCalPatient = $pdo->prepare("
    SELECT encounter_id, queue_number, visit_date, queue_status, check_in_status, complaint, approval_status, decline_reason 
    FROM encounters 
    WHERE patient_id = ? AND visit_date BETWEEN ? AND ?
");
$stmtCalPatient->execute([$patientId, "{$calMonthParam}-01 00:00:00", "{$calMonthParam}-{$daysInMonth} 23:59:59"]);
$calPatientBookings = [];
while ($row = $stmtCalPatient->fetch()) {
    $d = substr($row['visit_date'], 0, 10);
    $calPatientBookings[$d][] = $row;
}

// 4. Fetch Total Bookings per Day in this Month (to check against quota)
$stmtCalTotalBookings = $pdo->prepare("
    SELECT DATE(visit_date) AS vdate, COUNT(*) AS booked_total 
    FROM encounters 
    WHERE visit_date BETWEEN ? AND ? 
      AND queue_status != 'Cancelled'
      AND approval_status != 'Declined'
    GROUP BY DATE(visit_date)
");
$stmtCalTotalBookings->execute(["{$calMonthParam}-01 00:00:00", "{$calMonthParam}-{$daysInMonth} 23:59:59"]);
$calTotalBookings = [];
while ($row = $stmtCalTotalBookings->fetch()) {
    $calTotalBookings[$row['vdate']] = (int)$row['booked_total'];
}

// Prepare unavailable dates map for JavaScript instant calendar validation
$docUnavailableJs = [];
foreach ($calDocSchedules as $dDate => $dSched) {
    if (isset($dSched['is_available']) && (int)$dSched['is_available'] === 0) {
        $docUnavailableJs[$dDate] = $dSched['notes'] ?: 'Attending Physician Unavailable';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard - Paikit Health Center</title>
    <link rel="icon" type="image/png" href="../img/logo.png">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/modern-theme.css">
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/accessibility.js"></script>
    <script>
        const doctorUnavailableMap = <?= json_encode($docUnavailableJs) ?>;
        const attendingDocName = <?= json_encode($attendingDoctor['full_name']) ?>;

        function checkApptDay(input) {
            if (!input || !input.value) return false;
            const val = input.value;
            const parts = val.split('-');
            const dt = new Date(parts[0], parts[1] - 1, parts[2]);
            const day = dt.getDay(); // 0 is Sunday, 6 is Saturday

            if (day === 0 || day === 6) {
                alert('Notice: Paikit Health Center is closed on Saturdays and Sundays. Please select a weekday from Monday to Friday.');
                input.value = '';
                return false;
            }

            if (doctorUnavailableMap && doctorUnavailableMap[val]) {
                const reason = doctorUnavailableMap[val];
                alert('Notice: The attending physician (' + attendingDocName + ') will NOT be available on this date (' + val + '):\n\nReason: ' + reason + '\n\nPlease select another consultation date from the calendar.');
                input.value = '';
                return false;
            }
            return true;
        }

        function selectBookingDate(dateStr) {
            const input = document.getElementById('apptDateInput');
            if (input) {
                input.value = dateStr;
                checkApptDay(input);
            }
            const modalEl = document.getElementById('bookModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
        }

        function openSettingsModal(tabName) {
            const modalEl = document.getElementById('settingsModal');
            if (!modalEl) {
                console.error('Settings modal element (#settingsModal) not found!');
                return;
            }
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
            if (tabName === 'security') {
                const secTab = document.getElementById('security-tab');
                if (secTab) bootstrap.Tab.getOrCreateInstance(secTab).show();
            } else if (tabName === 'profile') {
                const profTab = document.getElementById('profile-tab');
                if (profTab) bootstrap.Tab.getOrCreateInstance(profTab).show();
            }
        }
    </script>
    <style>
        .ticket-number {
            font-size: 3.5rem;
            font-weight: 900;
            color: var(--primary);
            letter-spacing: 2px;
            line-height: 1.1;
        }
        .qr-card {
            background: #ffffff;
            border-radius: var(--radius-xl);
            border: 2.5px dashed #86efac;
            box-shadow: var(--shadow-card);
        }
        /* Interactive Consultation Calendar Grid */
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }
        .cal-header-cell {
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            text-align: center;
            padding: 8px 4px;
            background: #f1f5f9;
            color: #334155;
            border-radius: 8px;
        }
        .cal-day-cell {
            min-height: 96px;
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            padding: 6px 8px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            font-size: 0.85rem;
            position: relative;
        }
        .cal-day-cell.is-available {
            border-color: #86efac;
            background: #f0fdf4;
            cursor: pointer;
        }
        .cal-day-cell.is-available:hover {
            border-color: #15803d;
            box-shadow: 0 4px 14px rgba(21, 128, 61, 0.22);
            transform: translateY(-2px);
        }
        .cal-day-cell.is-unavailable {
            border-color: #fca5a5;
            background: #fef2f2;
            cursor: not-allowed;
        }
        .cal-day-cell.is-weekend {
            background: #f8fafc;
            border-color: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
        }
        .cal-day-cell.is-booked {
            border-color: #93c5fd;
            background: #eff6ff;
        }
        .cal-day-cell.is-past {
            background: #fafafa;
            border-color: #e5e7eb;
            color: #9ca3af;
            cursor: not-allowed;
        }
        .cal-day-cell.is-empty {
            background: transparent;
            border: 1px dashed #e2e8f0;
            cursor: default;
        }
        .cal-day-num {
            font-weight: 800;
            font-size: 1.05rem;
            line-height: 1;
        }
        @media (max-width: 768px) {
            .cal-day-cell {
                min-height: 80px;
                padding: 4px;
            }
            .cal-day-num {
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark patient-header shadow-sm sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard">
            <img src="../img/logo.png" alt="Paikit Logo" class="brand-logo-circle">
            <div>
                <span class="d-block lh-1 text-white fw-bold fs-5">Paikit Health Center</span>
                <span class="small text-white-50" style="font-size: 0.72rem; letter-spacing: 0.5px;">Patient Consultation Portal</span>
            </div>
        </a>
        <div class="d-flex align-items-center gap-2">
            <!-- Senior Text Toggle Button -->
            <button type="button" class="senior-toggle-btn me-1" onclick="toggleSeniorMode()" title="Toggle larger text for seniors">
                <i class="bi bi-fonts fs-6"></i> <strong>A+</strong> Senior Text
            </button>
            <a href="../queue_monitor" target="_blank" class="btn btn-outline-light btn-sm">
                <i class="bi bi-display me-1 text-warning"></i> Public Queue Monitor
            </a>
            <a href="../" class="btn btn-outline-light btn-sm">
                <i class="bi bi-house-door me-1"></i> Home
            </a>
            <button type="button" class="btn btn-outline-light btn-sm ms-1 px-3 py-1 rounded-pill d-flex align-items-center gap-1 shadow-sm" onclick="openSettingsModal('profile')" data-bs-toggle="modal" data-bs-target="#settingsModal" title="Account Settings & Security">
                <i class="bi bi-person-circle fs-6 text-info"></i>
                <span class="fw-bold text-white"><?= sanitize($clientPatient['full_name']) ?></span>
                <i class="bi bi-gear-fill ms-1 text-warning" style="font-size: 0.8rem;"></i>
            </button>
            <a href="../logout" class="btn btn-danger btn-sm ms-1">
                <i class="bi bi-box-arrow-right me-1"></i> Log Out
            </a>
        </div>
    </div>
</nav>

<div class="container my-4">

    <!-- Flash Alerts (Errors as alert, Messages as Welcome Modal) -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-4" role="alert">
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

    <!-- Sleek Patient Welcome & Status Hero -->
    <div class="dash-hero mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-bold">
                    <i class="bi bi-shield-check me-1"></i> Patient Portal Verified
                </span>
                <span class="text-secondary small fw-semibold"><i class="bi bi-calendar-event me-1 text-primary"></i> <?= date('l, F j, Y') ?></span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                Hello, <?= sanitize($clientPatient['full_name']) ?>! 👋
            </h3>
            <div class="text-secondary small">
                Welcome to your Barangay Canlubang digital clinic portal. Book appointments, track live queue numbers, and review your consultations.
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#settingsModal">
                <i class="bi bi-gear-fill me-1 text-warning"></i> Settings & Security
            </button>
            <button class="btn btn-primary btn-sm rounded-pill fw-bold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#bookModal">
                <i class="bi bi-calendar-plus-fill me-1"></i> Book Appointment
            </button>
        </div>
    </div>

    <!-- Main Patient Dashboard Grid -->
    <div class="row g-4">
        <!-- Left Column: Patient Profile & Active Queue Ticket -->
        <div class="col-lg-4">
            <!-- Profile Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white overflow-hidden">
                <div class="p-4 text-center" style="background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%);">
                    <div class="mx-auto mb-3 d-flex align-items-center justify-content-center shadow-sm" style="width: 76px; height: 76px; border-radius: 22px; background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); border: 3px solid #ffffff;">
                        <i class="bi bi-person-fill text-success" style="font-size: 2.3rem;"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1"><?= sanitize($clientPatient['full_name']) ?></h5>
                    <div class="d-flex justify-content-center gap-1 mb-2">
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">
                            <i class="bi bi-patch-check-fill me-1"></i>Verified Patient
                        </span>
                        <span class="badge bg-secondary-subtle text-secondary border rounded-pill">
                            P-<?= str_pad($clientPatient['patient_id'], 5, '0', STR_PAD_LEFT) ?>
                        </span>
                    </div>

                    <div class="text-start small border-top pt-3 mt-3">
                        <div class="p-2 mb-2 bg-light rounded-3 d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="bi bi-telephone text-primary me-1"></i>Mobile:</span>
                            <strong class="text-dark"><?= sanitize($clientPatient['mobile_number'] ?? 'N/A') ?></strong>
                        </div>
                        <div class="p-2 mb-2 bg-light rounded-3 d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="bi bi-envelope text-primary me-1"></i>Email:</span>
                            <strong class="text-dark text-truncate" style="max-width: 170px;" title="<?= sanitize($clientPatient['email'] ?? '') ?>"><?= sanitize($clientPatient['email'] ?? 'N/A') ?></strong>
                        </div>
                        <div class="p-2 mb-2 bg-light rounded-3">
                            <div class="text-muted small mb-1"><i class="bi bi-geo-alt text-danger me-1"></i>Address (Canlubang):</div>
                            <strong class="text-dark d-block">
                                <?= sanitize($clientPatient['sitio'] ?? 'Paikit') ?><?= !empty($clientPatient['street_address']) ? ' (' . sanitize($clientPatient['street_address']) . ')' : '' ?>, Canlubang
                            </strong>
                        </div>
                        <div class="p-2 mb-2 bg-light rounded-3 d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="bi bi-cake2 text-warning me-1"></i>Birth / Gender:</span>
                            <strong class="text-dark"><?= sanitize($clientPatient['birth_date'] ?? 'N/A') ?> (<?= sanitize($clientPatient['gender'] ?? 'N/A') ?>)</strong>
                        </div>
                        <div class="mt-3 p-2 bg-success-subtle text-success rounded-3 small border border-success-subtle" style="font-size: 0.74rem;">
                            <i class="bi bi-shield-check me-1"></i> Protected under RA 10173 (Data Privacy Act of 2012).
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm w-100 rounded-pill fw-bold mt-3 shadow-sm py-2" data-bs-toggle="modal" data-bs-target="#settingsModal">
                            <i class="bi bi-gear-fill me-1 text-warning"></i> Account Settings & Security
                        </button>
                    </div>
                </div>
            </div>

            <!-- Active Queue Ticket / Request with QR Code & Live Tracker -->
            <?php if ($activeTicket): ?>
                <?php 
                    $isPendingApproval = (($activeTicket['approval_status'] ?? '') === 'Pending');
                    $isApproved = (($activeTicket['approval_status'] ?? '') === 'Approved');
                ?>
                <div class="card qr-card shadow-sm p-4 text-center mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-primary text-uppercase px-3 py-1 rounded-pill">
                            <?= sanitize($activeTicket['encounter_type']) ?> <?= $isPendingApproval ? 'REQUEST' : 'TICKET' ?>
                        </span>
                        <?php if ($isPendingApproval): ?>
                            <span class="badge bg-warning text-dark px-3 py-1 rounded-pill">
                                <i class="bi bi-hourglass-split me-1"></i> PENDING APPROVAL
                            </span>
                        <?php else: ?>
                            <span class="badge bg-success px-3 py-1 rounded-pill">
                                <i class="bi bi-check-circle-fill me-1"></i> APPROVED
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ticket-number mb-1"><?= sanitize($activeTicket['queue_number']) ?></div>

                    <div class="small text-muted mb-3">
                        <i class="bi bi-calendar3 text-primary me-1"></i> <?= date('l, F j, Y', strtotime($activeTicket['visit_date'])) ?>
                        <span class="mx-1">&bull;</span>
                        <i class="bi bi-heart-pulse text-danger me-1"></i> <?= sanitize($activeTicket['complaint'] ?: 'General Consultation') ?>
                    </div>

                    <?php if ($isPendingApproval): ?>
                        <!-- Pending Staff Review Status Box -->
                        <div class="alert alert-warning border-0 rounded-3 text-start small mb-3 p-3">
                            <div class="d-flex align-items-center mb-1">
                                <i class="bi bi-hourglass-top fs-5 text-warning me-2"></i>
                                <strong class="text-dark">AWAITING STAFF APPROVAL</strong>
                            </div>
                            <p class="mb-1 text-muted" style="font-size: 0.82rem;">
                                Your appointment request for <strong><?= date('l, F j, Y', strtotime($activeTicket['visit_date'])) ?></strong> has been submitted to the Health Center Staff Desk for review and quota verification.
                            </p>
                            <div class="small text-secondary mt-1">
                                <i class="bi bi-info-circle text-primary me-1"></i> Once approved by staff, your official check-in QR pass will be activated.
                            </div>
                        </div>

                        <!-- Dimmed QR placeholder while pending -->
                        <div class="p-3 bg-light rounded text-center text-muted small mb-3 border">
                            <i class="bi bi-qr-code fs-1 d-block mb-1 text-secondary opacity-50"></i>
                            <span class="fw-bold d-block text-dark">QR Check-in Pass On Standby</span>
                            <span class="text-muted" style="font-size: 0.78rem;">Will activate automatically upon Staff approval</span>
                        </div>
                    <?php else: ?>
                        <!-- Approved Ticket Logic -->
                        <?php if (!$isTicketToday): ?>
                            <div class="alert alert-success border-0 rounded-3 text-start small mb-3 p-3">
                                <div class="d-flex align-items-center mb-1">
                                    <i class="bi bi-check-circle-fill fs-5 text-success me-2"></i>
                                    <strong class="text-dark">APPOINTMENT CONFIRMED</strong>
                                </div>
                                <p class="mb-0 text-muted" style="font-size: 0.82rem;">
                                    Your appointment is confirmed for <strong><?= date('l, F j, Y', strtotime($activeTicket['visit_date'])) ?></strong>. Upon arrival at the health center on your scheduled date, present this QR code to the Staff Desk.
                                </p>
                            </div>
                        <?php elseif (($activeTicket['check_in_status'] ?? '') === 'Booked'): ?>
                            <div class="alert alert-primary border-0 rounded-3 text-start small mb-3 p-3">
                                <div class="d-flex align-items-center mb-1">
                                    <i class="bi bi-geo-alt-fill fs-5 text-primary me-2"></i>
                                    <strong class="text-dark">TODAY'S APPOINTMENT - CHECK IN READY</strong>
                                </div>
                                <p class="mb-0 text-muted" style="font-size: 0.82rem;">
                                    Your appointment is today! <strong>Present this QR code to the Staff Desk</strong> so they can mark you as <em>"Arrived"</em> and place you in the physician's active calling queue.
                                </p>
                            </div>
                        <?php else: ?>
                            <!-- Live Queue Tracker (Arrived at Clinic) -->
                            <div class="p-3 rounded-4 mb-3" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #bfdbfe;">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="badge bg-success px-2 py-1 rounded-pill small">
                                        <i class="bi bi-geo-alt-fill me-1"></i> CHECKED IN (IN QUEUE)
                                    </span>
                                    <small class="text-muted"><i class="bi bi-broadcast text-danger me-1"></i> Live Tracker</small>
                                </div>
                                <div class="row g-2 align-items-center text-center">
                                    <div class="col-6 border-end">
                                        <div class="text-muted small fw-bold" style="font-size: 0.72rem;">NOW SERVING:</div>
                                        <div class="fs-2 fw-bold text-danger" id="liveServingDisplay"><?= sanitize($liveQueueInfo['serving_number'] ?? 'Standby') ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small fw-bold" style="font-size: 0.72rem;">YOUR POSITION:</div>
                                        <div class="fs-4 fw-bold text-primary" id="livePositionDisplay">
                                            <?php if ($activeTicket['queue_status'] === 'Serving'): ?>
                                                <span class="text-success"><i class="bi bi-megaphone-fill"></i> NOW BEING CALLED!</span>
                                            <?php elseif ($liveQueueInfo && $liveQueueInfo['position'] !== null): ?>
                                                #<?= (int)$liveQueueInfo['position'] ?> in queue
                                            <?php else: ?>
                                                In Queue
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="small text-muted text-center mt-2" id="liveTrackerDetail" style="font-size: 0.8rem;">
                                    <?php if ($activeTicket['queue_status'] === 'Serving'): ?>
                                        <span class="text-success fw-bold"><i class="bi bi-door-open-fill me-1"></i> You are being called! Please proceed into the physician's consultation room.</span>
                                    <?php elseif ($liveQueueInfo && $liveQueueInfo['position'] === 1): ?>
                                        <span class="text-primary fw-bold"><i class="bi bi-bell-fill me-1"></i> YOU ARE NEXT IN LINE! Please standby outside the consultation room.</span>
                                    <?php elseif ($liveQueueInfo && $liveQueueInfo['position'] !== null): ?>
                                        <span><i class="bi bi-people me-1"></i> <strong><?= (int)$liveQueueInfo['ahead_count'] ?></strong> patient(s) currently ahead of you.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- QR Code Container -->
                        <div class="d-flex justify-content-center mb-3">
                            <div id="activeTicketQr"></div>
                        </div>
                    <?php endif; ?>

                    <div class="p-2 bg-light rounded small mb-2">
                        <span class="text-muted" style="font-size: 0.75rem;">TRANSACTION CODE:</span>
                        <div class="fw-bold text-dark font-monospace"><?= sanitize($activeTicket['transaction_code'] ?: 'PK-ONLINE') ?></div>
                    </div>

                    <div class="small text-secondary">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        You may wait comfortably inside or outside; this screen updates automatically in real-time as the queue progresses.
                    </div>
                </div>
            <?php else: ?>
                <?php if ($recentDeclined): ?>
                    <!-- Notice if an appointment was recently declined -->
                    <div class="card border-0 shadow-sm rounded-4 p-4 text-center bg-white mb-4 border-start border-danger border-4">
                        <div class="badge bg-danger-subtle text-danger p-3 rounded-circle mb-2 mx-auto" style="width: 50px; height: 50px;">
                            <i class="bi bi-calendar-x fs-4"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Appointment Request Notice</h6>
                        <div class="alert alert-danger border-0 rounded-3 text-start small mb-2 p-2" style="font-size: 0.8rem;">
                            <strong>Reason:</strong> <?= sanitize($recentDeclined['decline_reason'] ?: 'Capacity full or physician unavailable') ?>
                        </div>
                        <p class="text-muted small mb-3" style="font-size: 0.8rem;">
                            Your appointment for <strong><?= date('M j, Y', strtotime($recentDeclined['visit_date'])) ?></strong> could not be accommodated. You may choose another date.
                        </p>
                        <button class="btn btn-primary btn-sm fw-bold py-2 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#bookModal">
                            <i class="bi bi-calendar-plus me-1"></i> Book Another Date
                        </button>
                    </div>
                <?php else: ?>
                    <div class="card border-0 shadow-sm rounded-4 p-4 text-center bg-white mb-4">
                        <div class="text-muted mb-2">
                            <i class="bi bi-ticket-perforated fs-1 text-secondary"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">No Active Ticket Today</h6>
                        <p class="text-muted small mb-3">
                            You can book an outpatient consultation appointment before visiting the health center.
                        </p>
                        <button class="btn btn-primary btn-sm fw-bold py-2 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#bookModal">
                            <i class="bi bi-calendar-plus me-1"></i> Book an Appointment
                        </button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Right Column: Appointment Booking & Consultation History -->
        <div class="col-lg-8">

            <!-- Interactive Consultation Appointment Calendar -->
            <div class="card border-0 shadow-sm rounded-4 bg-white mb-4 overflow-hidden">
                <div class="card-header bg-white border-bottom py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-calendar3 text-primary"></i> Consultation Appointment Calendar
                        </h5>
                        <small class="text-muted">Click any open weekday to easily book your consultation appointment.</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm shadow-sm">
                            <a href="dashboard?cal_month=<?= $prevMonthParam ?>" class="btn btn-outline-secondary px-2" title="Previous Month">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <span class="btn btn-light fw-bold text-dark px-3" style="cursor: default;">
                                <?= $monthLabel ?>
                            </span>
                            <a href="dashboard?cal_month=<?= $nextMonthParam ?>" class="btn btn-outline-secondary px-2" title="Next Month">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </div>
                        <button class="btn btn-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#bookModal">
                            <i class="bi bi-calendar-plus me-1"></i> Book Appointment
                        </button>
                    </div>
                </div>
                
                <div class="card-body p-3 p-md-4">
                    <!-- Senior-Friendly Color Legend -->
                    <div class="d-flex flex-wrap align-items-center gap-3 mb-3 p-2 bg-light rounded-3 small">
                        <span class="fw-bold text-muted" style="font-size: 0.78rem;">LEGEND:</span>
                        <span class="d-flex align-items-center gap-1">
                            <span class="d-inline-block rounded-circle" style="width: 12px; height: 12px; background-color: #22c55e;"></span>
                            <strong class="text-success">Available</strong> (Click to Book)
                        </span>
                        <span class="d-flex align-items-center gap-1">
                            <span class="d-inline-block rounded-circle" style="width: 12px; height: 12px; background-color: #ef4444;"></span>
                            <strong class="text-danger">Doctor Unavailable</strong> (Off-Duty / Leave)
                        </span>
                        <span class="d-flex align-items-center gap-1">
                            <span class="d-inline-block rounded-circle" style="width: 12px; height: 12px; background-color: #0284c7;"></span>
                            <strong class="text-primary">Your Appointment</strong>
                        </span>
                        <span class="d-flex align-items-center gap-1">
                            <span class="d-inline-block rounded-circle" style="width: 12px; height: 12px; background-color: #94a3b8;"></span>
                            <span class="text-secondary">Closed (Weekends / Off)</span>
                        </span>
                    </div>

                    <!-- 7-Column Calendar Grid -->
                    <div class="cal-grid">
                        <div class="cal-header-cell">Sun</div>
                        <div class="cal-header-cell">Mon</div>
                        <div class="cal-header-cell">Tue</div>
                        <div class="cal-header-cell">Wed</div>
                        <div class="cal-header-cell">Thu</div>
                        <div class="cal-header-cell">Fri</div>
                        <div class="cal-header-cell">Sat</div>

                        <?php
                        // Empty cells before the 1st of the month
                        for ($blank = 0; $blank < $firstDayOfWeek; $blank++): ?>
                            <div class="cal-day-cell is-empty"></div>
                        <?php endfor; ?>

                        <?php
                        for ($day = 1; $day <= $daysInMonth; $day++):
                            $dateStr = sprintf("%s-%02d", $calMonthParam, $day);
                            $dayOfWeekNum = (int)date('w', strtotime($dateStr)); // 0=Sun, 6=Sat
                            $dayOfWeekMon = (int)date('N', strtotime($dateStr)); // 1=Mon, 7=Sun
                            $isWeekend = ($dayOfWeekNum === 0 || $dayOfWeekNum === 6);
                            $isToday = ($dateStr === date('Y-m-d'));
                            $isPast = ($dateStr < date('Y-m-d'));

                            // Check doctor schedule override
                            $docSched = $calDocSchedules[$dateStr] ?? null;
                            $isDocUnavailable = ($docSched && isset($docSched['is_available']) && (int)$docSched['is_available'] === 0);
                            $docNotes = $docSched['notes'] ?? '';
                            $docQuota = $docSched ? (int)$docSched['max_slots'] : 50;

                            // Check weekly settings
                            $wkSetting = $calWeeklySettings[$dayOfWeekMon] ?? null;
                            $isWkUnavailable = (!$isWeekend && $wkSetting && !(int)$wkSetting['is_available']);

                            // Check patient's own bookings on this day
                            $patientBookings = $calPatientBookings[$dateStr] ?? [];
                            $hasPatientBooking = !empty($patientBookings);

                            // Check total booked encounters for quota calculation
                            $dayTakenSlots = $calTotalBookings[$dateStr] ?? 0;
                            $slotsRemaining = max(0, $docQuota - $dayTakenSlots);
                            $isQuotaFull = ($dayTakenSlots >= $docQuota);

                            // Determine Cell Class & State
                            $cellClass = '';
                            $canBook = false;
                            $statusBadge = '';
                            $statusNote = '';

                            if ($isWeekend) {
                                $cellClass = 'is-weekend';
                                $statusBadge = '<span class="badge bg-secondary-subtle text-secondary border small">Closed</span>';
                                $statusNote = 'Weekend';
                            } elseif ($isDocUnavailable) {
                                $cellClass = 'is-unavailable';
                                $statusBadge = '<span class="badge bg-danger text-white"><i class="bi bi-x-circle me-1"></i>Dr. Unavailable</span>';
                                $statusNote = $docNotes ?: 'Doctor Off Duty';
                            } elseif ($isWkUnavailable) {
                                $cellClass = 'is-weekend';
                                $statusBadge = '<span class="badge bg-secondary text-white small">Clinic Closed</span>';
                                $statusNote = $wkSetting['remarks'] ?? 'No Clinic';
                            } elseif ($hasPatientBooking) {
                                // Find if patient has an active (non-declined) booking
                                $activePatientBooking = null;
                                foreach ($patientBookings as $pb) {
                                    if ($pb['queue_status'] !== 'Cancelled' && ($pb['approval_status'] ?? '') !== 'Declined') {
                                        $activePatientBooking = $pb;
                                        break;
                                    }
                                }

                                if ($activePatientBooking) {
                                    $cellClass = 'is-booked';
                                    $firstTicket = $activePatientBooking['queue_number'] ?? '';
                                    if (($activePatientBooking['approval_status'] ?? '') === 'Pending') {
                                        $statusBadge = '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending #' . sanitize($firstTicket) . '</span>';
                                        $statusNote = 'Awaiting Staff Review';
                                    } else {
                                        $statusBadge = '<span class="badge bg-primary text-white"><i class="bi bi-ticket-fill me-1"></i>Approved #' . sanitize($firstTicket) . '</span>';
                                        $statusNote = 'Appointment Confirmed';
                                    }
                                } elseif ($isPast) {
                                    $cellClass = 'is-past';
                                    $statusNote = 'Past Date';
                                } elseif ($isQuotaFull) {
                                    $cellClass = 'is-past';
                                    $statusBadge = '<span class="badge bg-warning text-dark small">Quota Full</span>';
                                    $statusNote = 'No Slots Left';
                                } else {
                                    $cellClass = 'is-available';
                                    $canBook = true;
                                    $statusBadge = '<span class="badge bg-success text-white"><i class="bi bi-check-circle me-1"></i>Available</span>';
                                    $statusNote = $slotsRemaining . ' slots open';
                                }
                            } elseif ($isPast) {
                                $cellClass = 'is-past';
                                $statusNote = 'Past Date';
                            } elseif ($isQuotaFull) {
                                $cellClass = 'is-past';
                                $statusBadge = '<span class="badge bg-warning text-dark small">Quota Full</span>';
                                $statusNote = 'No Slots Left';
                            } else {
                                $cellClass = 'is-available';
                                $canBook = true;
                                $statusBadge = '<span class="badge bg-success text-white"><i class="bi bi-check-circle me-1"></i>Available</span>';
                                $statusNote = $slotsRemaining . ' slots open';
                            }
                        ?>
                            <div class="cal-day-cell <?= $cellClass ?> <?= $isToday ? 'border-primary shadow-sm' : '' ?>"
                                 <?= $canBook ? 'onclick="selectBookingDate(\'' . $dateStr . '\')"' : '' ?>
                                 <?= $isDocUnavailable ? 'title="Attending doctor is not available: ' . sanitize($statusNote) . '"' : '' ?>>
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="cal-day-num <?= $isToday ? 'text-primary' : ($isWeekend || $isPast ? 'text-muted' : 'text-dark') ?>">
                                        <?= $day ?>
                                    </span>
                                    <?php if ($isToday): ?>
                                        <span class="badge bg-primary text-white" style="font-size: 0.65rem;">TODAY</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-2 text-start">
                                    <?php if ($statusBadge): ?>
                                        <div class="mb-1"><?= $statusBadge ?></div>
                                    <?php endif; ?>
                                    <div class="text-truncate small <?= $canBook ? 'text-success fw-bold' : ($isDocUnavailable ? 'text-danger fw-semibold' : 'text-muted') ?>" style="font-size: 0.72rem;">
                                        <?= sanitize($statusNote) ?>
                                    </div>
                                </div>
                                <?php if ($canBook): ?>
                                    <div class="mt-1 text-end" style="font-size: 0.72rem; color: #15803d; font-weight: 700;">
                                        <i class="bi bi-calendar2-plus me-1"></i>Book
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-clipboard2-pulse me-2 text-primary"></i> Consultation Records & E-Prescriptions
                </h5>
                <button class="btn btn-outline-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#bookModal">
                    <i class="bi bi-calendar-plus me-1"></i> New Appointment
                </button>
            </div>

            <div class="card border-0 shadow-sm rounded-4 bg-white p-4">
                <?php if (empty($pastEncounters)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
                        <h6 class="fw-bold">No medical records on file yet</h6>
                        <p class="small mb-0">Your future consultations, checkups, and electronic prescriptions will automatically appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($pastEncounters as $enc): ?>
                            <?php 
                                $v = !empty($enc['vital_signs']) ? json_decode($enc['vital_signs'], true) : [];
                                $rx = !empty($enc['medication_details']) ? json_decode($enc['medication_details'], true) : [];
                            ?>
                            <div class="list-group-item p-3 border-bottom rounded-3 mb-3 bg-light">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="badge bg-primary me-2"><?= sanitize($enc['queue_number']) ?></span>
                                        <strong><?= date('M d, Y &bull; h:i A', strtotime($enc['visit_date'])) ?></strong>
                                        <span class="badge bg-secondary-subtle text-dark border ms-2"><?= sanitize($enc['encounter_type']) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge <?= ($enc['queue_status'] === 'Completed') ? 'bg-success' : 'bg-warning text-dark' ?>">
                                            <?= sanitize($enc['queue_status']) ?>
                                        </span>
                                        <?php if (!empty($enc['transaction_code'])): ?>
                                            <a href="view_record?encounter_id=<?= (int)$enc['encounter_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold">
                                                <i class="bi bi-printer me-1"></i> Print / PDF
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="text-muted small mb-2">
                                    <strong>Chief Complaint:</strong> <?= sanitize($enc['complaint'] ?: 'Routine checkup') ?>
                                </div>

                                <!-- Triage Vitals (Recorded by Doctor) -->
                                <?php if (!empty($v)): ?>
                                    <div class="p-2 bg-white rounded small mb-2 text-dark border">
                                        <span class="me-3">BP: <strong><?= sanitize($v['bp'] ?? 'N/A') ?></strong></span>
                                        <span class="me-3">Temp: <strong><?= sanitize($v['temp'] ?? 'N/A') ?> °C</strong></span>
                                        <span class="me-3">Weight: <strong><?= sanitize($v['weight'] ?? 'N/A') ?> kg</strong></span>
                                        <span class="me-3">Height: <strong><?= sanitize($v['height'] ?? 'N/A') ?> cm</strong></span>
                                        <?php if (!empty($v['pulse'])): ?><span class="me-3">Pulse: <strong><?= sanitize($v['pulse']) ?> bpm</strong></span><?php endif; ?>
                                        <?php if (!empty($v['resp_rate'])): ?><span>Resp: <strong><?= sanitize($v['resp_rate']) ?> cpm</strong></span><?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Doctor's Clinical Diagnosis -->
                                <?php if (!empty($enc['diagnostic_notes'])): ?>
                                    <div class="p-3 bg-white rounded-3 mb-2 border-start border-4 border-info shadow-sm">
                                        <div class="fw-bold small text-info mb-1">
                                            <i class="bi bi-stethoscope me-1"></i> Attending Physician Assessment & Diagnosis:
                                        </div>
                                        <div class="small text-dark" style="white-space: pre-line;"><?= sanitize($enc['diagnostic_notes']) ?></div>
                                    </div>
                                <?php endif; ?>

                                <!-- Prescribed Medications -->
                                <?php if (!empty($rx)): ?>
                                    <div class="mt-2 small bg-white p-3 rounded-3 border">
                                        <strong class="text-success"><i class="bi bi-capsule me-1"></i> Official Electronic Prescription:</strong>
                                        <ul class="mb-0 mt-2">
                                            <?php foreach ($rx as $m): ?>
                                                <li>
                                                    <strong><?= sanitize($m['medication'] ?? '') ?></strong> &bull;
                                                    <span><?= sanitize($m['dosage'] ?? '') ?></span>
                                                    <?php if (!empty($m['duration'])): ?> &bull; <em><?= sanitize($m['duration']) ?></em><?php endif; ?>
                                                    (Qty: <?= sanitize($m['quantity'] ?? '1') ?>)
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Appointment Booking Modal -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-labelledby="bookModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold" id="bookModalLabel">
                    <i class="bi bi-calendar2-plus me-1"></i> Book an Appointment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="dashboard" method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="book_appointment" value="1">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">Consultation Date</label>
                        <input type="date" name="appointment_date" id="apptDateInput" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" onchange="checkApptDay(this)" required>
                        <small class="text-primary d-block mt-1">
                            <i class="bi bi-calendar-check me-1"></i>Open: <strong>Monday to Friday only</strong> (Closed on Saturdays and Sundays).
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">Chief Complaint / Primary Health Service</label>
                        <textarea name="complaint" id="apptComplaint" class="form-control" rows="3" placeholder="e.g. General Consultation, Immunization, Fever, cough, or select from services below" required></textarea>
                        <div class="mt-2 d-flex flex-wrap gap-1 align-items-center">
                            <span class="small text-muted me-1" style="font-size: 0.75rem;">Health Services:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('apptComplaint').value = 'General Consultation'">General Consultation</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('apptComplaint').value = 'Immunization'">Immunization</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('apptComplaint').value = 'Pediatric Care'">Pediatric Care</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('apptComplaint').value = 'Prenatal Check up'">Prenatal Check up</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="document.getElementById('apptComplaint').value = 'Well-Baby Check up'">Well-Baby Check up</button>
                        </div>
                    </div>

                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        A unique appointment queue ticket will be automatically issued upon confirmation.
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-bold">
                        <i class="bi bi-check-circle me-1"></i> Confirm Appointment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../js/qrcode.min.js"></script>
<script>
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
<?php if ($activeTicket && ($activeTicket['approval_status'] ?? '') === 'Approved'): ?>
<script>
    window.addEventListener('DOMContentLoaded', () => {
        const qrEl = document.getElementById("activeTicketQr");
        if (qrEl) {
            new QRCode(qrEl, {
                text: "<?= 'PAIKIT:APPT:' . sanitize($activeTicket['queue_number']) ?>",
                width: 130,
                height: 130,
                colorDark : "#0f172a",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        }

        // Live Queue Auto-Polling (every 6 seconds)
        const activeEncId = <?= (int)$activeTicket['encounter_id'] ?>;
        function pollQueue() {
            fetch('queue_status_api?encounter_id=' + activeEncId)
                .then(res => res.json())
                .then(data => {
                    if (data && data.success && data.has_active) {
                        const sEl = document.getElementById('liveServingDisplay');
                        const pEl = document.getElementById('livePositionDisplay');
                        const dEl = document.getElementById('liveTrackerDetail');
                        if (sEl && data.serving_number) sEl.textContent = data.serving_number;
                        if (pEl) {
                            if (data.queue_status === 'Serving') {
                                pEl.innerHTML = '<span class="text-success"><i class="bi bi-megaphone-fill"></i> NOW SERVING!</span>';
                                if (dEl) dEl.innerHTML = '<span class="text-success fw-bold"><i class="bi bi-door-open-fill me-1"></i> You are being called! Please proceed to the Doctor\'s consultation room.</span>';
                            } else if (data.position === 1) {
                                pEl.innerHTML = '<span class="text-primary">#1 (YOU ARE NEXT!)</span>';
                                if (dEl) dEl.innerHTML = '<span class="text-primary fw-bold"><i class="bi bi-bell-fill me-1"></i> YOU ARE NEXT IN LINE! Please standby outside the Consultation Room.</span>';
                            } else if (data.position > 1) {
                                pEl.textContent = '#' + data.position + ' in Queue';
                                if (dEl) dEl.innerHTML = '<i class="bi bi-people me-1"></i> There are <strong>' + data.ahead_count + '</strong> patient(s) ahead of you.';
                            }
                        }
                    }
                })
                .catch(() => {});
        }
        setInterval(pollQueue, 6000);
    });
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/settings_modal.php'; ?>
</body>
</html>
