<?php
// seed.php - Seed database with tables and initial accounts
require_once __DIR__ . '/schema.sql'; // Just documentation reference

$host = '127.0.0.1';
$dbname = 'paikit_health_center';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Read and run schema.sql
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($sql);
    echo "Schema executed successfully.\n";

    // Hash passwords
    $password_hash = password_hash('Password123!', PASSWORD_DEFAULT);

    // 1. Insert Solo Doctor
    $stmt = $pdo->prepare("INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['doctor', 'Dr. Maria Santos, MD', 'doctor@paikit.health', $password_hash, 'Doctor']);
    $doctorId = $pdo->lastInsertId();

    // 2. Insert Staff
    $stmt->execute(['staff', 'Ana Reyes, RN (Staff Nurse)', 'staff@paikit.health', $password_hash, 'Staff']);
    $staffId = $pdo->lastInsertId();

    // 3. Insert Registered Client (Patient + User)
    $stmtPatient = $pdo->prepare("INSERT INTO patients (mobile_number, email, full_name, birth_date, gender, account_type) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtPatient->execute(['09171234567', 'client@paikit.health', 'Juan Dela Cruz', '1992-05-14', 'Male', 'registered_client']);
    $clientPatientId = $pdo->lastInsertId();

    $stmt->execute(['client', 'Juan Dela Cruz', 'client@paikit.health', $password_hash, 'Client']);
    $clientUserId = $pdo->lastInsertId();
    $pdo->prepare("UPDATE users SET patient_id = ? WHERE user_id = ?")->execute([$clientPatientId, $clientUserId]);

    // 4. Insert Doctor Schedule for today
    $today = date('Y-m-d');
    $stmtSched = $pdo->prepare("INSERT INTO doctor_schedules (duty_date, start_time, end_time, max_slots, notes) VALUES (?, ?, ?, ?, ?)");
    $stmtSched->execute([$today, '08:00:00', '17:00:00', 50, 'Regular Outpatient Clinic & Primary Care Consultation. Solo Physician on duty: Dr. Maria Santos, MD.']);

    // 5. Insert Sample Walk-in Patient (for demonstration of walk-in auto-linking and guest slip lookup)
    $stmtPatient->execute(['09189876543', 'Maria Clara De Los Santos', '1995-10-24', 'Female', 'walkin_guest']);
    $walkinPatientId = $pdo->lastInsertId();

    // Completed Encounter for Maria Clara with Diagnosis & Prescription
    $vitalsJson = json_encode([
        'bp' => '118/76',
        'temp' => '38.2',
        'weight' => '54',
        'height' => '158',
        'pulse' => '84',
        'rr' => '19'
    ]);
    $diagNotes = "ASSESSMENT: Acute Upper Respiratory Tract Infection (URTI) with mild dehydration.\nFINDINGS: Pharyngeal erythema present, tonsils not enlarged, lung fields clear to auscultation without crackles or wheezes.\nPLAN: Increased oral rehydration fluids, warm salt gargle, adequate bed rest, and prescription medications as indicated.";

    $stmtEnc = $pdo->prepare("INSERT INTO encounters (patient_id, queue_number, encounter_type, queue_status, complaint, vital_signs, diagnostic_notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtEnc->execute([
        $walkinPatientId,
        'W-001',
        'Walk-in',
        'Completed',
        'Fever, non-productive cough, and body malaise for 3 days',
        $vitalsJson,
        $diagNotes
    ]);
    $encounterId1 = $pdo->lastInsertId();

    // Prescription for Encounter 1
    $meds = [
        [
            'medication' => 'Amoxicillin 500mg Capsule',
            'dosage' => '1 capsule 3 times a day (every 8 hours) after meals',
            'duration' => '7 days',
            'quantity' => '21 capsules'
        ],
        [
            'medication' => 'Paracetamol 500mg Tablet',
            'dosage' => '1 tablet every 4 to 6 hours as needed for temperature >= 37.8°C',
            'duration' => '3 days',
            'quantity' => '10 tablets'
        ],
        [
            'medication' => 'Ascorbic Acid + Zinc (500mg/10mg)',
            'dosage' => '1 capsule once daily in the morning',
            'duration' => '14 days',
            'quantity' => '14 capsules'
        ]
    ];
    $stmtRx = $pdo->prepare("INSERT INTO prescriptions (encounter_id, medication_details, dispense_status) VALUES (?, ?, ?)");
    $stmtRx->execute([$encounterId1, json_encode($meds), 'Pending']);

    // Walk-in Access Token for Encounter 1
    $demoToken = 'PK-2026-DEMO12';
    $stmtToken = $pdo->prepare("INSERT INTO walkin_access_tokens (encounter_id, transaction_code) VALUES (?, ?)");
    $stmtToken->execute([$encounterId1, $demoToken]);

    // 6. Insert Active Waiting Encounter for Juan Dela Cruz (Registered Client)
    $clientVitals = json_encode([
        'bp' => '130/85',
        'temp' => '36.6',
        'weight' => '70',
        'height' => '172',
        'pulse' => '74',
        'rr' => '18'
    ]);
    $stmtEnc->execute([
        $clientPatientId,
        'A-001',
        'Appointment',
        'Waiting',
        'Routine hypertension follow-up and blood pressure check',
        $clientVitals,
        null
    ]);
    $encounterId2 = $pdo->lastInsertId();

    // Add Audit Log
    $stmtLog = $pdo->prepare("INSERT INTO audit_logs (username, role, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmtLog->execute(['system', 'System', 'SYSTEM_INITIALIZATION', 'Seeded database schema and initial test accounts for Paikit Health Center.', '127.0.0.1']);

    echo "Initial seed data created successfully!\n";
    echo "Doctor Account: doctor / Password123!\n";
    echo "Staff Account:  staff / Password123!\n";
    echo "Client Account: client / Password123!\n";
    echo "Demo Walk-in Slip Code: $demoToken\n";

} catch (Exception $e) {
    echo "Error seeding database: " . $e->getMessage() . "\n";
    exit(1);
}
