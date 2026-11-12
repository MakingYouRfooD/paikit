<?php
/**
 * doctor/update_encounter.php
 * Clinical Diagnostic & E-Prescription Backend Handler
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173) - Clinical Clearance Only
 */

require_once __DIR__ . '/../db.php';
require_role(['Doctor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard");
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header("Location: dashboard?error=" . urlencode("Security validation token expired. Please try again."));
    exit;
}

$action = $_POST['action'] ?? '';

// ACTION 1: CALL NEXT PATIENT IN FAIR INTERLEAVED QUEUE (2:1 Ratio & Priority-First)
if ($action === 'call_next') {
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd   = date('Y-m-d 23:59:59');

    // Fetch the fair interleaved queue of patients who have physically ARRIVED
    $interleavedQueue = get_interleaved_queue($pdo, $todayStart, $todayEnd);

    if (!empty($interleavedQueue)) {
        $nextPatient = $interleavedQueue[0];

        // Mark previously serving patient as Completed if requested
        if (!empty($_POST['complete_previous'])) {
            $stmtPrev = $pdo->prepare("UPDATE encounters SET queue_status = 'Completed', completed_at = NOW() WHERE visit_date BETWEEN ? AND ? AND queue_status = 'Serving'");
            $stmtPrev->execute([$todayStart, $todayEnd]);
        }

        $stmtUpdate = $pdo->prepare("UPDATE encounters SET queue_status = 'Serving' WHERE encounter_id = ?");
        $stmtUpdate->execute([$nextPatient['encounter_id']]);

        $typeDesc = !empty($nextPatient['is_priority']) ? "Priority ({$nextPatient['priority_reason']})" : $nextPatient['encounter_type'];
        audit_log(
            'CALL_NEXT_PATIENT', 
            "Doctor called Ticket #{$nextPatient['queue_number']} ({$typeDesc}, {$nextPatient['full_name']}) to Serving.",
            'Encounter',
            (string)$nextPatient['encounter_id'],
            [
                'patient_id'   => (int)$nextPatient['patient_id'],
                'patient_name' => $nextPatient['full_name'],
                'queue_number' => $nextPatient['queue_number'],
                'queue_status' => ['old' => 'Waiting', 'new' => 'Serving'],
                'type'         => $typeDesc
            ]
        );
        header("Location: dashboard?encounter_id={$nextPatient['encounter_id']}&msg=" . urlencode("Now serving Ticket #{$nextPatient['queue_number']} - {$nextPatient['full_name']} ({$typeDesc})."));
        exit;
    } else {
        header("Location: dashboard?msg=" . urlencode("No patients currently waiting who are marked as 'Arrived'."));
        exit;
    }
}

// ACTION 1B: TOGGLE PRIORITY SWITCH (Emergency, Senior, PWD, Pregnant)
if ($action === 'toggle_priority') {
    $encounterId = (int)($_POST['encounter_id'] ?? 0);
    $reason = trim($_POST['priority_reason'] ?? 'Senior Citizen / PWD / Emergency');
    if ($encounterId > 0) {
        $stmtCurr = $pdo->prepare("SELECT is_priority, queue_number, encounter_type FROM encounters WHERE encounter_id = ?");
        $stmtCurr->execute([$encounterId]);
        $row = $stmtCurr->fetch();
        if ($row) {
            $newPri = $row['is_priority'] ? 0 : 1;
            $stmtUp = $pdo->prepare("UPDATE encounters SET is_priority = ?, priority_reason = ? WHERE encounter_id = ?");
            $stmtUp->execute([$newPri, $newPri ? $reason : null, $encounterId]);
            $statusText = $newPri ? "marked as Priority ({$reason})" : "reverted to Regular";
            audit_log(
                'TOGGLE_PRIORITY', 
                "Doctor changed Ticket #{$row['queue_number']} to {$statusText}.",
                'Encounter',
                (string)$encounterId,
                [
                    'encounter_id' => $encounterId,
                    'queue_number' => $row['queue_number'],
                    'priority'     => ['old' => (bool)$row['is_priority'], 'new' => (bool)$newPri],
                    'reason'       => $newPri ? $reason : null
                ]
            );
            header("Location: dashboard?encounter_id={$encounterId}&msg=" . urlencode("Ticket #{$row['queue_number']} has been successfully {$statusText}!"));
            exit;
        }
    }
    header("Location: dashboard");
    exit;
}

// ACTION 2: UPDATE DUTY HOURS & SCHEDULE (TODAY OR FUTURE DATES)
if ($action === 'update_schedule') {
    $duty_date    = trim($_POST['duty_date'] ?? date('Y-m-d'));
    $raw_start    = trim($_POST['start_time'] ?? '08:00');
    $raw_end      = trim($_POST['end_time'] ?? '17:00');
    $start_time   = (strtotime($raw_start) !== false) ? date('H:i:s', strtotime($raw_start)) : '08:00:00';
    $end_time     = (strtotime($raw_end) !== false) ? date('H:i:s', strtotime($raw_end)) : '17:00:00';
    $max_slots    = max(1, (int)($_POST['max_slots'] ?? 50));
    $notes        = trim($_POST['notes'] ?? '');
    $is_available = isset($_POST['is_available']) ? (int)$_POST['is_available'] : 1;

    if (empty($duty_date) || !strtotime($duty_date)) {
        header("Location: dashboard?error=" . urlencode("Please provide a valid duty date."));
        exit;
    }

    if ($duty_date < date('Y-m-d')) {
        header("Location: dashboard?error=" . urlencode("Cannot modify quota for past dates. Please select today or a future date."));
        exit;
    }

    $dayOfWeek = (int)date('N', strtotime($duty_date));
    if ($dayOfWeek === 6 || $dayOfWeek === 7) {
        header("Location: dashboard?error=" . urlencode("The health center is closed on Saturdays and Sundays. Please select a weekday (Monday to Friday)."));
        exit;
    }

    // Check if schedule for date already exists
    $stmtCheck = $pdo->prepare("SELECT schedule_id FROM doctor_schedules WHERE duty_date = ?");
    $stmtCheck->execute([$duty_date]);
    $schedId = $stmtCheck->fetchColumn();

    if ($schedId) {
        $stmt = $pdo->prepare("UPDATE doctor_schedules SET start_time = ?, end_time = ?, max_slots = ?, notes = ?, is_available = ? WHERE schedule_id = ?");
        $stmt->execute([$start_time, $end_time, $max_slots, $notes, $is_available, $schedId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO doctor_schedules (duty_date, start_time, end_time, max_slots, notes, is_available) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$duty_date, $start_time, $end_time, $max_slots, $notes, $is_available]);
    }

    $formattedDate = date('F j, Y (l)', strtotime($duty_date));
    audit_log('DUTY_SCHEDULE_POSTED', "Doctor updated duty hours for {$duty_date} ({$start_time}-{$end_time}, Max Slots: {$max_slots}, Available: {$is_available}).");
    header("Location: dashboard?msg=" . urlencode("Daily quota of {$max_slots} patients and duty hours for {$formattedDate} updated successfully."));
    exit;
}

// ACTION 2B: SET DOCTOR UNAVAILABLE DATE (LEAVE / CONVENTION / OUT OF CLINIC)
if ($action === 'set_unavailability') {
    $duty_date = trim($_POST['duty_date'] ?? '');
    $notes     = trim($_POST['notes'] ?? 'Attending Medical Conference / Official Leave');

    if (empty($duty_date) || !strtotime($duty_date)) {
        header("Location: dashboard?error=" . urlencode("Please provide a valid date to mark as unavailable."));
        exit;
    }

    if ($duty_date < date('Y-m-d')) {
        header("Location: dashboard?error=" . urlencode("Cannot mark past dates as unavailable. Please select today or a future date."));
        exit;
    }

    $dayOfWeek = (int)date('N', strtotime($duty_date));
    if ($dayOfWeek === 6 || $dayOfWeek === 7) {
        header("Location: dashboard?error=" . urlencode("Saturdays and Sundays are already closed by default. Please select a weekday (Monday to Friday)."));
        exit;
    }

    // Check if existing record exists
    $stmtCheck = $pdo->prepare("SELECT schedule_id FROM doctor_schedules WHERE duty_date = ?");
    $stmtCheck->execute([$duty_date]);
    $schedId = $stmtCheck->fetchColumn();

    if ($schedId) {
        $stmt = $pdo->prepare("UPDATE doctor_schedules SET is_available = 0, max_slots = 0, notes = ? WHERE schedule_id = ?");
        $stmt->execute([$notes, $schedId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO doctor_schedules (duty_date, start_time, end_time, max_slots, notes, is_available) VALUES (?, '08:00', '17:00', 0, ?, 0)");
        $stmt->execute([$duty_date, $notes]);
    }

    // Check if any patients already booked appointments on this date
    $dateStart = $duty_date . ' 00:00:00';
    $dateEnd   = $duty_date . ' 23:59:59';
    $stmtBookedCount = $pdo->prepare("SELECT COUNT(*) FROM encounters WHERE visit_date BETWEEN ? AND ? AND queue_status = 'Waiting'");
    $stmtBookedCount->execute([$dateStart, $dateEnd]);
    $bookedOnDate = (int)$stmtBookedCount->fetchColumn();

    $formattedDate = date('F j, Y (l)', strtotime($duty_date));
    audit_log('DOCTOR_UNAVAILABLE_SET', "Doctor marked {$duty_date} as unavailable. Reason: {$notes}. Pre-booked tickets on date: {$bookedOnDate}.");

    $noticeMsg = "Successfully marked {$formattedDate} as Doctor Unavailable ({$notes}). Patients cannot book on this date.";
    if ($bookedOnDate > 0) {
        $noticeMsg .= " Note: {$bookedOnDate} patient(s) already hold active bookings on this date.";
    }

    header("Location: dashboard?msg=" . urlencode($noticeMsg));
    exit;
}

// ACTION 2C: REMOVE UNAVAILABILITY (RE-OPEN DATE FOR CONSULTATIONS)
if ($action === 'remove_unavailability') {
    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    $duty_date   = trim($_POST['duty_date'] ?? '');

    if ($schedule_id > 0) {
        $stmt = $pdo->prepare("UPDATE doctor_schedules SET is_available = 1, max_slots = 50, notes = 'Consultation Re-opened' WHERE schedule_id = ?");
        $stmt->execute([$schedule_id]);
    } elseif (!empty($duty_date)) {
        $stmt = $pdo->prepare("UPDATE doctor_schedules SET is_available = 1, max_slots = 50, notes = 'Consultation Re-opened' WHERE duty_date = ?");
        $stmt->execute([$duty_date]);
    }

    $formattedDate = !empty($duty_date) ? date('F j, Y', strtotime($duty_date)) : "Schedule #{$schedule_id}";
    audit_log('DOCTOR_UNAVAILABLE_REMOVED', "Doctor re-opened consultation availability for {$formattedDate}.");
    header("Location: dashboard?msg=" . urlencode("Consultation date {$formattedDate} has been re-opened! Patients may now book appointments."));
    exit;
}

// ACTION 3: SAVE CLINICAL ENCOUNTER, VITALS, DIAGNOSIS, AND E-PRESCRIPTIONS
if ($action === 'save_encounter') {
    $encounterId = (int)($_POST['encounter_id'] ?? 0);
    $complaint   = trim($_POST['complaint'] ?? '');

    if ($encounterId <= 0) {
        header("Location: dashboard?error=" . urlencode("Invalid consultation encounter ID."));
        exit;
    }

    $isCallNext = isset($_POST['save_and_call_next']);
    $isSaveOnly = isset($_POST['save_only']);

    // When either Save Progress or Complete & Call Next is clicked,
    // the current consultation is finished and closed.
    $queueStatus = 'Completed';
    if (!empty($_POST['queue_status']) && $_POST['queue_status'] === 'Cancelled') {
        $queueStatus = 'Cancelled';
    }

    // Vitals Encoding
    $vitals = [
        'bp'     => trim($_POST['vital_bp'] ?? ''),
        'temp'   => trim($_POST['vital_temp'] ?? ''),
        'weight' => trim($_POST['vital_weight'] ?? ''),
        'height' => trim($_POST['vital_height'] ?? ''),
        'pulse'  => trim($_POST['vital_pulse'] ?? ''),
        'rr'     => trim($_POST['vital_rr'] ?? ''),
    ];
    $vitalsJson = json_encode($vitals);

    // Clinical Diagnostic Notes
    $diagAssessment = trim($_POST['diagnostic_notes'] ?? '');

    // Update Encounter
    $stmtEnc = $pdo->prepare("
        UPDATE encounters 
        SET queue_status = ?, completed_at = IF(? = 'Completed', NOW(), completed_at), complaint = ?, vital_signs = ?, diagnostic_notes = ? 
        WHERE encounter_id = ?
    ");
    $stmtEnc->execute([$queueStatus, $queueStatus, $complaint, $vitalsJson, $diagAssessment, $encounterId]);

    // Process E-Prescriptions
    $medNames = $_POST['med_name'] ?? [];
    $medDosages = $_POST['med_dosage'] ?? [];
    $medDurations = $_POST['med_duration'] ?? [];
    $medQuantities = $_POST['med_quantity'] ?? [];

    $prescriptionItems = [];
    if (is_array($medNames)) {
        for ($i = 0; $i < count($medNames); $i++) {
            $name = trim($medNames[$i] ?? '');
            if (!empty($name)) {
                $prescriptionItems[] = [
                    'medication' => $name,
                    'dosage'     => trim($medDosages[$i] ?? ''),
                    'duration'   => trim($medDurations[$i] ?? ''),
                    'quantity'   => trim($medQuantities[$i] ?? '')
                ];
            }
        }
    }

    // Save or update prescriptions
    if (!empty($prescriptionItems)) {
        $medDetailsJson = json_encode($prescriptionItems);
        $stmtRxCheck = $pdo->prepare("SELECT prescription_id FROM prescriptions WHERE encounter_id = ?");
        $stmtRxCheck->execute([$encounterId]);
        $existingRx = $stmtRxCheck->fetchColumn();

        if ($existingRx) {
            $stmtRxUpdate = $pdo->prepare("UPDATE prescriptions SET medication_details = ? WHERE prescription_id = ?");
            $stmtRxUpdate->execute([$medDetailsJson, $existingRx]);
        } else {
            $stmtRxInsert = $pdo->prepare("INSERT INTO prescriptions (encounter_id, medication_details, dispense_status) VALUES (?, ?, 'Pending')");
            $stmtRxInsert->execute([$encounterId, $medDetailsJson]);
        }
    }

    // Ensure walkin access token exists for this encounter if none
    $stmtTokenCheck = $pdo->prepare("SELECT transaction_code FROM walkin_access_tokens WHERE encounter_id = ?");
    $stmtTokenCheck->execute([$encounterId]);
    $token = $stmtTokenCheck->fetchColumn();
    if (!$token) {
        $token = generate_transaction_code();
        $pdo->prepare("INSERT INTO walkin_access_tokens (encounter_id, transaction_code) VALUES (?, ?)")->execute([$encounterId, $token]);
    }

    audit_log(
        'ENCOUNTER_RECORD_UPDATED', 
        "Doctor finalized clinical notes & e-prescription for Encounter #{$encounterId} (Status: {$queueStatus}).",
        'Encounter',
        (string)$encounterId,
        [
            'encounter_id'    => $encounterId,
            'queue_status'    => $queueStatus,
            'complaint'       => $complaint,
            'vital_signs'     => $vitals,
            'rx_items_count'  => count($prescriptionItems),
            'has_prescription'=> !empty($prescriptionItems)
        ]
    );

    // If Complete & Call Next Patient was clicked
    if ($isCallNext) {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd   = date('Y-m-d 23:59:59');
        $interleavedQueue = get_interleaved_queue($pdo, $todayStart, $todayEnd);

        // Exclude current encounter just completed
        $remainingQueue = array_values(array_filter($interleavedQueue, fn($item) => (int)$item['encounter_id'] !== $encounterId));

        if (!empty($remainingQueue)) {
            $nextPatient = $remainingQueue[0];
            $stmtUpdate = $pdo->prepare("UPDATE encounters SET queue_status = 'Serving' WHERE encounter_id = ?");
            $stmtUpdate->execute([$nextPatient['encounter_id']]);

            $typeDesc = !empty($nextPatient['is_priority']) ? "Priority ({$nextPatient['priority_reason']})" : $nextPatient['encounter_type'];
            audit_log(
                'CALL_NEXT_PATIENT', 
                "Doctor completed Encounter #{$encounterId} and called Ticket #{$nextPatient['queue_number']} ({$typeDesc}, {$nextPatient['full_name']}) to Serving.",
                'Encounter',
                (string)$nextPatient['encounter_id'],
                [
                    'patient_id'   => (int)$nextPatient['patient_id'],
                    'patient_name' => $nextPatient['full_name'],
                    'queue_number' => $nextPatient['queue_number'],
                    'queue_status' => ['old' => 'Waiting', 'new' => 'Serving'],
                    'type'         => $typeDesc
                ]
            );

            header("Location: dashboard?encounter_id={$nextPatient['encounter_id']}&msg=" . urlencode("Consultation completed! Now serving Ticket #{$nextPatient['queue_number']} - {$nextPatient['full_name']} ({$typeDesc})."));
            exit;
        } else {
            header("Location: dashboard?msg=" . urlencode("Consultation completed! No more arrived patients waiting in line."));
            exit;
        }
    }

    // Save Progress: clear consultation room by redirecting to dashboard without encounter_id
    header("Location: dashboard?msg=" . urlencode("Consultation completed and saved successfully!"));
    exit;
}

// ACTION 4: CANCEL ENCOUNTER
if ($action === 'cancel_encounter') {
    $encounterId = (int)($_POST['encounter_id'] ?? 0);
    if ($encounterId > 0) {
        $stmt = $pdo->prepare("UPDATE encounters SET queue_status = 'Cancelled' WHERE encounter_id = ?");
        $stmt->execute([$encounterId]);
        audit_log('ENCOUNTER_CANCELLED', "Doctor cancelled Encounter #{$encounterId}.");
    }
    header("Location: dashboard?msg=" . urlencode("Encounter marked as Cancelled."));
    exit;
}

header("Location: dashboard");
exit;
