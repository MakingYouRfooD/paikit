<?php
/**
 * staff/walkin_process.php
 * Deterministic Mobile Lookup & Encounter Generator
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173) - Zero Clinical Exposure
 */

require_once __DIR__ . '/../db.php';
require_role(['Staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard");
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header("Location: dashboard?error=" . urlencode("Security token expired. Please try again."));
    exit;
}

$action = $_POST['action'] ?? '';

// ACTION 1: DETERMINISTIC WALKIN REGISTRATION & ENCOUNTER GENERATION
if ($action === 'register_walkin') {
    $rawMobile     = trim($_POST['mobile_number'] ?? '');
    $firstName     = trim($_POST['first_name'] ?? '');
    $middleName    = trim($_POST['middle_name'] ?? '');
    $lastName      = trim($_POST['last_name'] ?? '');
    $suffix        = trim($_POST['suffix'] ?? '');

    // Assemble Full Name
    $nameParts     = array_filter([$firstName, $middleName, $lastName]);
    $fullName      = implode(' ', $nameParts);
    if (!empty($suffix)) {
        $fullName .= ' ' . $suffix;
    }

    $birthDate     = trim($_POST['birth_date'] ?? '');
    $gender        = trim($_POST['gender'] ?? 'Other');
    $sitio         = trim($_POST['sitio'] ?? 'Paikit');
    $streetAddress = trim($_POST['street_address'] ?? '');
    $complaint     = trim($_POST['complaint'] ?? '');

    // Normalize phone number (deterministic anchor)
    $mobile = normalize_phone($rawMobile);

    if (empty($mobile) || !preg_match('/^09[0-9]{9}$/', $mobile)) {
        header("Location: dashboard?error=" . urlencode("Mobile number must be exactly 11 digits starting with 09 (e.g. 09171234567)."));
        exit;
    }

    if (empty($firstName) || empty($lastName) || empty($birthDate)) {
        header("Location: dashboard?error=" . urlencode("Patient First Name, Last Name, and Birth Date are mandatory for registration."));
        exit;
    }

    $todayStr = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) || $birthDate > $todayStr || $birthDate < '1900-01-01') {
        header("Location: dashboard?error=" . urlencode("Invalid birth date: Please select a valid date between January 1, 1900 and today."));
        exit;
    }

    if (!is_valid_person_name($firstName, 2)) {
        header("Location: dashboard?error=" . urlencode("Invalid First Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters and max 2 consecutive identical letters."));
        exit;
    }

    if (!empty($middleName) && !is_valid_person_name($middleName, 2)) {
        header("Location: dashboard?error=" . urlencode("Invalid Middle Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters and max 2 consecutive identical letters."));
        exit;
    }

    if (!is_valid_person_name($lastName, 2)) {
        header("Location: dashboard?error=" . urlencode("Invalid Last Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters and max 2 consecutive identical letters."));
        exit;
    }

    if (!is_valid_canlubang_sitio($sitio)) {
        header("Location: dashboard?error=" . urlencode("Address must be an authorized Canlubang sitio (Asia 1, Asia 2, Carmel Housing, Ceris 1 & 2, GK, Manfil, Mcdc, Paikit, or Palao)."));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // DETERMINISTIC LOOKUP: Check if patient already exists by unique mobile_number
        $stmtCheck = $pdo->prepare("SELECT patient_id, full_name, account_type FROM patients WHERE mobile_number = ? LIMIT 1");
        $stmtCheck->execute([$mobile]);
        $existingPatient = $stmtCheck->fetch();

        if ($existingPatient) {
            // MATCH FOUND: Link to existing patient record (Prevents duplicate profiles)
            $patientId = $existingPatient['patient_id'];
            $linkedStatus = "Linked to existing profile ({$existingPatient['full_name']})";

            // Optionally update name/birthdate/address if provided
            $stmtUpdatePat = $pdo->prepare("
                UPDATE patients 
                SET full_name = ?, first_name = ?, middle_name = ?, last_name = ?, suffix = ?, 
                    birth_date = ?, gender = ?, sitio = ?, street_address = ? 
                WHERE patient_id = ?
            ");
            $stmtUpdatePat->execute([$fullName, $firstName, $middleName, $lastName, $suffix, $birthDate, $gender, $sitio, $streetAddress, $patientId]);
        } else {
            // NO MATCH: Insert new patient record with account_type = 'walkin_guest'
            $stmtInsertPat = $pdo->prepare("
                INSERT INTO patients 
                (mobile_number, full_name, first_name, middle_name, last_name, suffix, birth_date, gender, sitio, street_address, account_type) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'walkin_guest')
            ");
            $stmtInsertPat->execute([$mobile, $fullName, $firstName, $middleName, $lastName, $suffix, $birthDate, $gender, $sitio, $streetAddress]);
            $patientId = $pdo->lastInsertId();
            $linkedStatus = "Created new walk-in guest profile";
        }

        // Generate sequential Queue Number for today (W-001, W-002, etc.)
        $queueNumber = generate_queue_number($pdo, 'W');

        $isPriority     = !empty($_POST['is_priority']) ? 1 : 0;
        $priorityReason = trim($_POST['priority_reason'] ?? '');
        if ($isPriority && empty($priorityReason)) {
            $priorityReason = 'Senior Citizen / PWD / Emergency';
        }

        // Create Encounter Record (Vitals and Diagnostic Notes are strictly encoded by the Solo Doctor)
        // Walk-in patients are physically present at the desk, so check_in_status is immediately 'Arrived'
        $stmtEnc = $pdo->prepare("
            INSERT INTO encounters 
            (patient_id, queue_number, encounter_type, queue_status, check_in_status, check_in_time, is_priority, priority_reason, complaint, vital_signs, diagnostic_notes)
            VALUES (?, ?, 'Walk-in', 'Waiting', 'Arrived', NOW(), ?, ?, ?, NULL, NULL)
        ");
        $stmtEnc->execute([$patientId, $queueNumber, $isPriority, $isPriority ? $priorityReason : null, $complaint]);
        $encounterId = $pdo->lastInsertId();

        // Generate Cryptographically Secure Transaction Code for Guest Slip Reference
        $transactionCode = generate_transaction_code();
        $stmtToken = $pdo->prepare("INSERT INTO walkin_access_tokens (encounter_id, transaction_code) VALUES (?, ?)");
        $stmtToken->execute([$encounterId, $transactionCode]);

        $pdo->commit();

        audit_log(
            'WALKIN_REGISTERED', 
            "Staff registered walk-in ticket {$queueNumber} for {$fullName} (Mobile: {$mobile}, {$linkedStatus}). Encounter ID: {$encounterId}.",
            'Encounter',
            (string)$encounterId,
            [
                'patient_id'       => $patientId,
                'patient_name'     => $fullName,
                'queue_number'     => $queueNumber,
                'encounter_type'   => 'Walk-in',
                'registration'     => $linkedStatus,
                'transaction_code' => $transactionCode
            ]
        );

        header("Location: dashboard?ticket_id={$encounterId}&print=1&msg=" . urlencode("Walk-in ticket {$queueNumber} generated successfully! {$linkedStatus}."));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Walk-in registration error: " . $e->getMessage());
        header("Location: dashboard?error=" . urlencode("Failed to register walk-in patient. Please try again."));
        exit;
    }
}

// ACTION 2: PHARMACY DISPENSING STATUS UPDATE
if ($action === 'update_dispense_status') {
    $prescriptionId = (int)($_POST['prescription_id'] ?? 0);
    $status         = $_POST['status'] ?? 'Dispensed';

    if (!in_array($status, ['Pending', 'Dispensed'])) {
        $status = 'Dispensed';
    }

    if ($prescriptionId > 0) {
        $stmt = $pdo->prepare("UPDATE prescriptions SET dispense_status = ? WHERE prescription_id = ?");
        $stmt->execute([$status, $prescriptionId]);

        audit_log('PRESCRIPTION_DISPENSED', "Staff updated Prescription ID {$prescriptionId} dispense status to '{$status}'.");
        header("Location: dashboard?tab=pharmacy&msg=" . urlencode("Dispensing status updated to '{$status}'."));
        exit;
    }
}

// ACTION 3: UPDATE WEEKLY CONSULTATION SCHEDULE (MON - FRI ONLY, SAT & SUN EXCLUDED)
if ($action === 'update_weekly_schedule') {
    $availableDays = $_POST['available_days'] ?? []; // Array of day numbers: 1 to 5
    $startTimes    = $_POST['start_time'] ?? [];
    $endTimes      = $_POST['end_time'] ?? [];

    for ($d = 1; $d <= 5; $d++) {
        $isAvail = in_array((string)$d, $availableDays, true) ? 1 : 0;
        $start   = !empty($startTimes[$d]) ? $startTimes[$d] : '08:00:00';
        $end     = !empty($endTimes[$d]) ? $endTimes[$d] : '17:00:00';

        $stmtUp = $pdo->prepare("
            UPDATE weekly_consultation_settings 
            SET is_available = ?, start_time = ?, end_time = ? 
            WHERE day_number = ?
        ");
        $stmtUp->execute([$isAvail, $start, $end, $d]);
    }

    audit_log('WEEKLY_SCHEDULE_UPDATED', "Staff/Admin updated weekly consultation schedule (Mon-Fri only, weekends excluded).");
    header("Location: dashboard?tab=schedule&msg=" . urlencode("Weekly Consultation Schedule (Monday to Friday) updated successfully."));
    exit;
}

// ACTION 4: CREATE DOCTOR OR STAFF ACCOUNT (ADMIN/STAFF INTERNAL PROVISIONING)
if ($action === 'create_user') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = trim($_POST['role'] ?? 'Staff');

    if (!in_array($role, ['Doctor', 'Staff'])) {
        header("Location: dashboard?tab=users&error=" . urlencode("Invalid role selected. Only Doctor or Staff accounts can be created."));
        exit;
    }

    if (empty($fullName) || empty($email) || empty($username) || empty($password)) {
        header("Location: dashboard?tab=users&error=" . urlencode("Full name, email address, username, and password are required."));
        exit;
    }

    if (strlen($password) < 8 
        || !preg_match('/[A-Z]/', $password) 
        || !preg_match('/[a-z]/', $password) 
        || !preg_match('/[0-9]/', $password) 
        || !preg_match('/[\W_]/', $password)) {
        header("Location: dashboard?tab=users&error=" . urlencode("Password must be at least 8 characters long and contain uppercase (A-Z), lowercase (a-z), numbers (0-9), and special characters (e.g. @, #, $, !)."));
        exit;
    }

    if (!is_valid_person_name($fullName, 2)) {
        header("Location: dashboard?tab=users&error=" . urlencode("Invalid Name: Numbers, spaces only, or ghost names are prohibited. Must contain at least 2 letters and max 2 consecutive identical letters."));
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: dashboard?tab=users&error=" . urlencode("Please enter a valid official email address."));
        exit;
    }

    // Check if email already exists
    $stmtCheckEmail = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmtCheckEmail->execute([$email]);
    if ($stmtCheckEmail->fetch()) {
        header("Location: dashboard?tab=users&error=" . urlencode("The email address '{$email}' is already in use by another personnel."));
        exit;
    }

    // Check if username already exists
    $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmtCheck->execute([$username]);
    if ($stmtCheck->fetch()) {
        header("Location: dashboard?tab=users&error=" . urlencode("The username '{$username}' is already in use. Please select another."));
        exit;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmtInsert = $pdo->prepare("INSERT INTO users (username, email, full_name, password_hash, role) VALUES (?, ?, ?, ?, ?)");
    $stmtInsert->execute([$username, $email, $fullName, $passwordHash, $role]);
    $newUserId = $pdo->lastInsertId();

    audit_log(
        'PERSONNEL_ACCOUNT_CREATED', 
        "Staff created {$role} account '{$email}' / '{$username}' for {$fullName} (User ID {$newUserId}).",
        'User',
        (string)$newUserId,
        [
            'user_id'   => (int)$newUserId,
            'username'  => $username,
            'full_name' => $fullName,
            'email'     => $email,
            'role'      => $role
        ]
    );
    header("Location: dashboard?tab=users&msg=" . urlencode("{$role} account for '{$fullName}' ({$email}) created successfully!"));
    exit;
}

// ACTION 5: 1-CLICK / QR SCAN CHECK-IN FOR APPOINTMENTS (MARK AS ARRIVED)
if ($action === 'checkin_appointment') {
    $encounterId = (int)($_POST['encounter_id'] ?? 0);
    $searchTerm  = trim($_POST['search_term'] ?? '');
    $isPriority  = !empty($_POST['is_priority']) ? 1 : 0;
    $priReason   = trim($_POST['priority_reason'] ?? 'Senior Citizen / PWD / Emergency');

    // Clean search term if scanned from QR code (e.g. "PAIKIT:A-001" or "PAIKIT:CHECKIN:A-001" or "PAIKIT:PK-12345678")
    $cleanSearch = preg_replace('/^PAIKIT:(CHECKIN:|APPT:)?/i', '', $searchTerm);
    // If format is like A-001:123, extract the first token
    if (strpos($cleanSearch, ':') !== false) {
        $parts = explode(':', $cleanSearch);
        $cleanSearch = $parts[0];
    }
    $cleanSearch = trim($cleanSearch);

    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd   = date('Y-m-d 23:59:59');
    $target = null;

    if ($encounterId > 0) {
        $stmt = $pdo->prepare("
            SELECT e.*, p.full_name, p.mobile_number 
            FROM encounters e 
            JOIN patients p ON e.patient_id = p.patient_id 
            WHERE e.encounter_id = ?
        ");
        $stmt->execute([$encounterId]);
        $target = $stmt->fetch();
    } elseif (!empty($cleanSearch)) {
        // Deterministic search by Queue Number, Transaction Code, Mobile Number, or Patient Name
        $stmt = $pdo->prepare("
            SELECT e.*, p.full_name, p.mobile_number 
            FROM encounters e
            JOIN patients p ON e.patient_id = p.patient_id
            LEFT JOIN walkin_access_tokens t ON e.encounter_id = t.encounter_id
            WHERE e.visit_date BETWEEN ? AND ?
              AND (e.queue_number = ? OR t.transaction_code = ? OR p.mobile_number = ? OR p.full_name LIKE ?)
            ORDER BY e.encounter_id DESC LIMIT 1
        ");
        $stmt->execute([$todayStart, $todayEnd, $cleanSearch, $cleanSearch, $cleanSearch, "%{$cleanSearch}%"]);
        $target = $stmt->fetch();
    }

    if ($target) {
        if (($target['approval_status'] ?? 'Approved') !== 'Approved') {
            header("Location: dashboard?tab=checkin&error=" . urlencode("Ticket #{$target['queue_number']} is currently '{$target['approval_status']}'. Only 'Approved' appointments can be checked in. Please approve it first under the 'Appointment Approvals' tab."));
            exit;
        }

        $stmtUpdate = $pdo->prepare("
            UPDATE encounters 
            SET check_in_status = 'Arrived', 
                check_in_time = NOW(),
                is_priority = CASE WHEN ? = 1 THEN 1 ELSE is_priority END,
                priority_reason = CASE WHEN ? = 1 THEN ? ELSE priority_reason END
            WHERE encounter_id = ?
        ");
        $stmtUpdate->execute([$isPriority, $isPriority, $priReason, $target['encounter_id']]);

        $priMsg = $isPriority ? " (Priority: {$priReason})" : "";
        audit_log(
            'APPOINTMENT_CHECKED_IN', 
            "Staff marked Ticket #{$target['queue_number']} ({$target['full_name']}) as Arrived{$priMsg}.",
            'Encounter',
            (string)$target['encounter_id'],
            [
                'patient_id'    => (int)$target['patient_id'],
                'patient_name'  => $target['full_name'],
                'queue_number'  => $target['queue_number'],
                'check_in'      => ['old' => 'Booked', 'new' => 'Arrived'],
                'is_priority'   => (bool)$isPriority,
                'priority_note' => $isPriority ? $priReason : null
            ]
        );
        header("Location: dashboard?tab=checkin&msg=" . urlencode("{$target['full_name']} (Ticket #{$target['queue_number']}) has been marked as 'Arrived'! Added to the Doctor's active calling queue."));
        exit;
    } else {
        header("Location: dashboard?tab=checkin&error=" . urlencode("No appointment found matching the QR code or search term ('{$cleanSearch}')."));
        exit;
    }
}

// ACTION 6: TOGGLE PRIORITY SWITCH (STAFF DESK)
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
                'TOGGLE_PRIORITY_STAFF', 
                "Staff changed Ticket #{$row['queue_number']} to {$statusText}.",
                'Encounter',
                (string)$encounterId,
                [
                    'encounter_id' => $encounterId,
                    'queue_number' => $row['queue_number'],
                    'priority'     => ['old' => (bool)$row['is_priority'], 'new' => (bool)$newPri],
                    'reason'       => $newPri ? $reason : null
                ]
            );
            header("Location: dashboard?tab=queue&msg=" . urlencode("Ticket #{$row['queue_number']} has been {$statusText}!"));
            exit;
        }
    }
    header("Location: dashboard");
    exit;
}

// ACTION 7: APPROVE CLIENT APPOINTMENT REQUEST
if ($action === 'approve_appointment') {
    $encounterId = (int)($_POST['encounter_id'] ?? 0);
    if ($encounterId > 0) {
        $stmt = $pdo->prepare("
            SELECT e.*, p.full_name, p.mobile_number, COALESCE(p.email, u.email) as patient_email
            FROM encounters e
            JOIN patients p ON e.patient_id = p.patient_id
            LEFT JOIN users u ON u.patient_id = p.patient_id
            WHERE e.encounter_id = ? AND e.encounter_type = 'Appointment'
        ");
        $stmt->execute([$encounterId]);
        $target = $stmt->fetch();

        if ($target) {
            $reviewerId = $_SESSION['user_id'] ?? null;
            $stmtUpdate = $pdo->prepare("
                UPDATE encounters 
                SET approval_status = 'Approved',
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE encounter_id = ?
            ");
            $stmtUpdate->execute([$reviewerId, $encounterId]);

            audit_log(
                'APPOINTMENT_APPROVED', 
                "Staff approved Ticket #{$target['queue_number']} for {$target['full_name']} scheduled on {$target['visit_date']}.",
                'Encounter',
                (string)$encounterId,
                [
                    'encounter_id'    => $encounterId,
                    'patient_id'      => (int)$target['patient_id'],
                    'patient_name'    => $target['full_name'],
                    'queue_number'    => $target['queue_number'],
                    'visit_date'      => $target['visit_date'],
                    'approval_status' => ['old' => 'Pending', 'new' => 'Approved']
                ]
            );

            // Dispatch notification email if patient has email
            if (!empty($target['patient_email'])) {
                require_once __DIR__ . '/../config/mail.php';
                $visitFmt = date('l, F j, Y', strtotime($target['visit_date']));
                $subject = "Appointment Confirmed - Ticket #{$target['queue_number']} (Paikit Health Center)";
                $body = "
                <div style='font-family: Arial, sans-serif; color: #1e293b; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>
                    <div style='background: #0f766e; padding: 20px; text-align: center; color: white;'>
                        <h2 style='margin: 0; font-size: 22px;'>Paikit Health Center</h2>
                        <p style='margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;'>Primary Consultation & Clinical Care</p>
                    </div>
                    <div style='padding: 24px; background: #ffffff;'>
                        <h3 style='color: #0f766e; margin-top: 0;'>Appointment Request Approved!</h3>
                        <p>Dear <strong>" . htmlspecialchars($target['full_name']) . "</strong>,</p>
                        <p>We are pleased to inform you that your outpatient appointment request has been reviewed and <strong style='color: #16a34a;'>APPROVED</strong> by the Health Center Staff Desk.</p>
                        
                        <div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin: 20px 0;'>
                            <table style='width: 100%; border-collapse: collapse;'>
                                <tr>
                                    <td style='padding: 6px 0; color: #64748b; font-size: 14px;'>Queue Ticket:</td>
                                    <td style='padding: 6px 0; font-weight: bold; font-size: 18px; color: #0f766e;'>Ticket #" . htmlspecialchars($target['queue_number']) . "</td>
                                </tr>
                                <tr>
                                    <td style='padding: 6px 0; color: #64748b; font-size: 14px;'>Scheduled Date:</td>
                                    <td style='padding: 6px 0; font-weight: bold; font-size: 15px; color: #1e293b;'>" . $visitFmt . "</td>
                                </tr>
                                <tr>
                                    <td style='padding: 6px 0; color: #64748b; font-size: 14px;'>Requested Service:</td>
                                    <td style='padding: 6px 0; font-size: 14px; color: #1e293b;'>" . htmlspecialchars($target['complaint'] ?: 'General Consultation') . "</td>
                                </tr>
                            </table>
                        </div>

                        <h4 style='margin-bottom: 8px; color: #1e293b;'>Instructions for Visit:</h4>
                        <ol style='padding-left: 20px; line-height: 1.6; color: #475569;'>
                            <li>Please arrive at the health center on your scheduled date between <strong>8:00 AM - 11:30 AM</strong>.</li>
                            <li>Log in to your patient portal on your mobile device to show your <strong>QR Code Check-in Pass</strong> to the Staff Desk.</li>
                            <li>Once the Staff Desk scans or checks you in, you will be placed into the physician's active calling queue.</li>
                        </ol>

                        <div style='text-align: center; margin-top: 25px;'>
                            <a href='http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/paikit/client/dashboard' style='background: #0f766e; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;'>View Patient Dashboard</a>
                        </div>
                    </div>
                    <div style='background: #f8fafc; padding: 14px 20px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0;'>
                        Paikit Health Center &bull; Canlubang, Calamba City, Laguna &bull; RA 10173 Data Privacy Protected
                    </div>
                </div>";
                @send_system_email($target['patient_email'], $target['full_name'], $subject, $body);
            }

            header("Location: dashboard?tab=approvals&msg=" . urlencode("Appointment for {$target['full_name']} (Ticket #{$target['queue_number']}) has been Approved!"));
            exit;
        }
    }
    header("Location: dashboard?tab=approvals&error=" . urlencode("Invalid appointment request."));
    exit;
}

// ACTION 8: DECLINE CLIENT APPOINTMENT REQUEST
if ($action === 'decline_appointment') {
    $encounterId   = (int)($_POST['encounter_id'] ?? 0);
    $declineReason = trim($_POST['decline_reason'] ?? '');
    if (empty($declineReason)) {
        $declineReason = 'Quota limit reached or schedule conflict.';
    }

    if ($encounterId > 0) {
        $stmt = $pdo->prepare("
            SELECT e.*, p.full_name, p.mobile_number, COALESCE(p.email, u.email) as patient_email
            FROM encounters e
            JOIN patients p ON e.patient_id = p.patient_id
            LEFT JOIN users u ON u.patient_id = p.patient_id
            WHERE e.encounter_id = ? AND e.encounter_type = 'Appointment'
        ");
        $stmt->execute([$encounterId]);
        $target = $stmt->fetch();

        if ($target) {
            $reviewerId = $_SESSION['user_id'] ?? null;
            $stmtUpdate = $pdo->prepare("
                UPDATE encounters 
                SET approval_status = 'Declined',
                    decline_reason = ?,
                    queue_status = 'Cancelled',
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE encounter_id = ?
            ");
            $stmtUpdate->execute([$declineReason, $reviewerId, $encounterId]);

            audit_log(
                'APPOINTMENT_DECLINED', 
                "Staff declined Ticket #{$target['queue_number']} for {$target['full_name']} (Reason: {$declineReason}).",
                'Encounter',
                (string)$encounterId,
                [
                    'encounter_id'    => $encounterId,
                    'patient_id'      => (int)$target['patient_id'],
                    'patient_name'    => $target['full_name'],
                    'queue_number'    => $target['queue_number'],
                    'visit_date'      => $target['visit_date'],
                    'approval_status' => ['old' => 'Pending', 'new' => 'Declined'],
                    'queue_status'    => ['old' => $target['queue_status'], 'new' => 'Cancelled'],
                    'decline_reason'  => $declineReason
                ]
            );

            // Dispatch notification email if patient has email
            if (!empty($target['patient_email'])) {
                require_once __DIR__ . '/../config/mail.php';
                $visitFmt = date('l, F j, Y', strtotime($target['visit_date']));
                $subject = "Appointment Request Update - Paikit Health Center";
                $body = "
                <div style='font-family: Arial, sans-serif; color: #1e293b; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>
                    <div style='background: #0f766e; padding: 20px; text-align: center; color: white;'>
                        <h2 style='margin: 0; font-size: 22px;'>Paikit Health Center</h2>
                        <p style='margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;'>Primary Consultation & Clinical Care</p>
                    </div>
                    <div style='padding: 24px; background: #ffffff;'>
                        <h3 style='color: #dc2626; margin-top: 0;'>Appointment Request Notice</h3>
                        <p>Dear <strong>" . htmlspecialchars($target['full_name']) . "</strong>,</p>
                        <p>We are writing to inform you that your outpatient appointment request for <strong>" . $visitFmt . "</strong> could not be accommodated at this time.</p>
                        
                        <div style='background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin: 20px 0;'>
                            <strong style='color: #991b1b; display: block; margin-bottom: 6px;'>Reason for Decision:</strong>
                            <p style='margin: 0; color: #7f1d1d; font-size: 15px;'>" . htmlspecialchars($declineReason) . "</p>
                        </div>

                        <p style='color: #475569; line-height: 1.5;'>
                            You may select an alternative consultation date from the interactive calendar on your patient portal or visit during standard walk-in hours (Monday to Friday, 8:00 AM - 12:00 PM).
                        </p>

                        <div style='text-align: center; margin-top: 25px;'>
                            <a href='http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/paikit/client/dashboard' style='background: #0f766e; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;'>Book An Alternative Date</a>
                        </div>
                    </div>
                    <div style='background: #f8fafc; padding: 14px 20px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0;'>
                        Paikit Health Center &bull; Canlubang, Calamba City, Laguna &bull; RA 10173 Data Privacy Protected
                    </div>
                </div>";
                @send_system_email($target['patient_email'], $target['full_name'], $subject, $body);
            }

            header("Location: dashboard?tab=approvals&msg=" . urlencode("Appointment for {$target['full_name']} (Ticket #{$target['queue_number']}) was Declined."));
            exit;
        }
    }
    header("Location: dashboard?tab=approvals&error=" . urlencode("Invalid appointment request."));
    exit;
}

// ACTION 9: SAVE SMTP EMAIL SETTINGS
if ($action === 'save_smtp_settings') {
    $host       = trim($_POST['smtp_host'] ?? 'smtp.gmail.com');
    $port       = (int)($_POST['smtp_port'] ?? 587);
    $username   = trim($_POST['smtp_username'] ?? '');
    $password   = trim($_POST['smtp_password'] ?? '');
    $encryption = trim($_POST['smtp_encryption'] ?? 'tls');
    $fromEmail  = trim($_POST['smtp_from_email'] ?? $username);
    $fromName   = trim($_POST['smtp_from_name'] ?? 'Paikit Health Center');

    $settings = [
        'smtp_host'       => $host,
        'smtp_port'       => (string)$port,
        'smtp_username'   => $username,
        'smtp_encryption' => $encryption,
        'smtp_from_email' => $fromEmail,
        'smtp_from_name'  => $fromName
    ];

    if (!empty($password)) {
        $settings['smtp_password'] = $password;
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $stmtUp = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
        ");
    } else {
        $stmtUp = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
    }

    foreach ($settings as $k => $v) {
        $stmtUp->execute([$k, $v]);
    }

    audit_log('SMTP_SETTINGS_UPDATED', "Staff updated outgoing SMTP configuration (Host: {$host}, Port: {$port}, User: {$username}).");
    header("Location: dashboard?open_settings=1&tab=smtp&msg=" . urlencode("Email & SMTP configuration saved successfully! PHPMailer will now use these credentials to dispatch password reset emails."));
    exit;
}

// ACTION 8: SEND TEST EMAIL
if ($action === 'test_smtp_email') {
    require_once __DIR__ . '/../config/mail.php';
    $testTo = trim($_POST['test_email'] ?? '');

    if (empty($testTo) || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        header("Location: dashboard?open_settings=1&tab=smtp&error=" . urlencode("Please provide a valid recipient email address for the test email."));
        exit;
    }

    $subject  = "Paikit Health Center - PHPMailer Test Email";
    $testHtml = "<h3>Paikit Health Center PHPMailer Test</h3>"
              . "<p>Congratulations! Your outgoing SMTP email server is working properly.</p>"
              . "<p>Password reset links and patient notifications can now be delivered directly to inboxes.</p>"
              . "<p><em>Sent at: " . date('F j, Y, g:i A') . "</em></p>";

    $res = send_system_email($testTo, "Test Recipient", $subject, $testHtml);

    if ($res['success']) {
        audit_log('SMTP_TEST_SENT', "Test email sent successfully to {$testTo}.");
        header("Location: dashboard?open_settings=1&tab=smtp&msg=" . urlencode("Test email successfully delivered to {$testTo}! Your PHPMailer SMTP configuration is active and working."));
        exit;
    } else {
        audit_log('SMTP_TEST_FAILED', "Test email failed for {$testTo}: " . $res['error']);
        header("Location: dashboard?open_settings=1&tab=smtp&error=" . urlencode("SMTP Delivery Failed: " . $res['error']));
        exit;
    }
}

header("Location: dashboard");
exit;
