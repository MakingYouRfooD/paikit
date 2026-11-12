<?php
/**
 * doctor/patient_history_api.php
 * Doctor-Side Patient Consultation & Medical History API
 * Strictly Scoped to Authenticated Doctor Role (RA 10173 Clinical Clearance)
 * Paikit Health Center Information System
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_role(['Doctor']);

header('Content-Type: application/json; charset=UTF-8');

$patientId = (int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$search    = trim((string)($_GET['search'] ?? ''));
$dateFrom  = trim((string)($_GET['date_from'] ?? ''));
$dateTo    = trim((string)($_GET['date_to'] ?? ''));

if ($patientId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Valid Patient ID is required.'
    ]);
    exit;
}

try {
    $pdo = get_db();

    // 1. Fetch Patient Profile
    $stmtPatient = $pdo->prepare("
        SELECT 
            patient_id, full_name, first_name, middle_name, last_name, suffix,
            birth_date, gender, mobile_number, email, sitio, street_address,
            account_type, created_at
        FROM patients 
        WHERE patient_id = ?
        LIMIT 1
    ");
    $stmtPatient->execute([$patientId]);
    $patient = $stmtPatient->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Patient record not found.'
        ]);
        exit;
    }

    // Compute patient age
    $age = 'N/A';
    if (!empty($patient['birth_date'])) {
        try {
            $dob = new DateTime($patient['birth_date']);
            $now = new DateTime();
            $age = (string)$now->diff($dob)->y;
        } catch (Exception $e) {
            $age = 'N/A';
        }
    }
    $patient['age'] = $age;

    // 2. Fetch Attending Physician fallback
    $primaryDoc = get_attending_physician($pdo);
    $defaultDoctorName = !empty($primaryDoc['full_name']) ? $primaryDoc['full_name'] : 'Dr. Attending Physician';

    // 3. Build dynamic encounter query with clinical search and date range
    $sql = "
        SELECT 
            e.encounter_id,
            e.patient_id,
            e.queue_number,
            e.encounter_type,
            e.visit_date,
            e.queue_status,
            e.check_in_status,
            e.approval_status,
            e.is_priority,
            e.priority_reason,
            e.complaint,
            e.vital_signs,
            e.diagnostic_notes,
            e.reviewed_by,
            u.full_name AS reviewed_by_name,
            p.prescription_id,
            p.medication_details,
            p.dispense_status
        FROM encounters e
        LEFT JOIN users u ON e.reviewed_by = u.user_id
        LEFT JOIN prescriptions p ON e.encounter_id = p.encounter_id
        WHERE e.patient_id = ?
    ";

    $params = [$patientId];

    // Filter by Date From
    if (!empty($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $sql .= " AND e.visit_date >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }

    // Filter by Date To
    if (!empty($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $sql .= " AND e.visit_date <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }

    // Keyword Search in clinical fields (diagnosis, complaint, prescription medications)
    if (!empty($search)) {
        $sql .= " AND (
            e.diagnostic_notes LIKE ? OR 
            e.complaint LIKE ? OR 
            p.medication_details LIKE ? OR
            e.queue_number LIKE ?
        )";
        $wildcard = '%' . $search . '%';
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
    }

    $sql .= " ORDER BY DATE(e.visit_date) DESC, e.encounter_id DESC";

    $stmtEnc = $pdo->prepare($sql);
    $stmtEnc->execute($params);
    $rawEncounters = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

    // Format each encounter record
    $timeline = [];
    foreach ($rawEncounters as $row) {
        $vitals = [];
        if (!empty($row['vital_signs'])) {
            $vitals = json_decode($row['vital_signs'], true) ?: [];
        }

        $medications = [];
        if (!empty($row['medication_details'])) {
            $medications = json_decode($row['medication_details'], true) ?: [];
        }

        $doctorName = !empty($row['reviewed_by_name']) ? $row['reviewed_by_name'] : $defaultDoctorName;

        $timeline[] = [
            'encounter_id'     => (int)$row['encounter_id'],
            'queue_number'     => $row['queue_number'],
            'encounter_type'   => $row['encounter_type'],
            'visit_date'       => $row['visit_date'],
            'visit_date_human' => date('F j, Y - g:i A', strtotime($row['visit_date'])),
            'queue_status'     => $row['queue_status'],
            'check_in_status'  => $row['check_in_status'],
            'approval_status'  => $row['approval_status'],
            'is_priority'      => (bool)$row['is_priority'],
            'priority_reason'  => $row['priority_reason'] ?? '',
            'complaint'        => $row['complaint'] ?: 'None specified',
            'diagnostic_notes' => $row['diagnostic_notes'] ?: 'No diagnostic notes encoded.',
            'vital_signs'      => [
                'bp'     => $vitals['bp'] ?? 'N/A',
                'temp'   => !empty($vitals['temp']) ? ($vitals['temp'] . ' °C') : 'N/A',
                'weight' => !empty($vitals['weight']) ? ($vitals['weight'] . ' kg') : 'N/A',
                'height' => !empty($vitals['height']) ? ($vitals['height'] . ' cm') : 'N/A',
                'pulse'  => !empty($vitals['pulse']) ? ($vitals['pulse'] . ' bpm') : 'N/A',
                'rr'     => !empty($vitals['rr']) ? ($vitals['rr'] . ' cpm') : 'N/A',
            ],
            'prescriptions'    => [
                'prescription_id' => $row['prescription_id'] ? (int)$row['prescription_id'] : null,
                'items'           => $medications,
                'dispense_status' => $row['dispense_status'] ?? 'N/A'
            ],
            'attending_doctor' => $doctorName
        ];
    }

    // 4. Audit Log the Access (Clinical Record Privacy Compliance)
    audit_log(
        'Patient_History_View',
        "Doctor accessed clinical consultation history for patient: {$patient['full_name']} (Patient ID: #{$patientId})",
        'Patient',
        (string)$patientId,
        [
            'patient_id'   => $patientId,
            'records_read' => count($timeline),
            'filter_query' => $search ?: null,
            'date_range'   => ($dateFrom || $dateTo) ? "{$dateFrom} to {$dateTo}" : 'All-time'
        ]
    );

    echo json_encode([
        'success'      => true,
        'patient'      => $patient,
        'total_visits' => count($timeline),
        'encounters'   => $timeline
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error querying medical history: ' . $e->getMessage()
    ]);
}
