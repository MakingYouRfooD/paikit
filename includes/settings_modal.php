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
                    <?php if ($mRole === 'Staff'): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold text-dark py-3 d-flex align-items-center justify-content-center gap-1" id="smtp-tab" data-bs-toggle="tab" data-bs-target="#tab-smtp" type="button" role="tab" aria-controls="tab-smtp" aria-selected="false">
                            <i class="bi bi-envelope-gear text-warning me-1 fs-5"></i> Email & SMTP
                            <?php if (empty($modalMailConfig['username']) || empty($modalMailConfig['password'])): ?>
                                <span class="badge bg-danger rounded-pill ms-1" style="font-size: 0.7rem;">Setup</span>
                            <?php else: ?>
                                <span class="badge bg-success rounded-pill ms-1" style="font-size: 0.7rem;">Active</span>
                            <?php endif; ?>
                        </button>
                    </li>
                    <?php endif; ?>
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
                                <form method="POST" action="dashboard">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-5">
                                            <label class="form-label small fw-bold">Mobile / Contact Number</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                                <input type="text" name="contact_number" class="form-control" 
                                                       value="<?= sanitize($modalPatient['contact_number'] ?? ($modalPatient['mobile_number'] ?? '')) ?>" 
                                                       placeholder="09123456789" maxlength="15" required>
                                            </div>
                                            <div class="form-text small">11-digit Philippine mobile number.</div>
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

                                <!-- Real-Time Password Checklist (Matching Registration Validation) -->
                                <div class="col-12">
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="small fw-bold text-dark mb-2">Password Security Checklist:</div>
                                        <div class="row g-2 small">
                                            <div class="col-sm-6" id="rule-length">
                                                <i class="bi bi-circle text-muted me-1"></i> At least 8 characters
                                            </div>
                                            <div class="col-sm-6" id="rule-upper">
                                                <i class="bi bi-circle text-muted me-1"></i> Uppercase letter (A-Z)
                                            </div>
                                            <div class="col-sm-6" id="rule-lower">
                                                <i class="bi bi-circle text-muted me-1"></i> Lowercase letter (a-z)
                                            </div>
                                            <div class="col-sm-6" id="rule-number">
                                                <i class="bi bi-circle text-muted me-1"></i> Number (0-9)
                                            </div>
                                            <div class="col-sm-6" id="rule-special">
                                                <i class="bi bi-circle text-muted me-1"></i> Special symbol (@, #, $, !, %, etc.)
                                            </div>
                                            <div class="col-sm-6" id="rule-match">
                                                <i class="bi bi-circle text-muted me-1"></i> Passwords match
                                            </div>
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

                    <?php if ($mRole === 'Staff'): ?>
                    <!-- TAB 3: EMAIL & SMTP CONFIGURATION -->
                    <div class="tab-pane fade" id="tab-smtp" role="tabpanel" aria-labelledby="smtp-tab">
                        <!-- Info Alert -->
                        <div class="alert alert-primary-subtle text-primary border border-primary small mb-3">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            Configure your outgoing email server (Gmail, Yahoo, or Custom SMTP). This enables real email delivery for password reset links and official clinic notices.
                        </div>

                        <!-- Google App Password Quick Guide -->
                        <div class="card border rounded-3 p-3 bg-light mb-4">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <strong class="text-dark small"><i class="bi bi-google me-1 text-danger"></i> How to use Gmail SMTP:</strong>
                                <a href="https://myaccount.google.com/apppasswords" target="_blank" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.75rem;">
                                    <i class="bi bi-box-arrow-up-right me-1"></i> Open Google App Passwords
                                </a>
                            </div>
                            <ol class="small text-muted mb-0 ps-3" style="font-size: 0.8rem; line-height: 1.5;">
                                <li>Go to your <strong>Google Account Security</strong> settings and ensure <strong>2-Step Verification</strong> is ON.</li>
                                <li>Search for <strong>"App Passwords"</strong> (or visit the button above).</li>
                                <li>Create a new App Password named <em>"Paikit Health Center"</em>.</li>
                                <li>Copy the generated <strong>16-letter password</strong> and paste it into the <em>SMTP Password</em> field below.</li>
                            </ol>
                        </div>

                        <!-- Configuration Form -->
                        <form action="walkin_process" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="save_smtp_settings">

                            <div class="row g-3 mb-3">
                                <div class="col-md-8">
                                    <label class="form-label fw-bold small">SMTP Server Host</label>
                                    <input type="text" name="smtp_host" class="form-control" value="<?= sanitize($modalMailConfig['host'] ?? 'smtp.gmail.com') ?>" required placeholder="e.g. smtp.gmail.com">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Port</label>
                                    <input type="number" name="smtp_port" class="form-control" value="<?= (int)($modalMailConfig['port'] ?? 587) ?>" required placeholder="587">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">SMTP Email / Username</label>
                                    <input type="email" name="smtp_username" class="form-control" value="<?= sanitize($modalMailConfig['username'] ?? '') ?>" required placeholder="e.g. paikithealthcenter@gmail.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">SMTP Password / 16-Letter App Password</label>
                                    <div class="input-group">
                                        <input type="password" name="smtp_password" id="settingsSmtpPassInput" class="form-control" value="<?= sanitize($modalMailConfig['password'] ?? '') ?>" placeholder="Enter 16-letter App Password">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('settingsSmtpPassInput', this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Encryption</label>
                                    <select name="smtp_encryption" class="form-select">
                                        <option value="tls" <?= ($modalMailConfig['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Port 587 - Recommended)</option>
                                        <option value="ssl" <?= ($modalMailConfig['encryption'] ?? 'tls') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-bold small">Sender Display Name</label>
                                    <input type="text" name="smtp_from_name" class="form-control" value="<?= sanitize($modalMailConfig['from_name'] ?? 'Paikit Health Center') ?>" required placeholder="Paikit Health Center">
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mb-4">
                                <button type="submit" class="btn btn-primary fw-bold shadow-sm">
                                    <i class="bi bi-check-circle me-1"></i> Save SMTP Settings
                                </button>
                            </div>
                        </form>

                        <!-- Test Email Section -->
                        <div class="card border rounded-3 p-3 bg-light">
                            <h6 class="fw-bold text-dark mb-2">
                                <i class="bi bi-send-check text-success me-1"></i> Test Real Email Delivery
                            </h6>
                            <p class="text-muted small mb-2" style="font-size: 0.8rem;">
                                Send a live test email to verify that PHPMailer can successfully connect and deliver messages to inboxes.
                            </p>
                            <form action="walkin_process" method="POST" class="d-flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="test_smtp_email">
                                <input type="email" name="test_email" class="form-control form-control-sm" placeholder="Enter recipient email (e.g. your personal email)" required>
                                <button type="submit" class="btn btn-success btn-sm fw-bold text-nowrap">
                                    <i class="bi bi-send me-1"></i> Send Test Email
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JAVASCRIPT LOGIC FOR SETTINGS MODAL & PASSWORD VALIDATION -->
<script>
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

    function updateChangeRuleBadge(elId, passed) {
        const el = document.getElementById(elId);
        if (!el) return;
        if (passed) {
            el.classList.remove('text-muted', 'text-danger');
            el.classList.add('text-success', 'fw-bold');
            const icon = el.querySelector('i');
            if (icon) icon.className = 'bi bi-check-circle-fill text-success me-1';
        } else {
            el.classList.remove('text-success', 'fw-bold');
            el.classList.add('text-muted');
            const icon = el.querySelector('i');
            if (icon) icon.className = 'bi bi-circle text-muted me-1';
        }
    }

    function checkChangePasswordRules() {
        const p1 = document.getElementById('newPwdInput') ? document.getElementById('newPwdInput').value : '';
        const p2 = document.getElementById('confirmPwdInput') ? document.getElementById('confirmPwdInput').value : '';

        const hasLen = p1.length >= 8;
        const hasUpper = /[A-Z]/.test(p1);
        const hasLower = /[a-z]/.test(p1);
        const hasNum = /[0-9]/.test(p1);
        const hasSpecial = /[^A-Za-z0-9]/.test(p1);
        const hasMatch = (p1.length > 0) && (p1 === p2);

        updateChangeRuleBadge('rule-length', hasLen);
        updateChangeRuleBadge('rule-upper', hasUpper);
        updateChangeRuleBadge('rule-lower', hasLower);
        updateChangeRuleBadge('rule-number', hasNum);
        updateChangeRuleBadge('rule-special', hasSpecial);
        updateChangeRuleBadge('rule-match', hasMatch);

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

    // Auto-open settings modal if requested via URL parameters
    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('open_settings') === '1') {
            openSettingsModal(urlParams.get('tab') || 'profile');
        }
    });
</script>
