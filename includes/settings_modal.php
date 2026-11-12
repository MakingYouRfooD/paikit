<?php
/**
 * includes/settings_modal.php - Unified Account Settings & Security Modal Component
 * Renders Profile and Change Password Tabs with 2-Step Email Verification Code
 * Compatible with Doctor, Staff, and Client roles under RA 10173.
 */

if (!isset($pdo)) {
    $pdo = get_db();
}

$mUserId = $_SESSION['user_id'] ?? null;
if (!$mUserId) {
    return;
}

$stmtModalU = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmtModalU->execute([$mUserId]);
$modalUser = $stmtModalU->fetch();
if (!$modalUser) {
    return;
}

$modalPatient = null;
if (!empty($modalUser['patient_id'])) {
    $stmtModalP = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmtModalP->execute([$modalUser['patient_id']]);
    $modalPatient = $stmtModalP->fetch();
}

$mRole = $modalUser['role']; // 'Doctor', 'Staff', 'Client'
$mEmail = !empty($modalUser['email']) ? $modalUser['email'] : (!empty($modalPatient['email']) ? $modalPatient['email'] : '');
$mFullName = !empty($modalUser['full_name']) ? $modalUser['full_name'] : (!empty($modalPatient['full_name']) ? $modalPatient['full_name'] : $modalUser['username']);
$mMaskedEmail = function_exists('mask_email') ? mask_email($mEmail) : $mEmail;

$modalMailConfig = [];
if ($mRole === 'Staff') {
    if (!function_exists('get_mail_config')) {
        require_once __DIR__ . '/../config/mail.php';
    }
    $modalMailConfig = get_mail_config();
}

// Include real-time security alert banner & live modal
require_once __DIR__ . '/security_alert_banner.php';
?>

<!-- UNIFIED ACCOUNT SETTINGS & SECURITY MODAL -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <!-- Modal Header -->
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #15803d 0%, #166534 100%);">
                <div>
                    <h5 class="modal-title fw-bold" id="settingsModalLabel">
                        <i class="bi bi-gear-wide-connected me-2"></i> Account Settings & Security
                    </h5>
                    <div class="small text-white-50">Manage your profile details, security credentials, and system settings</div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-0">
                <!-- Nav Tabs -->
                <ul class="nav nav-tabs nav-fill bg-light border-bottom px-3 pt-2" id="settingsTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold text-dark py-3" id="profile-tab" data-bs-toggle="tab" data-bs-target="#tab-profile" type="button" role="tab" aria-controls="tab-profile" aria-selected="true">
                            <i class="bi bi-person-lines-fill text-primary me-2 fs-5"></i> Profile Details
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold text-dark py-3" id="security-tab" data-bs-toggle="tab" data-bs-target="#tab-security" type="button" role="tab" aria-controls="tab-security" aria-selected="false">
                            <i class="bi bi-shield-lock-fill text-success me-2 fs-5"></i> Change Password & Security
                        </button>
                    </li>
                </ul>

                <div class="tab-content p-4" id="settingsTabContent">
                    
                    <!-- TAB 1: PROFILE DETAILS -->
                    <div class="tab-pane fade show active" id="tab-profile" role="tabpanel" aria-labelledby="profile-tab">
                        <?php if ($mRole === 'Client' && $modalPatient): ?>
                            <!-- Client / Patient Profile Overview -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="text-muted small fw-bold text-uppercase">Full Legal Name</div>
                                        <div class="fw-bold fs-6 text-dark mt-1"><?= sanitize($modalPatient['full_name']) ?></div>
                                        <div class="text-secondary small">Patient ID: <span class="badge bg-secondary">P-<?= str_pad($modalPatient['patient_id'], 5, '0', STR_PAD_LEFT) ?></span></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="text-muted small fw-bold text-uppercase">Registered Portal Email</div>
                                        <div class="fw-bold fs-6 text-dark mt-1 text-truncate">
                                            <?= sanitize($mEmail ?: 'No email registered') ?>
                                        </div>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle mt-1">
                                            <i class="bi bi-patch-check-fill me-1"></i> RA 10173 Verified Email
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="text-muted small fw-bold text-uppercase">Birth Date & Age</div>
                                        <div class="fw-bold text-dark mt-1">
                                            <?= !empty($modalPatient['birth_date']) ? date('M d, Y', strtotime($modalPatient['birth_date'])) : 'N/A' ?>
                                        </div>
                                        <div class="text-secondary small">
                                            <?= !empty($modalPatient['birth_date']) ? (new DateTime())->diff(new DateTime($modalPatient['birth_date']))->y : 0 ?> years old
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="text-muted small fw-bold text-uppercase">Gender & Civil Status</div>
                                        <div class="fw-bold text-dark mt-1">
                                            <?= sanitize($modalPatient['gender']) ?> / <?= sanitize($modalPatient['civil_status'] ?? 'Single') ?>
                                        </div>
                                        <div class="text-secondary small">Demographic Record</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="text-muted small fw-bold text-uppercase">PhilHealth ID</div>
                                        <div class="fw-bold text-dark mt-1">
                                            <?= sanitize($modalPatient['philhealth_number'] ?? 'N/A') ?>
                                        </div>
                                        <div class="text-secondary small">National Insurance</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Client Contact Update Form -->
                            <div class="card border-0 bg-light p-3 rounded-3">
                                <h6 class="fw-bold text-primary mb-3">
                                    <i class="bi bi-pencil-square me-1"></i> Update Contact & Address Information
                                </h6>
                                <form method="POST" action="dashboard" id="updateContactForm" onsubmit="return validateUpdateContactForm(event)">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-5">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label class="form-label small fw-bold mb-0">Mobile / Contact Number</label>
                                                <span class="badge bg-light text-muted border" id="updateMobileDigitCount">0 / 11 digits</span>
                                            </div>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                                <input type="tel" name="contact_number" id="updateContactNumber" class="form-control" 
                                                       value="<?= sanitize($modalPatient['contact_number'] ?? ($modalPatient['mobile_number'] ?? '')) ?>" 
                                                       placeholder="09171234567" required maxlength="11" minlength="11" 
                                                       pattern="(09|08)[0-9]{9}" 
                                                       title="Must be an 11-digit Philippine registered mobile number (e.g. 09171234567)" 
                                                       oninput="handleUpdateMobileInput(this)">
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mt-1">
                                                <span class="form-text small text-muted" style="font-size: 0.72rem;">Globe, Smart, TM, TNT, Sun, DITO</span>
                                                <span id="updateTelcoNetworkBadge" class="badge bg-light text-secondary border small" style="font-size: 0.72rem;">Enter PH Mobile</span>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-7">
                                            <label class="form-label small fw-bold">Sitio / Area in Canlubang</label>
                                            <select name="sitio" class="form-select" required>
                                                <option value="">-- Select Official Canlubang Area --</option>
                                                <?php
                                                $canlubangSitios = [
                                                    'Asia 1', 'Asia 2', 'Carmel Housing', 'Ceris 1 & 2', 'Ceris 1', 'Ceris 2', 
                                                    'GK', 'Manfil', 'Mcdc', 'Paikit', 'Palao', 'Kapayapaan', 'Purok 1', 'Purok 2', 'Purok 3'
                                                ];
                                                $currSitio = strtolower(trim($modalPatient['sitio'] ?? ''));
                                                foreach ($canlubangSitios as $s):
                                                    $selected = (strtolower($s) === $currSitio) ? 'selected' : '';
                                                ?>
                                                    <option value="<?= htmlspecialchars($s) ?>" <?= $selected ?>><?= htmlspecialchars($s) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label small fw-bold">Street Address / House No.</label>
                                            <input type="text" name="street_address" class="form-control" 
                                                   value="<?= sanitize($modalPatient['street_address'] ?? '') ?>" 
                                                   placeholder="House / Block / Lot / Street Name" required>
                                        </div>

                                        <div class="col-12 text-end mt-3">
                                            <button type="submit" class="btn btn-primary btn-sm fw-bold px-3">
                                                <i class="bi bi-check2-circle me-1"></i> Save Contact Updates
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                        <?php else: ?>
                            <!-- Doctor / Staff Personnel Profile Overview -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="text-muted small fw-bold text-uppercase">Personnel Name</div>
                                        <div class="fw-bold fs-6 text-dark mt-1"><?= sanitize($mFullName) ?></div>
                                        <span class="badge <?= $mRole === 'Doctor' ? 'bg-primary' : 'bg-success' ?> mt-1">
                                            <i class="bi <?= $mRole === 'Doctor' ? 'bi-heart-pulse-fill' : 'bi-hospital-fill' ?> me-1"></i>
                                            <?= sanitize($mRole) ?> Clearance (RA 10173)
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="text-muted small fw-bold text-uppercase">Authorized Email Address</div>
                                        <div class="fw-bold fs-6 text-dark mt-1 text-truncate">
                                            <?= sanitize($mEmail ?: 'No email registered') ?>
                                        </div>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle mt-1">
                                            <i class="bi bi-shield-check me-1"></i> Official Health Staff Account
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="text-muted small fw-bold text-uppercase">Username</div>
                                        <div class="fw-bold text-dark mt-1"><?= sanitize($modalUser['username']) ?></div>
                                        <div class="text-secondary small">System Login ID</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="text-muted small fw-bold text-uppercase">Health Center Station</div>
                                        <div class="fw-bold text-dark mt-1">Barangay Canlubang, Calamba City</div>
                                        <div class="text-secondary small"><?= $mRole === 'Doctor' ? 'Consultation Room 1' : 'Triage & Reception Desk' ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Doctor / Staff Profile Update Form -->
                            <div class="card border-0 bg-light p-3 rounded-3">
                                <h6 class="fw-bold text-primary mb-3">
                                    <i class="bi bi-pencil-square me-1"></i> Update Personnel Information
                                </h6>
                                <form method="POST" action="dashboard">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Full Name & Title</label>
                                            <input type="text" name="full_name" class="form-control" value="<?= sanitize($mFullName) ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Official Email Address</label>
                                            <input type="email" name="email" class="form-control" value="<?= sanitize($mEmail) ?>" required>
                                        </div>
                                        <div class="col-12 text-end mt-3">
                                            <button type="submit" class="btn btn-primary btn-sm fw-bold px-3">
                                                <i class="bi bi-check2-circle me-1"></i> Save Personnel Profile
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 2: CHANGE PASSWORD & SECURITY -->
                    <div class="tab-pane fade" id="tab-security" role="tabpanel" aria-labelledby="security-tab">
                        <!-- Active Device & Session Security Status -->
                        <div class="card border-0 bg-light p-3 rounded-3 mb-4">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="bg-success text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <i class="bi bi-display fs-5"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark">Currently Active Device</div>
                                        <div class="text-secondary small">
                                            <strong><?= sanitize(get_device_description()) ?></strong> (Your Device) &bull; IP: <code><?= sanitize(get_client_ip()) ?></code>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge bg-success-subtle text-success border border-success px-3 py-2 rounded-pill small">
                                        <i class="bi bi-shield-lock-fill me-1"></i> Protected: Single Device Active
                                    </span>
                                </div>
                            </div>
                            <div class="small text-muted mt-2 pt-2 border-top">
                                <i class="bi bi-info-circle me-1"></i> Concurrent logins across multiple devices are prohibited. If another device attempts to sign in to your account, it will automatically be blocked, and you will receive a security alert email and on-screen notification.
                            </div>
                        </div>

                        <div class="alert alert-info border-0 rounded-3 mb-4 d-flex align-items-start">
                            <i class="bi bi-shield-check fs-3 me-3 text-primary flex-shrink-0 mt-1"></i>
                            <div>
                                <strong class="d-block mb-1">Two-Step Email Security Verification (RA 10173)</strong>
                                <span class="small">
                                    To protect clinical records and your account privacy, changing your password requires verifying a 6-digit numeric security code sent to your registered email address:
                                    <strong><?= sanitize($mMaskedEmail) ?></strong>.
                                </span>
                            </div>
                        </div>

                        <!-- Step 1: Request Code -->
                        <div class="card border-0 bg-light p-3 rounded-3 mb-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div>
                                    <div class="fw-bold text-dark">Step 1: Request Security Verification Code</div>
                                    <div class="text-secondary small">Click the button below to generate and send a 6-digit code to your email.</div>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-success fw-bold px-3 py-2 rounded-pill shadow-sm" id="btnSendPwdCode" onclick="sendPasswordVerificationCode();">
                                        <i class="bi bi-envelope-arrow-up me-1" id="iconSendPwdCode"></i>
                                        <span id="textSendPwdCode">Send Code to Email</span>
                                    </button>
                                </div>
                            </div>
                            <div id="pwdCodeAlertBox" class="mt-3 d-none"></div>
                        </div>

                        <!-- Step 2: Password Change Form -->
                        <form method="POST" action="dashboard" id="formChangePassword" onsubmit="return validateChangePasswordForm(event);">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="change_password">

                            <div class="fw-bold text-dark mb-3">Step 2: Enter Verification Code & New Password</div>

                            <div class="row g-3">
                                <!-- Current Password -->
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Current Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" name="current_password" id="currentPwdInput" class="form-control" placeholder="Enter current password" required autocomplete="current-password">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('currentPwdInput', this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- 6-Digit Code -->
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">6-Digit Email Code <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-key-fill text-warning"></i></span>
                                        <input type="text" name="verification_code" id="verifyCodeInput" class="form-control text-center fw-bold fs-5" 
                                               style="letter-spacing: 4px; font-family: monospace;" 
                                               placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autocomplete="one-time-code"
                                               inputmode="numeric"
                                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);"
                                               onkeypress="return (event.charCode >= 48 && event.charCode <= 57);"
                                               onpaste="setTimeout(() => { this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6); }, 10);"
                                               title="Please enter numbers only (6 digits)">
                                    </div>
                                    <div class="form-text small">Enter the 6-digit numeric code received in your email.</div>
                                </div>

                                <!-- New Password -->
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">New Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" name="new_password" id="newPwdInput" class="form-control" 
                                               placeholder="Enter new password" required autocomplete="new-password"
                                               oninput="checkChangePasswordRules();">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('newPwdInput', this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Confirm New Password -->
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Confirm New Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" id="confirmPwdInput" class="form-control" 
                                               placeholder="Re-type new password" required autocomplete="new-password"
                                               oninput="checkChangePasswordRules();">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('confirmPwdInput', this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Real-Time Password Security Requirements Checklist -->
                                <div class="col-12">
                                    <div class="p-2 mb-2 bg-light rounded border" style="font-size: 0.72rem;">
                                        <div class="fw-bold text-muted mb-1"><i class="bi bi-shield-check me-1"></i> PASSWORD SECURITY REQUIREMENTS:</div>
                                        <div class="row g-1">
                                            <div class="col-6" id="changeRuleLength"><i class="bi bi-x-circle text-danger me-1"></i> Min. 8 characters</div>
                                            <div class="col-6" id="changeRuleUpper"><i class="bi bi-x-circle text-danger me-1"></i> Uppercase letter (A-Z)</div>
                                            <div class="col-6" id="changeRuleLower"><i class="bi bi-x-circle text-danger me-1"></i> Lowercase letter (a-z)</div>
                                            <div class="col-6" id="changeRuleNumber"><i class="bi bi-x-circle text-danger me-1"></i> Number (0-9)</div>
                                            <div class="col-6" id="changeRuleSpecial"><i class="bi bi-x-circle text-danger me-1"></i> Special char (@#$!%...)</div>
                                            <div class="col-6" id="changeRuleMatch"><i class="bi bi-x-circle text-danger me-1"></i> Passwords match</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 text-end mt-3">
                                    <button type="button" class="btn btn-secondary btn-sm me-2 rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-success btn-sm fw-bold px-4 rounded-pill shadow-sm" id="btnSubmitChangePwd">
                                        <i class="bi bi-shield-check me-1"></i> Update Password
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JAVASCRIPT LOGIC FOR SETTINGS MODAL & PASSWORD VALIDATION -->
<script>
    const updateGlobePrefixes = ['0905','0906','0915','0916','0917','0926','0927','0935','0936','0945','0953','0954','0955','0956','0965','0966','0967','0975','0977','0978','0979','0995','0996','0997','0817'];
    const updateSmartPrefixes = ['0907','0908','0909','0910','0911','0912','0913','0914','0918','0919','0920','0921','0922','0923','0924','0925','0928','0929','0930','0931','0932','0933','0934','0938','0939','0940','0941','0942','0943','0946','0947','0948','0949','0950','0951','0961','0962','0963','0964','0968','0969','0970','0971','0981','0982','0985','0989','0998','0999','0813'];
    const updateDitoPrefixes  = ['0991','0992','0993','0994','0895','0896','0897','0898'];

    function handleUpdateMobileInput(el) {
        if (!el) return;
        el.value = el.value.replace(/[^0-9]/g, '').slice(0, 11);
        const countBadge = document.getElementById('updateMobileDigitCount');
        const telcoBadge = document.getElementById('updateTelcoNetworkBadge');
        const len = el.value.length;

        if (countBadge) {
            countBadge.textContent = len + ' / 11 digits';
            if (len === 11 && (el.value.startsWith('09') || el.value.startsWith('08'))) {
                countBadge.className = 'badge bg-success-subtle text-success border border-success';
            } else {
                countBadge.className = 'badge bg-light text-danger border border-danger';
            }
        }

        if (telcoBadge) {
            if (len >= 4) {
                const p = el.value.substring(0, 4);
                if (updateGlobePrefixes.includes(p)) {
                    telcoBadge.className = 'badge bg-primary text-white border small';
                    telcoBadge.textContent = 'Globe / TM';
                } else if (updateSmartPrefixes.includes(p)) {
                    telcoBadge.className = 'badge bg-success text-white border small';
                    telcoBadge.textContent = 'Smart / TNT / Sun';
                } else if (updateDitoPrefixes.includes(p)) {
                    telcoBadge.className = 'badge bg-danger text-white border small';
                    telcoBadge.textContent = 'DITO Telecommunity';
                } else if (el.value.startsWith('09') || el.value.startsWith('08')) {
                    telcoBadge.className = 'badge bg-info text-dark border small';
                    telcoBadge.textContent = 'PH Mobile Number';
                } else {
                    telcoBadge.className = 'badge bg-danger text-white border small';
                    telcoBadge.textContent = 'Invalid PH Prefix';
                }
            } else {
                telcoBadge.className = 'badge bg-light text-secondary border small';
                telcoBadge.textContent = 'Enter PH Mobile';
            }
        }
    }

    function validateUpdateContactForm(e) {
        const input = document.getElementById('updateContactNumber');
        if (!input) return true;
        const mobile = input.value.trim();
        if (!/^(09|08)[0-9]{9}$/.test(mobile)) {
            alert('Mobile number must be a valid 11-digit Philippine mobile number starting with 09 or 08 (e.g. 09171234567).');
            input.focus();
            if (e) e.preventDefault();
            return false;
        }
        return true;
    }

    function initUpdateMobileValidation() {
        const input = document.getElementById('updateContactNumber');
        if (input) {
            handleUpdateMobileInput(input);
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
        initUpdateMobileValidation();
        if (tabName === 'security') {
            const secTab = document.getElementById('security-tab');
            if (secTab) bootstrap.Tab.getOrCreateInstance(secTab).show();
        } else if (tabName === 'smtp') {
            const smtpTab = document.getElementById('smtp-tab');
            if (smtpTab) bootstrap.Tab.getOrCreateInstance(smtpTab).show();
        } else if (tabName === 'profile') {
            const profTab = document.getElementById('profile-tab');
            if (profTab) bootstrap.Tab.getOrCreateInstance(profTab).show();
        }
    }

    function togglePasswordVisibility(inputId, btn) {
        const el = document.getElementById(inputId);
        if (!el) return;
        const icon = btn ? btn.querySelector('i') : null;
        if (el.type === 'password') {
            el.type = 'text';
            if (icon) {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        } else {
            el.type = 'password';
            if (icon) {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    }

    function updateChangeRuleBadge(id, valid, text) {
        const el = document.getElementById(id);
        if (!el) return;
        if (valid) {
            el.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> ' + text;
        } else {
            el.innerHTML = '<i class="bi bi-x-circle text-danger me-1"></i> ' + text;
        }
    }

    function checkChangePasswordRules() {
        const p1 = document.getElementById('newPwdInput') ? document.getElementById('newPwdInput').value : '';
        const p2 = document.getElementById('confirmPwdInput') ? document.getElementById('confirmPwdInput').value : '';

        const hasLen     = p1.length >= 8;
        const hasUpper   = /[A-Z]/.test(p1);
        const hasLower   = /[a-z]/.test(p1);
        const hasNum     = /[0-9]/.test(p1);
        const hasSpecial = /[\W_]/.test(p1);
        const hasMatch   = (p1.length > 0) && (p1 === p2);

        updateChangeRuleBadge('changeRuleLength', hasLen, 'Min. 8 characters');
        updateChangeRuleBadge('changeRuleUpper', hasUpper, 'Uppercase letter (A-Z)');
        updateChangeRuleBadge('changeRuleLower', hasLower, 'Lowercase letter (a-z)');
        updateChangeRuleBadge('changeRuleNumber', hasNum, 'Number (0-9)');
        updateChangeRuleBadge('changeRuleSpecial', hasSpecial, 'Special char (@#$!%...)');
        updateChangeRuleBadge('changeRuleMatch', hasMatch, 'Passwords match');

        return (hasLen && hasUpper && hasLower && hasNum && hasSpecial && hasMatch);
    }

    function validateChangePasswordForm(e) {
        const cur = document.getElementById('currentPwdInput') ? document.getElementById('currentPwdInput').value.trim() : '';
        const code = document.getElementById('verifyCodeInput') ? document.getElementById('verifyCodeInput').value.trim() : '';
        
        if (!cur) {
            alert('Please enter your Current Password.');
            if (e) e.preventDefault();
            return false;
        }
        if (!code || !/^\d{6}$/.test(code)) {
            alert('Please enter the valid 6-digit verification code sent to your email.');
            if (e) e.preventDefault();
            return false;
        }
        if (!checkChangePasswordRules()) {
            alert('Please ensure your new password satisfies all security requirements and that passwords match.');
            if (e) e.preventDefault();
            return false;
        }
        return true;
    }

    let pwdCooldownTimer = null;
    function startPwdCodeCooldown(seconds) {
        const btn = document.getElementById('btnSendPwdCode');
        const txt = document.getElementById('textSendPwdCode');
        const icon = document.getElementById('iconSendPwdCode');
        if (!btn || !txt) return;

        btn.disabled = true;
        let remaining = seconds;

        if (pwdCooldownTimer) clearInterval(pwdCooldownTimer);
        pwdCooldownTimer = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(pwdCooldownTimer);
                btn.disabled = false;
                txt.textContent = 'Resend Code to Email';
                if (icon) icon.className = 'bi bi-envelope-arrow-up me-1';
            } else {
                txt.textContent = 'Resend in ' + remaining + 's';
            }
        }, 1000);
    }

    function sendPasswordVerificationCode() {
        const btn = document.getElementById('btnSendPwdCode');
        const txt = document.getElementById('textSendPwdCode');
        const icon = document.getElementById('iconSendPwdCode');
        const alertBox = document.getElementById('pwdCodeAlertBox');

        if (btn) btn.disabled = true;
        if (txt) txt.textContent = 'Sending Code...';
        if (icon) icon.className = 'spinner-border spinner-border-sm me-1';

        const formData = new FormData();
        formData.append('csrf_token', '<?= csrf_token() ?>');
        formData.append('action', 'send_password_code');
        formData.append('is_ajax', '1');

        fetch('dashboard', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (alertBox) {
                alertBox.classList.remove('d-none', 'alert-danger', 'alert-success');
                if (data.success) {
                    alertBox.classList.add('alert', 'alert-success', 'border-0', 'p-3', 'rounded-3');
                    alertBox.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>' + data.message;
                    startPwdCodeCooldown(60);
                    const codeInput = document.getElementById('verifyCodeInput');
                    if (codeInput) codeInput.focus();
                } else {
                    alertBox.classList.add('alert', 'alert-danger', 'border-0', 'p-3', 'rounded-3');
                    alertBox.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>' + (data.error || 'Failed to dispatch verification code.');
                    if (btn) btn.disabled = false;
                    if (txt) txt.textContent = 'Send Code to Email';
                    if (icon) icon.className = 'bi bi-envelope-arrow-up me-1';
                }
            }
        })
        .catch(err => {
            if (alertBox) {
                alertBox.classList.remove('d-none', 'alert-success');
                alertBox.classList.add('alert', 'alert-danger', 'border-0', 'p-3', 'rounded-3');
                alertBox.innerHTML = '<i class="bi bi-exclamation-circle-fill me-2"></i>Network error while requesting verification code.';
            }
            if (btn) btn.disabled = false;
            if (txt) txt.textContent = 'Send Code to Email';
            if (icon) icon.className = 'bi bi-envelope-arrow-up me-1';
        });
    }

    // Auto-open settings modal if requested via URL parameters and initialize inputs
    window.addEventListener('DOMContentLoaded', () => {
        initUpdateMobileValidation();
        const settingsModalEl = document.getElementById('settingsModal');
        if (settingsModalEl) {
            settingsModalEl.addEventListener('shown.bs.modal', initUpdateMobileValidation);
        }
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('open_settings') === '1') {
            openSettingsModal(urlParams.get('tab') || 'profile');
        }
    });
</script>
