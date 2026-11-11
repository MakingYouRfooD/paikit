<?php
/**
 * queue_monitor.php - Public Queue Display Monitor
 * Paikit Health Center Information System
 * Modern, Eye-Friendly Healthcare Display UI (RA 10173 Protected)
 */

require_once __DIR__ . '/db.php';

// Support AJAX polling for live refresh
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd   = date('Y-m-d 23:59:59');

    // Currently Serving
    $stmtServing = $pdo->prepare("
        SELECT e.encounter_id, e.queue_number, p.full_name, e.visit_date 
        FROM encounters e
        JOIN patients p ON e.patient_id = p.patient_id
        WHERE e.visit_date BETWEEN ? AND ? AND e.queue_status = 'Serving'
        ORDER BY e.encounter_id DESC LIMIT 1
    ");
    $stmtServing->execute([$todayStart, $todayEnd]);
    $serving = $stmtServing->fetch();

    function mask_name(?string $name): string {
        if (!$name) return 'Walk-in Patient';
        $parts = explode(' ', trim($name));
        $masked = [];
        foreach ($parts as $p) {
            $len = mb_strlen($p);
            if ($len <= 2) {
                $masked[] = $p[0] . '*';
            } else {
                $masked[] = mb_substr($p, 0, 1) . str_repeat('*', min(4, $len - 1));
            }
        }
        return implode(' ', $masked);
    }

    $servingData = null;
    if ($serving) {
        $servingData = [
            'queue_number' => $serving['queue_number'],
            'masked_name'  => mask_name($serving['full_name'])
        ];
    }

    // Waiting queue (Fair Interleaved 2:1 Ratio & Priority-First, Arrived Only)
    $waitingRows = get_interleaved_queue($pdo, $todayStart, $todayEnd);
    $waitingData = [];
    foreach (array_slice($waitingRows, 0, 10) as $row) {
        $typeLabel = !empty($row['is_priority']) ? 'Priority' : $row['encounter_type'];
        $waitingData[] = [
            'queue_number' => $row['queue_number'],
            'masked_name'  => mask_name($row['full_name']),
            'type'         => $typeLabel
        ];
    }

    // Completed recently
    $stmtCompleted = $pdo->prepare("
        SELECT e.queue_number, p.full_name
        FROM encounters e
        JOIN patients p ON e.patient_id = p.patient_id
        WHERE e.visit_date BETWEEN ? AND ? AND e.queue_status = 'Completed'
        ORDER BY e.encounter_id DESC LIMIT 6
    ");
    $stmtCompleted->execute([$todayStart, $todayEnd]);
    $completedRows = $stmtCompleted->fetchAll();
    $completedData = [];
    foreach ($completedRows as $row) {
        $completedData[] = [
            'queue_number' => $row['queue_number'],
            'masked_name'  => mask_name($row['full_name'])
        ];
    }

    echo json_encode([
        'serving'   => $servingData,
        'waiting'   => $waitingData,
        'completed' => $completedData,
        'time'      => date('h:i:s A'),
        'date'      => date('l, F j, Y')
    ]);
    exit;
}

$today = date('Y-m-d');
$attendingDoctor = get_attending_physician($pdo);
$stmtSched = $pdo->prepare("SELECT * FROM doctor_schedules WHERE duty_date = ? ORDER BY schedule_id DESC LIMIT 1");
$stmtSched->execute([$today]);
$schedule = $stmtSched->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Queue Display - Paikit Health Center</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/modern-theme.css">
    <script src="js/accessibility.js"></script>
    <style>
        .header-bar {
            background: linear-gradient(135deg, #0e391e 0%, #14532d 50%, #166534 100%) !important;
            border-bottom: 3px solid #22c55e !important;
        }
        .monitor-card {
            background-color: #ffffff;
            border: 2px solid #86efac;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(20, 83, 45, 0.08);
        }
        .ticket-number-huge {
            font-size: 6.8rem;
            font-weight: 900;
            color: #dc2626;
            letter-spacing: 2px;
            line-height: 1;
            margin: 1.5rem 0;
            text-shadow: 0 2px 4px rgba(220, 38, 38, 0.15);
        }
        .pill-now-serving {
            background-color: #fee2e2;
            color: #b91c1c;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
            padding: 0.4rem 1.2rem;
            border-radius: 50rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .pulse-dot {
            width: 10px;
            height: 10px;
            background-color: #dc2626;
            border-radius: 50%;
            display: inline-block;
            animation: pulse-ring 1.8s infinite;
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.9); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.4; }
            100% { transform: scale(0.9); opacity: 1; }
        }
        .queue-table th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            border: none;
            padding: 0.75rem 1rem;
        }
        .queue-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .badge-type-appointment {
            background-color: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
            font-size: 0.75rem;
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
        }
        .badge-type-walkin {
            background-color: #fef3c7;
            color: #b45309;
            font-weight: 600;
            font-size: 0.75rem;
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
        }
        .completed-pill {
            background-color: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<!-- Header Navigation Bar with Paikit Official Logo -->
<header class="header-bar py-3 px-4 shadow-sm text-white">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <img src="img/logo.png" alt="Paikit Logo" class="brand-logo-circle" style="width: 52px; height: 52px; border: 2.5px solid #ffffff;">
            <div>
                <h4 class="fw-bold mb-0 text-white" style="letter-spacing: 0.5px;">PAIKIT HEALTH CENTER</h4>
                <div class="text-white-50 small">RURAL HEALTH UNIT &bull; BARANGAY CANLUBANG QUEUE DISPLAY</div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3">
            <button type="button" class="senior-toggle-btn" onclick="toggleSeniorMode()" title="Toggle larger text for seniors">
                <i class="bi bi-fonts fs-6"></i> <strong>A+</strong> Senior Text
            </button>
            <div class="text-end">
                <div class="fs-3 fw-bold text-white mb-0" id="clockTime"><?= date('h:i:s A') ?></div>
                <div class="text-white-50 small" id="clockDate"><?= date('l, F j, Y') ?></div>
            </div>
        </div>
    </div>
</header>

<!-- Sub-header Clinic Details -->
<div class="bg-white py-2 px-4 border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center small">
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Physician On Duty:</span>
            <strong class="text-dark"><?= sanitize($attendingDoctor['full_name']) ?></strong>
            <span class="badge bg-primary-subtle text-primary border ms-1">Consultation Room 1</span>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div>
                <span class="text-muted">Clinic Consultation Hours:</span>
                <strong class="text-dark ms-1">
                    <?= $schedule ? date('g:i A', strtotime($schedule['start_time'])) . ' - ' . date('g:i A', strtotime($schedule['end_time'])) : '08:00 AM - 05:00 PM' ?>
                </strong>
            </div>
            <span class="badge bg-success-subtle text-success border">
                <i class="bi bi-shield-check me-1"></i> RA 10173 Data Privacy Protected
            </span>
        </div>
    </div>
</div>

<!-- Main Display Body -->
<main class="container-fluid p-4 flex-grow-1">
    <div class="row g-4 h-100">
        <!-- Left: Now Serving Hero Card -->
        <div class="col-lg-7 d-flex flex-column">
            <div class="monitor-card p-5 text-center flex-grow-1 d-flex flex-column justify-content-center">
                <div>
                    <span class="pill-now-serving mb-2">
                        <span class="pulse-dot"></span>
                        NOW SERVING &bull; ROOM 1
                    </span>
                </div>

                <div class="ticket-number-huge" id="servingTicket">STANDBY</div>

                <div>
                    <div class="text-muted small text-uppercase fw-bold mb-1">PATIENT CALL</div>
                    <div class="fs-2 fw-bold text-dark" id="servingName">Next Patient Preparing</div>
                </div>

                <div class="mt-4 pt-4 border-top text-muted small d-flex justify-content-center align-items-center gap-2">
                    <i class="bi bi-bell-fill text-warning"></i>
                    <span>Please proceed to Consultation Room 1 when your ticket number appears above.</span>
                </div>
            </div>
        </div>

        <!-- Right: Upcoming Queue Table & Recent Completed -->
        <div class="col-lg-5 d-flex flex-column gap-3">
            <!-- Upcoming Queue Card -->
            <div class="monitor-card p-4 flex-grow-1 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center pb-3 mb-2 border-bottom">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-hourglass-split text-primary me-2"></i> Upcoming Queue
                    </h5>
                    <span class="badge bg-light text-primary border fw-bold px-3 py-2" id="waitingCount">0 Waiting</span>
                </div>

                <div class="table-responsive flex-grow-1">
                    <table class="table queue-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>TICKET #</th>
                                <th>PATIENT NAME</th>
                                <th class="text-end">CATEGORY</th>
                            </tr>
                        </thead>
                        <tbody id="waitingBody">
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">Checking queue status...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recently Completed Card -->
            <div class="monitor-card p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold text-dark small">
                        <i class="bi bi-check-circle-fill text-success me-1"></i> RECENTLY CONSULTED
                    </span>
                    <span class="text-muted" style="font-size: 0.78rem;">Please proceed to Pharmacy for medicines</span>
                </div>
                <div class="d-flex gap-2 flex-wrap" id="completedBadges">
                    <span class="text-muted small">None yet today</span>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Footer -->
<footer class="bg-white py-2 px-4 border-top small text-muted">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <div>
            Paikit Health Center &bull; Data Privacy Compliant: Patient names are masked in compliance with RA 10173 regulations.
        </div>
        <div class="d-flex gap-3 align-items-center">
            <span class="badge bg-success-subtle text-success border">Live Auto-Refresh Active</span>
            <a href="./" class="text-primary text-decoration-none fw-bold">Return to Portal Home &rarr;</a>
        </div>
    </div>
</footer>

<script>
    let lastServing = '';

    async function pollQueue() {
        try {
            const res = await fetch('queue_monitor?ajax=1');
            const data = await res.json();

            // Update Time
            document.getElementById('clockTime').textContent = data.time;
            document.getElementById('clockDate').textContent = data.date;

            // Update Serving
            const ticketEl = document.getElementById('servingTicket');
            const nameEl = document.getElementById('servingName');

            if (data.serving) {
                ticketEl.textContent = data.serving.queue_number;
                nameEl.textContent = data.serving.masked_name;

                if (lastServing !== data.serving.queue_number) {
                    lastServing = data.serving.queue_number;
                    playChime();
                }
            } else {
                ticketEl.textContent = 'STANDBY';
                nameEl.textContent = 'Next Patient Preparing';
            }

            // Update Waiting List
            const waitingBody = document.getElementById('waitingBody');
            document.getElementById('waitingCount').textContent = data.waiting.length + ' Patients Waiting';

            if (data.waiting.length === 0) {
                waitingBody.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-muted small">No patients waiting in queue.</td></tr>';
            } else {
                let html = '';
                data.waiting.forEach((item) => {
                    const badgeClass = (item.type === 'Appointment') ? 'badge-type-appointment' : 'badge-type-walkin';
                    html += `
                        <tr>
                            <td><span class="fs-5 fw-bold text-dark">${escapeHtml(item.queue_number)}</span></td>
                            <td class="text-dark fw-medium">${escapeHtml(item.masked_name)}</td>
                            <td class="text-end"><span class="${badgeClass}">${escapeHtml(item.type)}</span></td>
                        </tr>
                    `;
                });
                waitingBody.innerHTML = html;
            }

            // Update Completed List
            const compEl = document.getElementById('completedBadges');
            if (data.completed.length === 0) {
                compEl.innerHTML = '<span class="text-muted small">None yet today</span>';
            } else {
                let compHtml = '';
                data.completed.forEach(item => {
                    compHtml += `<span class="completed-pill">${escapeHtml(item.queue_number)}</span>`;
                });
                compEl.innerHTML = compHtml;
            }

        } catch (err) {
            console.error('Queue poll error:', err);
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, m => map[m]);
    }

    function playChime() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(523.25, ctx.currentTime); // C5
            osc.frequency.setValueAtTime(659.25, ctx.currentTime + 0.18); // E5
            gain.gain.setValueAtTime(0.15, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.005, ctx.currentTime + 0.65);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.65);
        } catch (e) {}
    }

    pollQueue();
    setInterval(pollQueue, 3500);
</script>
</body>
</html>
