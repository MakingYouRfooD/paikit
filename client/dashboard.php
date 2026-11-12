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
                                // Generate sequential appointment queue number for that specific date (e.g. A-001)
                                $queueNumber = generate_queue_number($pdo, 'A', $appointmentDate);

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

// Handle Appointment Cancellation by Client
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['cancel_appointment'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation token expired. Please try again.";
    } else {
        $cancelId = (int)($_POST['encounter_id'] ?? 0);
        if ($cancelId > 0) {
            $stmtC = $pdo->prepare("UPDATE encounters SET queue_status = 'Cancelled' WHERE encounter_id = ? AND patient_id = ? AND queue_status = 'Waiting'");
            $stmtC->execute([$cancelId, $patientId]);
            audit_log('APPOINTMENT_CANCELLED_BY_PATIENT', "Patient #{$patientId} cancelled appointment Encounter #{$cancelId}.");
            header("Location: dashboard?msg=" . urlencode("Nai-cancel na po ang iyong appointment matagumpay."));
            exit;
        }
    }
}

// Fetch Active Queue Ticket for TODAY ONLY (Do not display if not yet scheduled today)
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
      AND DATE(e.visit_date) = ?
    ORDER BY e.encounter_id ASC 
    LIMIT 1
");
$stmtActive->execute([$patientId, $today]);
$activeTicket = $stmtActive->fetch();

// Fetch Pending & Upcoming Appointments (Future dates or Pending Staff Review)
$stmtPending = $pdo->prepare("
    SELECT e.*, t.transaction_code, u.full_name as reviewer_name 
    FROM encounters e
    LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
    LEFT JOIN users u ON e.reviewed_by = u.user_id
    WHERE e.patient_id = ? 
      AND e.queue_status IN ('Waiting', 'Serving')
      AND e.approval_status != 'Declined'
      AND (DATE(e.visit_date) > ? OR e.approval_status = 'Pending')
    ORDER BY DATE(e.visit_date) ASC, e.encounter_id ASC
");
$stmtPending->execute([$patientId, $today]);
$pendingAppointments = $stmtPending->fetchAll();
$pendingCount = count($pendingAppointments);

// Also fetch recently declined appointment (if any within last 7 days) to explain to the patient
$sevenDaysAgo = date('Y-m-d 00:00:00', strtotime('-7 days'));
$stmtRecentDeclined = $pdo->prepare("
    SELECT e.*, u.full_name as reviewer_name 
    FROM encounters e
    LEFT JOIN users u ON e.reviewed_by = u.user_id
    WHERE e.patient_id = ? 
      AND e.approval_status = 'Declined'
      AND e.visit_date >= ?
    ORDER BY e.reviewed_at DESC, e.encounter_id DESC 
    LIMIT 1
");
$stmtRecentDeclined->execute([$patientId, $sevenDaysAgo]);
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
    ORDER BY DATE(e.visit_date) DESC, e.encounter_id DESC
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

// 5. Visual Stepper Calculation for Patient Journey
$step1Class = 'completed';
$step1Icon = 'bi-check2';
$step2Class = '';
$step2Icon = 'bi-calendar-check';
$step3Class = '';
$step3Icon = 'bi-qr-code-scan';
$step4Class = '';
$step4Icon = 'bi-heart-pulse-fill';
$stepperPercent = 25;

if ($activeTicket) {
    if (($activeTicket['approval_status'] ?? '') === 'Pending') {
        $step1Class = 'active';
        $step1Icon = 'bi-hourglass-split';
        $stepperPercent = 15;
    } elseif (($activeTicket['approval_status'] ?? '') === 'Approved') {
        $step1Class = 'completed';
        $step1Icon = 'bi-check2';
        $step2Class = 'completed';
        $step2Icon = 'bi-check2';
        $stepperPercent = 50;

        if (($activeTicket['check_in_status'] ?? '') === 'Arrived') {
            $step3Class = 'completed';
            $step3Icon = 'bi-check2';
            $stepperPercent = 75;

            if ($activeTicket['queue_status'] === 'Serving') {
                $step4Class = 'active';
                $step4Icon = 'bi-megaphone-fill';
                $stepperPercent = 92;
            } elseif ($activeTicket['queue_status'] === 'Completed') {
                $step4Class = 'completed';
                $step4Icon = 'bi-check2-all';
                $stepperPercent = 100;
            } else {
                $step3Class = 'active';
                $step3Icon = 'bi-broadcast';
            }
        } else {
            $step2Class = 'active';
            $step2Icon = 'bi-calendar-check-fill';
        }
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
    <link rel="stylesheet" href="../css/modern-theme.css?v=<?= time() ?>">
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
        /* Base page background */
        body.client-portal-body {
            background-color: #f1f5f9;
            color: #1e293b;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        }

        /* Hero Banner */
        .patient-hero-luxury {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 45%, #047857 100%);
            border-radius: 24px;
            padding: 2.2rem 2.4rem;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 36px -6px rgba(6, 78, 59, 0.28);
        }
        .patient-hero-luxury::after {
            content: "";
            position: absolute;
            right: -50px;
            bottom: -50px;
            width: 250px;
            height: 250px;
            background: radial-gradient(circle, rgba(167, 243, 208, 0.18) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .hero-glass-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.14);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.22);
            color: #ffffff;
            padding: 0.35rem 0.9rem;
            border-radius: 9999px;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .hero-action-btn-gold {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: #0f172a;
            border: none;
            font-weight: 800;
            transition: all 0.2s ease;
        }
        .hero-action-btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.35);
            color: #0f172a;
        }

        /* Segmented Navigation Tabs */
        .patient-pill-nav {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: #edf2f7;
            padding: 0.5rem 0.65rem;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .patient-pill-nav::-webkit-scrollbar {
            display: none;
        }
        .patient-pill-btn {
            flex: 1 1 auto;
            min-width: max-content;
            padding: 0.7rem 1.15rem;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 14px;
            font-weight: 700;
            font-size: 0.88rem;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            text-decoration: none;
        }
        .patient-pill-btn .badge {
            margin-left: 0.35rem;
            font-size: 0.74rem;
            padding: 0.32em 0.65em;
            letter-spacing: 0.3px;
        }
        .patient-pill-btn:hover {
            color: #065f46;
            background: rgba(255, 255, 255, 0.75);
            border-color: rgba(0, 0, 0, 0.04);
        }
        .patient-pill-btn.active {
            background: #ffffff;
            color: #065f46;
            border-color: rgba(0, 0, 0, 0.05);
            box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
        }
        .patient-pill-btn.btn-pending-nav {
            color: #92400e;
        }
        .patient-pill-btn.btn-pending-nav:hover {
            background: #fef3c7;
            color: #78350f;
            border-color: #fde68a;
        }

        /* Horizontal Workflow Stepper */
        .patient-stepper {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            justify-content: space-between !important;
            position: relative;
            padding: 1rem 0.5rem;
            width: 100%;
        }
        .stepper-progress-bar {
            position: absolute;
            top: 26px;
            left: 10%;
            right: 10%;
            height: 6px;
            background: #e2e8f0;
            z-index: 1;
            border-radius: 9999px;
        }
        .stepper-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 9999px;
            transition: width 0.4s ease;
        }
        .stepper-node {
            position: relative;
            z-index: 2;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            text-align: center;
            width: 85px;
        }
        .stepper-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #ffffff;
            border: 3px solid #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            font-weight: 800;
            color: #64748b;
            transition: all 0.3s ease;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
        }
        .stepper-node.completed .stepper-circle {
            background: #059669;
            border-color: #059669;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
        }
        .stepper-node.active .stepper-circle {
            background: #ffffff;
            border-color: #059669;
            color: #059669;
            box-shadow: 0 0 0 5px rgba(5, 150, 105, 0.2);
            transform: scale(1.1);
        }
        .stepper-label {
            margin-top: 0.5rem;
            font-size: 0.8rem;
            font-weight: 700;
            color: #64748b;
            white-space: nowrap;
        }
        .stepper-node.completed .stepper-label { color: #0f172a; }
        .stepper-node.active .stepper-label { color: #059669; }

        /* Digital Health Pass Ticket Card */
        .digital-health-pass {
            background: #ffffff;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        .pass-ribbon-header {
            background: linear-gradient(135deg, #064e3b 0%, #047857 100%);
            padding: 1rem 1.8rem;
            color: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .ticket-number-display {
            font-size: clamp(3.2rem, 5vw, 4.4rem);
            font-weight: 900;
            color: #047857;
            letter-spacing: -1.5px;
            line-height: 1;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
        }
        .qr-scanner-frame {
            display: inline-block;
            padding: 16px;
            background: #ffffff;
            border-radius: 20px;
            border: 2px dashed #86efac;
            box-shadow: 0 8px 24px -4px rgba(4, 120, 87, 0.12);
        }

        /* Live Queue Radar Box */
        .live-radar-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%);
            border: 1.5px solid #86efac;
            border-radius: 20px;
            padding: 1.4rem;
            position: relative;
        }
        .live-radar-pulse {
            display: inline-block;
            width: 10px;
            height: 10px;
            background-color: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            animation: livePulse 1.8s infinite;
        }
        @keyframes livePulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(34, 197, 94, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }

        /* Calendar Grid */
        .cal-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(80px, 1fr));
            gap: 6px;
            min-width: 620px;
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
            border-radius: 12px;
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

        /* Mobile Bottom Nav Bar (STRICTLY HIDDEN ON DESKTOP) */
        .mobile-patient-nav {
            display: none;
        }
        @media (max-width: 767.98px) {
            .mobile-patient-nav {
                display: flex !important;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                border-top: 1px solid #cbd5e1;
                padding: 0.4rem 0.5rem;
                padding-bottom: max(0.45rem, env(safe-area-inset-bottom));
                z-index: 1040;
                box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
                justify-content: space-around;
            }
            .mobile-nav-link {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                font-size: 0.72rem;
                font-weight: 600;
                color: #64748b;
                text-decoration: none;
                padding: 0.35rem 0.6rem;
                border-radius: 12px;
                transition: all 0.2s ease;
                min-width: 60px;
            }
            .mobile-nav-link i {
                font-size: 1.25rem;
                margin-bottom: 2px;
            }
            .mobile-nav-link.active {
                color: #15803d;
                font-weight: 800;
                background-color: #f0fdf4;
            }
            body.client-portal-body {
                padding-bottom: calc(75px + env(safe-area-inset-bottom)) !important;
            }
        }
    </style>
</head>
<body class="client-portal-body">

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

    <!-- Luxury Patient Welcome & Health Overview Hero Banner -->
    <div class="patient-hero-luxury mb-4 shadow-lg">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="hero-glass-pill">
                        <i class="bi bi-patch-check-fill text-warning"></i> Verified Citizen Patient
                    </span>
                    <span class="hero-glass-pill">
                        <i class="bi bi-geo-alt-fill text-info"></i> <?= sanitize($clientPatient['sitio'] ?: 'Paikit') ?>, Canlubang
                    </span>
                    <span class="hero-glass-pill">
                        <i class="bi bi-calendar-event text-white"></i> <?= date('l, F j, Y') ?>
                    </span>
                </div>
                <h2 class="fw-bold text-white mb-2" style="font-size: clamp(1.6rem, 2.8vw, 2.3rem); letter-spacing: -0.5px;">
                    Magandang Araw, <?= sanitize($clientPatient['full_name']) ?>! 🩺
                </h2>
                <p class="text-white-50 mb-3" style="font-size: 0.95rem; max-width: 640px; line-height: 1.5;">
                    Paikit Health Center Digital Care Hub &bull; Tingnan ang real-time consultation queue, mag-book ng outpatient appointment, at i-access ang iyong official diagnostic records.
                </p>

                <!-- Micro Health Stats Row in Hero -->
                <div class="d-flex flex-wrap gap-2 pt-2 border-top border-white border-opacity-10">
                    <div class="hero-glass-pill py-2 px-3">
                        <i class="bi bi-person-badge-fill text-warning fs-5"></i>
                        <div>
                            <div class="text-white-50" style="font-size: 0.68rem; text-transform: uppercase;">Attending Physician</div>
                            <div class="fw-bold text-white small"><?= sanitize($attendingDoctor['full_name'] ?: 'Dr. Attending Physician') ?></div>
                        </div>
                    </div>
                    <div class="hero-glass-pill py-2 px-3">
                        <i class="bi bi-folder2-open text-info fs-5"></i>
                        <div>
                            <div class="text-white-50" style="font-size: 0.68rem; text-transform: uppercase;">Medical Files</div>
                            <div class="fw-bold text-white small"><?= count($pastEncounters) ?> Past Encounters</div>
                        </div>
                    </div>
                    <div class="hero-glass-pill py-2 px-3">
                        <i class="bi bi-clock-history text-success fs-5"></i>
                        <div>
                            <div class="text-white-50" style="font-size: 0.68rem; text-transform: uppercase;">Clinic Hours</div>
                            <div class="fw-bold text-white small">Mon-Fri 8:00 AM - 5:00 PM</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hero Action Buttons -->
            <div class="col-lg-4 text-lg-end">
                <div class="d-flex flex-column flex-sm-row flex-lg-column gap-2 justify-content-lg-end">
                    <button class="btn hero-action-btn-gold py-2 px-4 rounded-pill shadow-sm d-flex align-items-center justify-content-center gap-2" onclick="switchPatientTab('tab-calendar-btn')">
                        <i class="bi bi-calendar2-plus-fill fs-5"></i>
                        <span>Book an Appointment</span>
                    </button>
                    <button class="btn btn-outline-light py-2 px-4 rounded-pill fw-bold d-flex align-items-center justify-content-center gap-2" data-bs-toggle="modal" data-bs-target="#settingsModal">
                        <i class="bi bi-shield-lock-fill"></i>
                        <span>Account & Security</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Client Segmented Navigation Pills -->
    <div class="patient-pill-nav mb-4" id="patientTabList" role="tablist">
        <button class="patient-pill-btn active" id="tab-pass-btn" data-bs-toggle="tab" data-bs-target="#tabPassPane" type="button" role="tab">
            <i class="bi bi-ticket-perforated-fill text-primary fs-5"></i>
            <span>Today's Pass & Live Queue</span>
            <?php if ($activeTicket): ?>
                <span class="badge bg-warning text-dark rounded-pill font-monospace fw-bold"><?= sanitize($activeTicket['queue_number']) ?></span>
            <?php endif; ?>
        </button>

        <!-- PENDING APPOINTMENTS (ACCESSIBLE ONLY HERE WITH GENEROUS BREATHING ROOM) -->
        <button type="button" class="patient-pill-btn btn-pending-nav" id="tab-pending-btn" data-bs-toggle="modal" data-bs-target="#pendingApptModal" title="Tingnan ang iyong pending o nakatakdang appointment sa hinaharap">
            <i class="bi bi-clock-history text-warning fs-5"></i>
            <span>Pending Appointments</span>
            <?php if ($pendingCount > 0): ?>
                <span class="badge bg-warning text-dark rounded-pill font-monospace fw-bold"><?= $pendingCount ?></span>
            <?php else: ?>
                <span class="badge bg-secondary-subtle text-muted rounded-pill">0</span>
            <?php endif; ?>
        </button>

        <button class="patient-pill-btn" id="tab-calendar-btn" data-bs-toggle="tab" data-bs-target="#tabCalendarPane" type="button" role="tab">
            <i class="bi bi-calendar-check-fill text-success fs-5"></i>
            <span>Book Consultation</span>
        </button>
        <button class="patient-pill-btn" id="tab-records-btn" data-bs-toggle="tab" data-bs-target="#tabRecordsPane" type="button" role="tab">
            <i class="bi bi-journal-medical text-info fs-5"></i>
            <span>My Medical Records</span>
            <span class="badge bg-light text-dark border rounded-pill"><?= count($pastEncounters) ?></span>
        </button>
        <button class="patient-pill-btn" id="tab-profile-btn" data-bs-toggle="tab" data-bs-target="#tabProfilePane" type="button" role="tab">
            <i class="bi bi-person-badge-fill text-secondary fs-5"></i>
            <span>My Patient Profile</span>
        </button>
    </div>

    <!-- Tab Content -->
    <div class="tab-content" id="patientTabContent">

        <!-- TAB 1: TODAY'S PASS & LIVE QUEUE TRACKER -->
        <div class="tab-pane fade show active" id="tabPassPane" role="tabpanel">
            <?php if ($activeTicket): ?>
                <?php 
                    $isPendingApproval = (($activeTicket['approval_status'] ?? '') === 'Pending');
                    $isApproved = (($activeTicket['approval_status'] ?? '') === 'Approved');
                ?>
                <!-- Digital Medical Boarding Pass -->
                <div class="digital-health-pass mb-4">
                    <!-- Pass Ribbon Header -->
                    <div class="pass-ribbon-header">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-white text-success fw-bold px-3 py-1 rounded-pill small">
                                <i class="bi bi-shield-check me-1"></i> PAIKIT HEALTH CENTER
                            </span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">
                                <?= sanitize($activeTicket['encounter_type']) ?> Pass
                            </span>
                        </div>
                        <div>
                            <?php if ($isPendingApproval): ?>
                                <span class="badge bg-warning text-dark px-3 py-1 rounded-pill fw-bold">
                                    <i class="bi bi-hourglass-split me-1"></i> PENDING STAFF REVIEW
                                </span>
                            <?php else: ?>
                                <span class="badge bg-light text-success px-3 py-1 rounded-pill fw-bold">
                                    <i class="bi bi-check-circle-fill me-1"></i> APPROVED & ACTIVE
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="p-4 bg-white">
                        <!-- Visual 4-Stage Stepper -->
                        <div class="patient-stepper mb-4">
                            <div class="stepper-progress-bar">
                                <div class="stepper-progress-fill" style="width: <?= $stepperPercent ?>%;"></div>
                            </div>
                            <div class="stepper-node <?= $step1Class ?>">
                                <div class="stepper-circle"><i class="bi <?= $step1Icon ?>"></i></div>
                                <div class="stepper-label">1. Request</div>
                            </div>
                            <div class="stepper-node <?= $step2Class ?>">
                                <div class="stepper-circle"><i class="bi <?= $step2Icon ?>"></i></div>
                                <div class="stepper-label">2. Approved</div>
                            </div>
                            <div class="stepper-node <?= $step3Class ?>">
                                <div class="stepper-circle"><i class="bi <?= $step3Icon ?>"></i></div>
                                <div class="stepper-label">3. Check-in</div>
                            </div>
                            <div class="stepper-node <?= $step4Class ?>">
                                <div class="stepper-circle"><i class="bi <?= $step4Icon ?>"></i></div>
                                <div class="stepper-label">4. Consult</div>
                            </div>
                        </div>

                        <div class="row g-4 align-items-center">
                            <!-- Left: Giant Ticket Number, QR Pass & Codes -->
                            <div class="col-lg-5 text-center border-end-lg">
                                <div class="small fw-bold text-uppercase text-muted" style="letter-spacing: 1px;">YOUR QUEUE PASS</div>
                                <div class="ticket-number-display my-1">
                                    <?= sanitize($activeTicket['queue_number']) ?>
                                </div>
                                <div class="text-secondary small mb-3">
                                    <i class="bi bi-calendar-check text-primary me-1"></i> Date: <strong><?= date('l, F j, Y', strtotime($activeTicket['visit_date'])) ?></strong>
                                    <br>
                                    <i class="bi bi-chat-left-text text-danger me-1"></i> <?= sanitize($activeTicket['complaint'] ?: 'General Outpatient Care') ?>
                                </div>

                                <!-- QR Pass with Scanner Targeting Corners -->
                                <?php if ($isPendingApproval): ?>
                                    <div class="p-3 bg-light rounded-4 text-center text-muted small mb-3 border">
                                        <i class="bi bi-qr-code fs-1 d-block mb-1 text-secondary opacity-50"></i>
                                        <span class="fw-bold d-block text-dark">QR Check-in Pass Standby</span>
                                        <span class="text-muted" style="font-size: 0.78rem;">Activates automatically once verified by Clinic Staff</span>
                                    </div>
                                <?php else: ?>
                                    <div class="my-3">
                                        <div class="qr-scanner-frame">
                                            <div id="activeTicketQr"></div>
                                        </div>
                                    </div>
                                    <div class="small text-muted mb-2" style="font-size: 0.78rem;">
                                        <i class="bi bi-upc-scan text-success me-1"></i> Show this QR to Clinic Staff Desk to Check-In
                                    </div>
                                <?php endif; ?>

                                <!-- Transaction Code Pill with Copy -->
                                <div class="p-2 bg-light rounded-pill border d-inline-flex align-items-center gap-2 px-3 mb-2 shadow-sm">
                                    <span class="text-muted small" style="font-size: 0.72rem;">SLIP CODE:</span>
                                    <strong class="text-dark font-monospace fs-6"><?= sanitize($activeTicket['transaction_code'] ?: 'PK-ONLINE') ?></strong>
                                </div>

                                <div>
                                    <a href="view_record?encounter_id=<?= (int)$activeTicket['encounter_id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill px-4 fw-bold shadow-sm">
                                        <i class="bi bi-printer-fill me-1"></i> View / Print Digital Slip
                                    </a>
                                </div>
                            </div>

                            <!-- Right: Live Radar Command HUD / Status Card -->
                            <div class="col-lg-7">
                                <?php if ($isPendingApproval): ?>
                                    <div class="card border-0 rounded-4 p-4 shadow-sm" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 5px solid #f59e0b !important;">
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <div class="rounded-circle bg-warning text-white d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; flex-shrink: 0;">
                                                <i class="bi bi-hourglass-top fs-4"></i>
                                            </div>
                                            <div>
                                                <h5 class="fw-bold text-dark mb-0">Under Staff Verification</h5>
                                                <span class="text-muted small">Appointment Review in Progress</span>
                                            </div>
                                        </div>
                                        <p class="text-secondary small mb-3">
                                            Ang iyong appointment request para sa <strong><?= date('l, F j, Y', strtotime($activeTicket['visit_date'])) ?></strong> ay sinusuri na ng Staff Desk para sa daily patient quota at schedule ng doktor.
                                        </p>
                                        <div class="p-3 bg-white rounded-3 border small text-muted">
                                            <i class="bi bi-bell-fill text-warning me-1"></i> Makatatanggap ka ng update sa portal at email kapag naaprubahan na ang iyong ticket pass.
                                        </div>
                                    </div>
                                <?php elseif (!$isTicketToday): ?>
                                    <div class="card border-0 rounded-4 p-4 shadow-sm" style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-left: 5px solid #15803d !important;">
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; flex-shrink: 0;">
                                                <i class="bi bi-calendar-check-fill fs-4"></i>
                                            </div>
                                            <div>
                                                <h5 class="fw-bold text-dark mb-0">Confirmed Appointment</h5>
                                                <span class="text-success small fw-semibold">Schedule is locked in</span>
                                            </div>
                                        </div>
                                        <p class="text-secondary small mb-3">
                                            Nakatakda ang iyong konsultasyon sa darating na <strong><?= date('l, F j, Y', strtotime($activeTicket['visit_date'])) ?></strong>.
                                        </p>
                                        <div class="p-3 bg-white rounded-3 border small text-muted">
                                            <i class="bi bi-geo-alt-fill text-danger me-1"></i> Sa mismong araw ng appointment, dalhin ang iyong mobile device at ipakita ang QR Pass sa Staff Desk pagdating sa health center.
                                        </div>
                                    </div>
                                <?php elseif (($activeTicket['check_in_status'] ?? '') === 'Booked'): ?>
                                    <div class="card border-0 rounded-4 p-4 shadow-sm" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-left: 5px solid #2563eb !important;">
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; flex-shrink: 0;">
                                                <i class="bi bi-qr-code-scan fs-4"></i>
                                            </div>
                                            <div>
                                                <h5 class="fw-bold text-dark mb-0">Ready for Clinic Check-in!</h5>
                                                <span class="text-primary small fw-semibold">Consultation scheduled today</span>
                                            </div>
                                        </div>
                                        <p class="text-secondary small mb-3">
                                            Nandito na ang iyong schedule ngayong araw! Mangyaring magtungo sa Health Center Staff Desk upang i-scan ang iyong QR Pass.
                                        </p>
                                        <div class="p-3 bg-white rounded-3 border small text-muted">
                                            <i class="bi bi-broadcast text-danger me-1"></i> Pagkatapos ma-scan, awtomatiko kang mapapasama sa active queue list ng doktor.
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <!-- High-Tech Live Radar Command HUD -->
                                    <div class="live-radar-command shadow-lg mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="live-radar-pulse"></span>
                                                <span class="badge bg-success text-white px-3 py-1 rounded-pill small fw-bold">
                                                    <i class="bi bi-broadcast me-1"></i> LIVE CLINIC RADAR ACTIVE
                                                </span>
                                            </div>
                                            <small class="text-white-50" style="font-size: 0.75rem;">Auto-refreshes every 6s</small>
                                        </div>

                                        <div class="row g-3 text-center my-2">
                                            <div class="col-6 border-end border-white border-opacity-10">
                                                <div class="text-white-50 small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 1px;">DOCTOR NOW CALLING</div>
                                                <div class="fs-1 fw-bold text-warning font-monospace" id="liveServingDisplay" style="text-shadow: 0 0 15px rgba(245, 158, 11, 0.5);">
                                                    <?= sanitize($liveQueueInfo['serving_number'] ?? 'Standby') ?>
                                                </div>
                                                <small class="text-white-50">Room 1 &bull; Dr. <?= sanitize($attendingDoctor['full_name']) ?></small>
                                            </div>
                                            <div class="col-6">
                                                <div class="text-white-50 small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 1px;">YOUR LIVE POSITION</div>
                                                <div class="fs-2 fw-bold text-white" id="livePositionDisplay">
                                                    <?php if ($activeTicket['queue_status'] === 'Serving'): ?>
                                                        <span class="badge bg-danger text-white fs-4"><i class="bi bi-megaphone-fill"></i> CALLING YOU!</span>
                                                    <?php elseif ($liveQueueInfo && $liveQueueInfo['position'] !== null): ?>
                                                        #<?= (int)$liveQueueInfo['position'] ?> in queue
                                                    <?php else: ?>
                                                        In Live Queue
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-white-50">Fair 2:1 Interleaved Queue</small>
                                            </div>
                                        </div>

                                        <div class="p-3 rounded-3 border border-white border-opacity-20 text-center mt-3 text-white fw-semibold small" id="liveTrackerDetail" style="background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(8px);">
                                            <?php if ($activeTicket['queue_status'] === 'Serving'): ?>
                                                <span class="text-warning fs-6"><i class="bi bi-door-open-fill me-1"></i> YOU ARE BEING CALLED! Mangyaring pumasok na sa consultation room ng doktor.</span>
                                            <?php elseif ($liveQueueInfo && $liveQueueInfo['position'] === 1): ?>
                                                <span class="text-warning fs-6"><i class="bi bi-bell-fill me-1"></i> IKAW NA ANG SUSUNOD! Mangyaring mag-standby sa tapat ng silid ng doktor.</span>
                                            <?php elseif ($liveQueueInfo && $liveQueueInfo['position'] !== null): ?>
                                                <span><i class="bi bi-people-fill text-warning me-1"></i> Mayroong <strong><?= (int)$liveQueueInfo['ahead_count'] ?></strong> pasyente sa unahan mo. Maaari kang maghintay nang komportable.</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Clinic Hours Reminder -->
                                <div class="card border-0 bg-light p-3 rounded-4 mt-3">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-info-circle-fill text-primary"></i>
                                        <strong class="text-dark small">Barangay Canlubang Clinic Policy:</strong>
                                    </div>
                                    <div class="text-muted small" style="font-size: 0.8rem;">
                                        Bukas ang health center mula Lunes hanggang Biyernes (8:00 AM - 5:00 PM). Priyoridad ang mga Senior Citizens, Buntis, PWD, at Emergency cases.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- No Active Ticket State -->
                <?php if (!empty($pendingAppointments)): ?>
                    <div class="card border-0 bg-success-subtle text-success-emphasis p-4 rounded-4 mb-4 shadow-sm border-start border-success border-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <span class="badge bg-success text-white px-3 py-1 rounded-pill small mb-2 fw-semibold">
                                    <i class="bi bi-calendar2-check-fill me-1"></i> May Nakatakdang Konsultasyon
                                </span>
                                <h5 class="fw-bold text-dark mb-1">
                                    Ticket #<?= sanitize($pendingAppointments[0]['queue_number']) ?> &bull; <?= date('l, F j, Y', strtotime($pendingAppointments[0]['visit_date'])) ?>
                                </h5>
                                <p class="small text-secondary mb-0">
                                    Hindi mo pa araw ng iskedyul ngayon (<?= date('l, F j, Y') ?>). Nakatakda ang iyong susunod na appointment sa <strong><?= date('l, F j, Y', strtotime($pendingAppointments[0]['visit_date'])) ?></strong>.
                                </p>
                            </div>
                            <button type="button" class="btn btn-success fw-bold px-4 py-2 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#pendingApptModal">
                                <i class="bi bi-clock-history me-1"></i> Tingnan ang Appointment (<?= $pendingCount ?>)
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm rounded-4 bg-white p-5 text-center mb-4">
                    <div class="rounded-circle bg-success-subtle text-success mx-auto mb-3 d-flex align-items-center justify-content-center shadow-sm" style="width: 86px; height: 86px;">
                        <i class="bi bi-ticket-perforated-fill fs-1"></i>
                    </div>
                    <h3 class="fw-bold text-dark mb-2">No Active Queue Ticket Today</h3>
                    <p class="text-secondary small max-w-md mx-auto mb-4" style="max-width: 520px; font-size: 0.92rem;">
                        Wala kang aktibong appointment o queue pass ngayong araw (<?= date('F j, Y') ?>). Magiging aktibo lamang ang iyong queue pass sa mismong araw ng iyong nakatakdang iskedyul.
                    </p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <?php if (!empty($pendingAppointments)): ?>
                            <button type="button" class="btn btn-warning px-4 py-2 rounded-pill fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#pendingApptModal">
                                <i class="bi bi-clock-history me-1"></i> View Pending Appointments (<?= $pendingCount ?>)
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-primary px-4 py-2 rounded-pill fw-bold shadow-sm" onclick="switchPatientTab('tab-calendar-btn')">
                            <i class="bi bi-calendar-plus me-1"></i> Book an Appointment Now
                        </button>
                        <button type="button" class="btn btn-outline-secondary px-4 py-2 rounded-pill fw-semibold" onclick="switchPatientTab('tab-records-btn')">
                            <i class="bi bi-journal-medical me-1"></i> Review Past Medical Records
                        </button>
                    </div>

                    <?php if ($recentDeclined): ?>
                        <div class="alert alert-danger border-0 rounded-4 text-start small mt-4 p-3 border-start border-danger border-4 shadow-sm">
                            <strong class="d-block text-danger mb-1"><i class="bi bi-calendar-x me-1"></i> Previous Appointment Notice (<?= date('M j, Y', strtotime($recentDeclined['visit_date'])) ?>):</strong>
                            <div><strong>Reason:</strong> <?= sanitize($recentDeclined['decline_reason'] ?: 'Capacity full or physician unavailable') ?></div>
                            <div class="text-muted mt-1" style="font-size: 0.78rem;">You may select another available weekday from the consultation calendar.</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 2: BOOK CONSULTATION / CALENDAR -->
        <div class="tab-pane fade" id="tabCalendarPane" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 bg-white mb-4 overflow-hidden">
                <!-- Header with Month Switcher -->
                <div class="card-header bg-white border-bottom py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-calendar3 text-primary"></i> Consultation Appointment Calendar
                        </h5>
                        <small class="text-muted">Pindutin ang anumang kulay berdeng petsa upang i-reserve ang iyong appointment.</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm shadow-sm">
                            <a href="dashboard?cal_month=<?= $prevMonthParam ?>&tab=calendar" class="btn btn-outline-secondary px-2" title="Previous Month">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <span class="btn btn-light fw-bold text-dark px-3" style="cursor: default;">
                                <?= $monthLabel ?>
                            </span>
                            <a href="dashboard?cal_month=<?= $nextMonthParam ?>&tab=calendar" class="btn btn-outline-secondary px-2" title="Next Month">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn btn-primary btn-sm fw-bold shadow-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#bookModal">
                                <i class="bi bi-calendar-plus me-1"></i> Book Appointment
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-body p-3 p-md-4">
                    <!-- Senior-Friendly Color Legend -->
                    <div class="d-flex flex-wrap align-items-center gap-3 mb-3 p-3 bg-light rounded-4 small border">
                        <span class="fw-bold text-dark"><i class="bi bi-info-circle text-primary me-1"></i> LEGEND:</span>
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
                    <div class="cal-wrapper">
                    <div class="cal-grid">
                        <div class="cal-header-cell">Sun</div>
                        <div class="cal-header-cell">Mon</div>
                        <div class="cal-header-cell">Tue</div>
                        <div class="cal-header-cell">Wed</div>
                        <div class="cal-header-cell">Thu</div>
                        <div class="cal-header-cell">Fri</div>
                        <div class="cal-header-cell">Sat</div>

                        <?php
                        for ($blank = 0; $blank < $firstDayOfWeek; $blank++): ?>
                            <div class="cal-day-cell is-empty"></div>
                        <?php endfor; ?>

                        <?php
                        for ($day = 1; $day <= $daysInMonth; $day++):
                            $curDateStr = sprintf('%s-%02d', $calMonthParam, $day);
                            $curTs = strtotime($curDateStr);
                            $curDayOfWeek = (int)date('w', $curTs);
                            $isWeekend = ($curDayOfWeek === 0 || $curDayOfWeek === 6);
                            $isPast = ($curDateStr < $today);
                            $isToday = ($curDateStr === $today);

                            $docSched = $calDocSchedules[$curDateStr] ?? null;
                            $docAvailable = $docSched ? (int)$docSched['is_available'] : 1;
                            $docNotes = $docSched ? $docSched['notes'] : '';
                            $docMaxSlots = $docSched ? (int)$docSched['max_slots'] : 50;

                            $wkDayNum = (int)date('N', $curTs);
                            $wkSetting = $calWeeklySettings[$wkDayNum] ?? null;
                            $clinicDayAvailable = $wkSetting ? (int)$wkSetting['is_available'] : 1;

                            $patAppts = $calPatientBookings[$curDateStr] ?? [];
                            $hasPatientAppt = !empty($patAppts);

                            $bookedCount = $calTotalBookings[$curDateStr] ?? 0;
                            $isQuotaFull = ($bookedCount >= $docMaxSlots);

                            $cellClass = '';
                            $badgeText = '';
                            $badgeClass = '';
                            $canBook = false;

                            if ($isWeekend) {
                                $cellClass = 'is-weekend';
                                $badgeText = 'Closed';
                                $badgeClass = 'bg-secondary-subtle text-secondary';
                            } elseif ($isPast) {
                                $cellClass = 'is-past';
                                $badgeText = 'Past';
                                $badgeClass = 'bg-light text-muted border';
                            } elseif ($hasPatientAppt) {
                                $cellClass = 'is-booked';
                                $badgeText = 'Booked';
                                $badgeClass = 'bg-primary text-white';
                            } elseif (!$clinicDayAvailable) {
                                $cellClass = 'is-unavailable';
                                $badgeText = 'No Clinic';
                                $badgeClass = 'bg-danger-subtle text-danger';
                            } elseif (!$docAvailable) {
                                $cellClass = 'is-unavailable';
                                $badgeText = 'Doc Leave';
                                $badgeClass = 'bg-danger text-white';
                            } elseif ($isQuotaFull) {
                                $cellClass = 'is-unavailable';
                                $badgeText = 'Full (50)';
                                $badgeClass = 'bg-warning text-dark';
                            } else {
                                $cellClass = 'is-available';
                                $remSlots = max(0, $docMaxSlots - $bookedCount);
                                $badgeText = "Open ({$remSlots})";
                                $badgeClass = 'bg-success text-white';
                                $canBook = true;
                            }
                        ?>
                            <div class="cal-day-cell <?= $cellClass ?>" 
                                 <?= $canBook ? "onclick=\"openBookingModalForDate('{$curDateStr}')\"" : '' ?>
                                 title="<?= $curDateStr ?> - <?= $badgeText ?><?= !empty($docNotes) ? ' (' . sanitize($docNotes) . ')' : '' ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="cal-day-num <?= $isToday ? 'text-primary' : '' ?>"><?= $day ?></span>
                                    <?php if ($isToday): ?>
                                        <span class="badge bg-primary text-white" style="font-size: 0.65rem; padding: 2px 4px;">Today</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-auto">
                                    <span class="badge <?= $badgeClass ?> w-100 text-truncate" style="font-size: 0.72rem;">
                                        <?= $badgeText ?>
                                    </span>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: MY MEDICAL RECORDS & PRESCRIPTIONS -->
        <div class="tab-pane fade" id="tabRecordsPane" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2 border-bottom pb-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-primary px-3 py-1 rounded-pill small">Digital Health Passport</span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill small">RA 10173 Encrypted</span>
                        </div>
                        <h4 class="fw-bold text-dark mb-0">
                            <i class="bi bi-journal-medical text-primary me-2"></i> Clinical Encounters & Official E-Prescriptions
                        </h4>
                        <small class="text-muted">Kompletong talaan ng iyong vital signs, assessment ng doktor, at opisyal na reseta.</small>
                    </div>
                    <button class="btn btn-primary btn-sm fw-bold shadow-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#bookModal">
                        <i class="bi bi-calendar-plus me-1"></i> New Consultation
                    </button>
                </div>

                <?php if (empty($pastEncounters)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                        <h6 class="fw-bold">No medical records on file yet</h6>
                        <p class="small mb-0">Your future outpatient consultations, clinic checkups, and electronic prescriptions will automatically appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($pastEncounters as $enc): ?>
                            <?php 
                                $v = !empty($enc['vital_signs']) ? json_decode($enc['vital_signs'], true) : [];
                                $rx = !empty($enc['medication_details']) ? json_decode($enc['medication_details'], true) : [];
                            ?>
                            <div class="card border-0 shadow-sm rounded-4 bg-white border border-light overflow-hidden">
                                <!-- Card Header -->
                                <div class="p-3 bg-light border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="badge bg-primary fs-6 font-monospace">Ticket #<?= sanitize($enc['queue_number']) ?></span>
                                        <strong class="text-dark fs-6"><?= date('F j, Y', strtotime($enc['visit_date'])) ?> &bull; <?= date('h:i A', strtotime($enc['visit_date'])) ?></strong>
                                        <span class="badge bg-secondary-subtle text-dark border"><?= sanitize($enc['encounter_type']) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge <?= ($enc['queue_status'] === 'Completed') ? 'bg-success' : 'bg-warning text-dark' ?>">
                                            <?= sanitize($enc['queue_status']) ?>
                                        </span>
                                        <?php if (!empty($enc['transaction_code'])): ?>
                                            <a href="view_record?encounter_id=<?= (int)$enc['encounter_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold rounded-pill px-3">
                                                <i class="bi bi-printer me-1"></i> Print / PDF Slip
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="p-4">
                                    <div class="text-muted small mb-3">
                                        <strong class="text-dark"><i class="bi bi-chat-left-text me-1 text-primary"></i> Chief Complaint:</strong> <?= sanitize($enc['complaint'] ?: 'Routine outpatient checkup') ?>
                                    </div>

                                    <!-- Biometric Vitals Tiles -->
                                    <?php if (!empty($v)): ?>
                                        <div class="mb-3">
                                            <div class="small fw-bold text-muted mb-2 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                                <i class="bi bi-activity text-danger me-1"></i> Recorded Vital Signs Snapshot
                                            </div>
                                            <div class="row g-2">
                                                <div class="col-md-2 col-4">
                                                    <div class="vitals-tile text-center">
                                                        <span class="text-muted d-block small" style="font-size: 0.7rem;">Blood Pressure</span>
                                                        <strong class="text-dark fs-6"><?= sanitize($v['bp'] ?? 'N/A') ?></strong>
                                                    </div>
                                                </div>
                                                <div class="col-md-2 col-4">
                                                    <div class="vitals-tile text-center">
                                                        <span class="text-muted d-block small" style="font-size: 0.7rem;">Temperature</span>
                                                        <strong class="text-dark fs-6"><?= sanitize($v['temp'] ?? 'N/A') ?> °C</strong>
                                                    </div>
                                                </div>
                                                <div class="col-md-2 col-4">
                                                    <div class="vitals-tile text-center">
                                                        <span class="text-muted d-block small" style="font-size: 0.7rem;">Weight</span>
                                                        <strong class="text-dark fs-6"><?= sanitize($v['weight'] ?? 'N/A') ?> kg</strong>
                                                    </div>
                                                </div>
                                                <div class="col-md-2 col-4">
                                                    <div class="vitals-tile text-center">
                                                        <span class="text-muted d-block small" style="font-size: 0.7rem;">Height</span>
                                                        <strong class="text-dark fs-6"><?= sanitize($v['height'] ?? 'N/A') ?> cm</strong>
                                                    </div>
                                                </div>
                                                <div class="col-md-2 col-4">
                                                    <div class="vitals-tile text-center">
                                                        <span class="text-muted d-block small" style="font-size: 0.7rem;">Pulse Rate</span>
                                                        <strong class="text-dark fs-6"><?= sanitize($v['pulse'] ?? 'N/A') ?> bpm</strong>
                                                    </div>
                                                </div>
                                                <div class="col-md-2 col-4">
                                                    <div class="vitals-tile text-center">
                                                        <span class="text-muted d-block small" style="font-size: 0.7rem;">Resp. Rate</span>
                                                        <strong class="text-dark fs-6"><?= sanitize($v['resp_rate'] ?? 'N/A') ?> cpm</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Doctor's Diagnostic Assessment -->
                                    <?php if (!empty($enc['diagnostic_notes'])): ?>
                                        <div class="p-3 bg-light rounded-3 mb-3 border-start border-4 border-info shadow-sm">
                                            <div class="fw-bold small text-primary mb-1">
                                                <i class="bi bi-stethoscope me-1"></i> Attending Physician Assessment & Clinical Diagnosis:
                                            </div>
                                            <div class="small text-dark" style="white-space: pre-line;"><?= sanitize($enc['diagnostic_notes']) ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Prescribed Medications -->
                                    <?php if (!empty($rx)): ?>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <div class="mb-2">
                                                <strong class="text-success"><i class="bi bi-capsule me-1"></i> Official Electronic Prescription:</strong>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered bg-white small mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Medication Name</th>
                                                            <th>Dosage / Instructions</th>
                                                            <th>Duration</th>
                                                            <th class="text-center">Quantity</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($rx as $m): ?>
                                                            <tr>
                                                                <td class="fw-bold text-primary"><?= sanitize($m['medication'] ?? '') ?></td>
                                                                <td><?= sanitize($m['dosage'] ?? '--') ?></td>
                                                                <td><?= sanitize($m['duration'] ?? '--') ?></td>
                                                                <td class="text-center fw-bold"><?= sanitize($m['quantity'] ?? '1') ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 4: MY PATIENT PROFILE -->
        <div class="tab-pane fade" id="tabProfilePane" role="tabpanel">
            <div class="card border-0 shadow-lg rounded-4 mb-4 bg-white overflow-hidden" style="max-width: 680px; margin: 0 auto;">
                <div class="p-4 text-center" style="background: linear-gradient(135deg, #064e3b 0%, #047857 100%); color: white;">
                    <div class="mx-auto mb-3 d-flex align-items-center justify-content-center shadow-lg" style="width: 88px; height: 88px; border-radius: 28px; background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); border: 3px solid rgba(255, 255, 255, 0.4);">
                        <i class="bi bi-person-fill text-white" style="font-size: 3rem;"></i>
                    </div>
                    <h3 class="fw-bold text-white mb-1"><?= sanitize($clientPatient['full_name']) ?></h3>
                    <div class="d-flex justify-content-center gap-1 mb-2">
                        <span class="badge bg-warning text-dark rounded-pill fw-bold">
                            <i class="bi bi-patch-check-fill me-1"></i>Verified Resident Patient
                        </span>
                        <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25 rounded-pill font-monospace">
                            P-<?= str_pad($clientPatient['patient_id'], 5, '0', STR_PAD_LEFT) ?>
                        </span>
                    </div>
                </div>

                <div class="p-4 bg-white">
                    <div class="p-3 mb-2 bg-light rounded-3 d-flex justify-content-between align-items-center">
                        <span class="text-muted"><i class="bi bi-telephone text-primary me-2"></i>Mobile Contact:</span>
                        <strong class="text-dark fs-6"><?= sanitize($clientPatient['mobile_number'] ?? 'N/A') ?></strong>
                    </div>
                    <div class="p-3 mb-2 bg-light rounded-3 d-flex justify-content-between align-items-center">
                        <span class="text-muted"><i class="bi bi-envelope text-primary me-2"></i>Email Address:</span>
                        <strong class="text-dark text-truncate" style="max-width: 250px;" title="<?= sanitize($clientPatient['email'] ?? '') ?>"><?= sanitize($clientPatient['email'] ?? 'N/A') ?></strong>
                    </div>
                    <div class="p-3 mb-2 bg-light rounded-3">
                        <div class="text-muted small mb-1"><i class="bi bi-geo-alt-fill text-danger me-2"></i>Registered Catchment Address:</div>
                        <strong class="text-dark fs-6 d-block">
                            <?= sanitize($clientPatient['sitio'] ?? 'Paikit') ?><?= !empty($clientPatient['street_address']) ? ' (' . sanitize($clientPatient['street_address']) . ')' : '' ?>, Canlubang, Calamba City
                        </strong>
                    </div>
                    <div class="p-3 mb-2 bg-light rounded-3 d-flex justify-content-between align-items-center">
                        <span class="text-muted"><i class="bi bi-cake2 text-warning me-2"></i>Birthdate & Gender:</span>
                        <strong class="text-dark"><?= sanitize($clientPatient['birth_date'] ?? 'N/A') ?> (<?= sanitize($clientPatient['gender'] ?? 'N/A') ?>)</strong>
                    </div>
                    <div class="mt-3 p-3 bg-success-subtle text-success rounded-3 small border border-success-subtle">
                        <i class="bi bi-shield-check me-1"></i> <strong>RA 10173 Security Notice:</strong> Medical records and demographic information are stored with clinical cryptography.
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-primary w-100 rounded-pill fw-bold shadow-sm py-2" data-bs-toggle="modal" data-bs-target="#settingsModal">
                            <i class="bi bi-gear-fill me-1 text-warning"></i> Account Settings & Password
                        </button>
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-2" onclick="toggleSeniorMode()" title="Toggle larger text for seniors">
                            <strong>A+</strong>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- End tab-content -->

    <!-- Mobile Sticky Bottom Navigation Bar (Thumb Friendly) -->
    <nav class="mobile-patient-nav">
        <a href="javascript:void(0)" class="mobile-nav-link active" id="mob-pass-btn" onclick="switchPatientTab('tab-pass-btn')">
            <i class="bi bi-ticket-perforated-fill"></i>
            <span>Queue</span>
        </a>
        <a href="javascript:void(0)" class="mobile-nav-link" id="mob-calendar-btn" onclick="switchPatientTab('tab-calendar-btn')">
            <i class="bi bi-calendar-plus-fill"></i>
            <span>Book</span>
        </a>
        <a href="javascript:void(0)" class="mobile-nav-link" id="mob-records-btn" onclick="switchPatientTab('tab-records-btn')">
            <i class="bi bi-journal-medical"></i>
            <span>Records</span>
        </a>
        <a href="javascript:void(0)" class="mobile-nav-link" id="mob-profile-btn" onclick="switchPatientTab('tab-profile-btn')">
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>
    </nav>
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

<!-- PENDING & UPCOMING APPOINTMENTS MODAL -->
<div class="modal fade" id="pendingApptModal" tabindex="-1" aria-labelledby="pendingApptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-light border-bottom py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-warning-subtle text-warning p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="bi bi-clock-history fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="pendingApptModalLabel">My Pending & Upcoming Appointments</h5>
                        <small class="text-muted">Mga nakatakda o pending na konsultasyon sa klinika</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <?php if (empty($pendingAppointments)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-calendar-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                        <h6 class="fw-bold">Walang Pending o Darating na Appointment</h6>
                        <p class="small mb-3">Wala kang kasalukuyang nakabinbin o nakatakdang appointment sa mga susunod na araw.</p>
                        <button type="button" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold shadow-sm" data-bs-dismiss="modal" onclick="switchPatientTab('tab-calendar-btn')">
                            <i class="bi bi-calendar-plus me-1"></i> Mag-book ng Appointment
                        </button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info border-0 rounded-3 small mb-3">
                        <i class="bi bi-info-circle-fill me-1 text-primary"></i>
                        <strong>Paunawa:</strong> Magiging aktibo ang iyong <strong>Live Queue Boarding Pass</strong> sa mismong araw ng iyong nakatakdang iskedyul. Dalhin ang iyong QR Code o Slip Code sa araw ng iyong konsultasyon.
                    </div>

                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($pendingAppointments as $pa): ?>
                            <?php 
                                $isPendingReview = ($pa['approval_status'] === 'Pending');
                                $visitTs = strtotime($pa['visit_date']);
                                $dayDiff = (int)ceil(($visitTs - time()) / 86400);
                                $diffLabel = ($dayDiff > 0) ? "Sa darating na {$dayDiff} araw" : "Nakatakda";
                            ?>
                            <div class="card border rounded-4 shadow-sm overflow-hidden bg-white">
                                <div class="p-3 bg-light border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary fs-6 font-monospace px-3 py-1">Ticket #<?= sanitize($pa['queue_number']) ?></span>
                                        <span class="badge bg-light text-dark border"><?= sanitize($pa['encounter_type']) ?></span>
                                        <span class="badge bg-info-subtle text-info border small"><?= $diffLabel ?></span>
                                    </div>
                                    <div>
                                        <?php if ($isPendingReview): ?>
                                            <span class="badge bg-warning text-dark px-3 py-1 rounded-pill fw-bold">
                                                <i class="bi bi-hourglass-split me-1"></i> Pending Staff Review
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success px-3 py-1 rounded-pill fw-bold">
                                                <i class="bi bi-check-circle-fill me-1"></i> Approved & Confirmed
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="p-4">
                                    <div class="row align-items-center g-3">
                                        <div class="col-md-7">
                                            <div class="mb-2">
                                                <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem;">Nakatakdang Petsa:</div>
                                                <div class="fs-5 fw-bold text-dark">
                                                    <i class="bi bi-calendar2-event text-primary me-1"></i> <?= date('l, F j, Y', $visitTs) ?>
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem;">Chief Complaint / Health Service:</div>
                                                <div class="fw-semibold text-secondary">
                                                    <?= sanitize($pa['complaint'] ?: 'General Outpatient Consultation') ?>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="text-muted small">Transaction Slip Code: </span>
                                                <code class="fw-bold text-primary"><?= sanitize($pa['transaction_code'] ?: 'Auto-Generated') ?></code>
                                            </div>
                                        </div>
                                        <div class="col-md-5 text-md-end">
                                            <div class="d-flex flex-column gap-2">
                                                <?php if (!empty($pa['transaction_code'])): ?>
                                                    <a href="view_record?encounter_id=<?= (int)$pa['encounter_id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill fw-semibold">
                                                        <i class="bi bi-printer me-1"></i> View / Print Digital Slip
                                                    </a>
                                                <?php endif; ?>
                                                <form action="dashboard" method="POST" onsubmit="return confirm('Sigurado ka bang nais mong kanselahin ang appointment na ito para sa <?= addslashes(date('F j, Y', $visitTs)) ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="cancel_appointment" value="1">
                                                    <input type="hidden" name="encounter_id" value="<?= (int)$pa['encounter_id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill w-100 fw-semibold">
                                                        <i class="bi bi-x-circle me-1"></i> Cancel Appointment
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="../js/qrcode.min.js"></script>
<script>
    function switchPatientTab(btnId) {
        const btn = document.getElementById(btnId);
        if (btn) {
            const tab = bootstrap.Tab.getOrCreateInstance(btn);
            tab.show();
        }
        const mapping = {
            'tab-pass-btn': 'mob-pass-btn',
            'tab-calendar-btn': 'mob-calendar-btn',
            'tab-records-btn': 'mob-records-btn',
            'tab-profile-btn': 'mob-profile-btn'
        };
        document.querySelectorAll('.mobile-nav-link').forEach(el => el.classList.remove('active'));
        const mobId = mapping[btnId];
        if (mobId) {
            const mobEl = document.getElementById(mobId);
            if (mobEl) mobEl.classList.add('active');
        }
    }

    function openBookingModalForDate(dateStr) {
        const input = document.getElementById('apptDateInput');
        if (input) {
            input.value = dateStr;
            checkApptDay(input);
        }
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('bookModal'));
        modal.show();
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

        // Check URL tab parameter
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        if (tabParam === 'calendar') {
            switchPatientTab('tab-calendar-btn');
        } else if (tabParam === 'records') {
            switchPatientTab('tab-records-btn');
        } else if (tabParam === 'profile') {
            switchPatientTab('tab-profile-btn');
        }

        // Listen to tab clicks to keep mobile nav synced
        document.querySelectorAll('.patient-pill-btn').forEach(btn => {
            btn.addEventListener('shown.bs.tab', (e) => {
                const targetId = e.target.id;
                const mapping = {
                    'tab-pass-btn': 'mob-pass-btn',
                    'tab-calendar-btn': 'mob-calendar-btn',
                    'tab-records-btn': 'mob-records-btn',
                    'tab-profile-btn': 'mob-profile-btn'
                };
                document.querySelectorAll('.mobile-nav-link').forEach(el => el.classList.remove('active'));
                const mobId = mapping[targetId];
                if (mobId) {
                    const mobEl = document.getElementById(mobId);
                    if (mobEl) mobEl.classList.add('active');
                }
            });
        });
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
