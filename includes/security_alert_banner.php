<?php
/**
 * includes/security_alert_banner.php - In-App Real-Time Security Alert Notifier
 * Paikit Health Center Information System
 * Compliant with Data Privacy Act of 2012 (RA 10173)
 */

if (!function_exists('is_logged_in') || !is_logged_in()) {
    return;
}

if (!isset($pdo)) {
    $pdo = get_db();
}

$currentUserId = (int)$_SESSION['user_id'];
$unreadAlerts = get_unread_security_alerts($pdo, $currentUserId);
?>

<!-- REAL-TIME SECURITY ALERT BANNER & MODAL CONTAINER -->
<div id="securityAlertsContainer" class="px-3 pt-3" style="position: relative; z-index: 1040;">
    <?php if (!empty($unreadAlerts)): ?>
        <?php foreach ($unreadAlerts as $secAlert): ?>
            <div class="alert alert-danger border-2 border-danger alert-dismissible fade show shadow-sm mb-3 rounded-3" 
                 id="security-alert-card-<?= (int)$secAlert['alert_id'] ?>" 
                 role="alert" 
                 style="background: #fff5f5; border-color: #ef4444 !important;">
                <div class="d-flex align-items-start gap-3">
                    <div class="bg-danger text-white rounded-circle p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                        <i class="bi bi-shield-slash-fill fs-5"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <span class="badge bg-danger text-uppercase fw-bold px-2 py-1">Security Alert</span>
                            <span class="text-danger fw-bold fs-6">Hinarang na Pag-login sa Ibang Device</span>
                            <span class="text-muted small ms-auto"><i class="bi bi-clock me-1"></i><?= date('M d, Y h:i A', strtotime($secAlert['created_at'])) ?></span>
                        </div>
                        <p class="mb-2 text-dark small" style="line-height: 1.5;">
                            May nagtangkang mag-log in sa iyong account gamit ang ibang device (<strong><?= sanitize($secAlert['attempt_device'] ?: 'Unknown Device') ?></strong>, IP Address: <code><?= sanitize($secAlert['attempt_ip'] ?: '127.0.0.1') ?></code>). 
                            <strong>HINARANG ng system ang pag-login</strong> upang protektahan ang iyong data dahil kasalukuyan kang naka-log in sa device na ito.
                        </p>
                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-danger fw-bold rounded-pill px-3 shadow-sm" onclick="openSettingsModal('security'); dismissSecurityAlert(<?= (int)$secAlert['alert_id'] ?>);">
                                <i class="bi bi-key-fill me-1"></i> Magpalit Kaagad ng Password
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="dismissSecurityAlert(<?= (int)$secAlert['alert_id'] ?>);">
                                <i class="bi bi-check2 me-1"></i> Naintindihan ko / Dismiss
                            </button>
                        </div>
                    </div>
                    <button type="button" class="btn-close" onclick="dismissSecurityAlert(<?= (int)$secAlert['alert_id'] ?>)" aria-label="Close"></button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Dynamic Modal for Real-Time Detection -->
<div class="modal fade" id="liveSecurityAlertModal" tabindex="-1" aria-labelledby="liveSecurityAlertModalLabel" aria-hidden="true" data-bs-backdrop="static" style="z-index: 1070;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger shadow-lg">
            <div class="modal-header bg-danger text-white py-3">
                <h5 class="modal-title fw-bold" id="liveSecurityAlertModalLabel">
                    <i class="bi bi-shield-slash-fill me-2"></i> Babala sa Seguridad: Hinarang na Pag-login
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-danger-subtle text-danger rounded-circle" style="width: 64px; height: 64px;">
                        <i class="bi bi-exclamation-triangle-fill fs-2"></i>
                    </span>
                </div>
                <h6 class="fw-bold text-danger mb-2">May nagtangkang mag-log in sa iyong account!</h6>
                <p class="text-secondary small mb-3" id="liveSecAlertDetails">
                    May ibang device na sumubok pumasok sa iyong account. Hinarang ito ng system dahil kasalukuyan kang naka-log in dito sa device na ito.
                </p>
                <div class="alert alert-light border small text-start p-3 mb-3 bg-light">
                    <div class="mb-1"><strong>Device:</strong> <span id="liveSecAlertDevice">-</span></div>
                    <div class="mb-1"><strong>IP Address:</strong> <code id="liveSecAlertIp">-</code></div>
                    <div><strong>Oras:</strong> <span id="liveSecAlertTime">-</span></div>
                </div>
                <div class="alert alert-warning border-0 small text-start p-2 mb-3">
                    <i class="bi bi-info-circle-fill text-warning me-1"></i>
                    <strong>Payo:</strong> Kung hindi ikaw ito, maaaring may nakakaalam ng iyong password. Magpalit agad ng password upang manatiling ligtas ang iyong account.
                </div>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-danger fw-bold py-2 rounded-pill shadow-sm" id="btnLiveChangePwd">
                        <i class="bi bi-shield-lock-fill me-1"></i> Magpalit Kaagad ng Password
                    </button>
                    <button type="button" class="btn btn-outline-secondary py-2 rounded-pill" data-bs-dismiss="modal" id="btnLiveDismiss">
                        Naintindihan ko (Dismiss)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    let activeAlertId = null;

    window.dismissSecurityAlert = function(alertId) {
        const card = document.getElementById('security-alert-card-' + alertId);
        if (card) {
            card.classList.remove('show');
            setTimeout(() => card.remove(), 250);
        }

        // Send dismissal request to API
        const apiPath = window.location.pathname.includes('/doctor/') || window.location.pathname.includes('/staff/') || window.location.pathname.includes('/client/')
            ? '../api/security_alerts.php'
            : 'api/security_alerts.php';

        const formData = new FormData();
        formData.append('action', 'dismiss');
        formData.append('alert_id', alertId);

        fetch(apiPath, {
            method: 'POST',
            body: formData
        }).catch(err => console.warn('Failed to dismiss alert:', err));
    };

    // Real-Time Poller (every 25 seconds) to catch concurrent login attempts while user is active
    function checkLiveSecurityAlerts() {
        const apiPath = window.location.pathname.includes('/doctor/') || window.location.pathname.includes('/staff/') || window.location.pathname.includes('/client/')
            ? '../api/security_alerts.php?action=check'
            : 'api/security_alerts.php?action=check';

        fetch(apiPath)
            .then(res => res.json())
            .then(data => {
                if (data && data.success && data.has_alert && data.alerts && data.alerts.length > 0) {
                    const latest = data.alerts[0];
                    if (activeAlertId !== latest.alert_id) {
                        activeAlertId = latest.alert_id;
                        showLiveSecurityModal(latest);
                    }
                }
            })
            .catch(err => {
                // Non-critical background ping
            });
    }

    function showLiveSecurityModal(alertData) {
        const modalEl = document.getElementById('liveSecurityAlertModal');
        if (!modalEl) return;

        const devEl  = document.getElementById('liveSecAlertDevice');
        const ipEl   = document.getElementById('liveSecAlertIp');
        const timeEl = document.getElementById('liveSecAlertTime');
        const btnChange = document.getElementById('btnLiveChangePwd');
        const btnDismiss = document.getElementById('btnLiveDismiss');

        if (devEl) devEl.textContent = alertData.attempt_device || 'Unknown Device';
        if (ipEl) ipEl.textContent = alertData.attempt_ip || 'Unknown IP';
        if (timeEl) timeEl.textContent = alertData.created_at || 'Just now';

        if (btnChange) {
            btnChange.onclick = function() {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                dismissSecurityAlert(alertData.alert_id);
                if (typeof openSettingsModal === 'function') {
                    openSettingsModal('security');
                }
            };
        }

        if (btnDismiss) {
            btnDismiss.onclick = function() {
                dismissSecurityAlert(alertData.alert_id);
            };
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    // Start background poller
    setInterval(checkLiveSecurityAlerts, 25000);
})();
</script>
