-- ==============================================================================
-- Paikit Health Center Information System - Supabase (PostgreSQL) Migration Script
-- Compliant with Data Privacy Act of 2012 (RA 10173)
-- Generated from current XAMPP MySQL database schema and data
-- ==============================================================================

-- 1. Helper / Compatibility Functions (Emulating MySQL built-ins for compatibility)
CREATE OR REPLACE FUNCTION curdate() RETURNS date AS $$
  SELECT CURRENT_DATE;
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION date_sub(dt date, val interval) RETURNS date AS $$
  SELECT (dt - val)::date;
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION date_add(dt date, val interval) RETURNS date AS $$
  SELECT (dt + val)::date;
$$ LANGUAGE sql STABLE;

-- 2. Drop existing tables if re-running
DROP TABLE IF EXISTS audit_logs CASCADE;
DROP TABLE IF EXISTS walkin_access_tokens CASCADE;
DROP TABLE IF EXISTS prescriptions CASCADE;
DROP TABLE IF EXISTS password_resets CASCADE;
DROP TABLE IF EXISTS encounters CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS patients CASCADE;
DROP TABLE IF EXISTS doctor_schedules CASCADE;
DROP TABLE IF EXISTS system_settings CASCADE;
DROP TABLE IF EXISTS weekly_consultation_settings CASCADE;

-- ------------------------------------------------------------------------------
-- 3. Table Definitions
-- ------------------------------------------------------------------------------

-- 3.1 Patients Table
CREATE TABLE patients (
  patient_id SERIAL PRIMARY KEY,
  mobile_number VARCHAR(15) NOT NULL UNIQUE,
  email VARCHAR(100) DEFAULT NULL,
  full_name VARCHAR(150) NOT NULL,
  first_name VARCHAR(100) DEFAULT NULL,
  middle_name VARCHAR(100) DEFAULT NULL,
  last_name VARCHAR(100) DEFAULT NULL,
  suffix VARCHAR(20) DEFAULT NULL,
  birth_date DATE NOT NULL,
  gender VARCHAR(10) NOT NULL CHECK (gender IN ('Male', 'Female', 'Other')),
  sitio VARCHAR(100) NOT NULL DEFAULT 'Paikit',
  street_address VARCHAR(255) DEFAULT NULL,
  account_type VARCHAR(25) DEFAULT 'walkin_guest' CHECK (account_type IN ('registered_client', 'walkin_guest')),
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_patients_mobile ON patients(mobile_number);
CREATE INDEX idx_patients_email ON patients(email);
CREATE INDEX idx_patients_name ON patients(full_name);

-- 3.2 System Users Table
CREATE TABLE users (
  user_id SERIAL PRIMARY KEY,
  patient_id INT DEFAULT NULL REFERENCES patients(patient_id) ON DELETE CASCADE,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(150) DEFAULT NULL,
  email VARCHAR(100) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL CHECK (role IN ('Doctor', 'Staff', 'Client')),
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_users_patient_id ON users(patient_id);

-- 3.3 Doctor Schedules Table
CREATE TABLE doctor_schedules (
  schedule_id SERIAL PRIMARY KEY,
  duty_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  max_slots INT DEFAULT 50,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
  is_available SMALLINT NOT NULL DEFAULT 1
);
CREATE INDEX idx_doctor_schedules_duty_date ON doctor_schedules(duty_date);

-- 3.4 Clinical Encounters Table
CREATE TABLE encounters (
  encounter_id SERIAL PRIMARY KEY,
  patient_id INT NOT NULL REFERENCES patients(patient_id) ON DELETE CASCADE,
  queue_number VARCHAR(10) NOT NULL,
  encounter_type VARCHAR(20) DEFAULT 'Walk-in' CHECK (encounter_type IN ('Walk-in', 'Appointment')),
  visit_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
  queue_status VARCHAR(20) DEFAULT 'Waiting' CHECK (queue_status IN ('Waiting', 'Serving', 'Completed', 'Cancelled')),
  check_in_status VARCHAR(20) NOT NULL DEFAULT 'Arrived' CHECK (check_in_status IN ('Booked', 'Arrived')),
  approval_status VARCHAR(20) NOT NULL DEFAULT 'Approved' CHECK (approval_status IN ('Pending', 'Approved', 'Declined')),
  decline_reason VARCHAR(255) DEFAULT NULL,
  reviewed_by INT DEFAULT NULL,
  reviewed_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
  check_in_time TIMESTAMP WITH TIME ZONE DEFAULT NULL,
  completed_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
  is_priority SMALLINT NOT NULL DEFAULT 0,
  priority_reason VARCHAR(100) DEFAULT NULL,
  complaint TEXT DEFAULT NULL,
  vital_signs TEXT DEFAULT NULL,
  diagnostic_notes TEXT DEFAULT NULL
);
CREATE INDEX idx_encounters_patient_id ON encounters(patient_id);
CREATE INDEX idx_encounters_visit_date ON encounters(visit_date);
CREATE INDEX idx_encounters_queue_status ON encounters(queue_status);
CREATE INDEX idx_encounters_queue_number ON encounters(queue_number);

-- 3.5 Prescriptions Table
CREATE TABLE prescriptions (
  prescription_id SERIAL PRIMARY KEY,
  encounter_id INT NOT NULL REFERENCES encounters(encounter_id) ON DELETE CASCADE,
  medication_details TEXT NOT NULL,
  dispense_status VARCHAR(20) DEFAULT 'Pending' CHECK (dispense_status IN ('Pending', 'Dispensed')),
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_prescriptions_encounter_id ON prescriptions(encounter_id);
CREATE INDEX idx_prescriptions_dispense_status ON prescriptions(dispense_status);

-- 3.6 Walk-in Access Tokens Table
CREATE TABLE walkin_access_tokens (
  token_id SERIAL PRIMARY KEY,
  encounter_id INT NOT NULL REFERENCES encounters(encounter_id) ON DELETE CASCADE,
  transaction_code VARCHAR(32) NOT NULL UNIQUE,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_walkin_tokens_encounter_id ON walkin_access_tokens(encounter_id);
CREATE INDEX idx_walkin_tokens_code ON walkin_access_tokens(transaction_code);

-- 3.7 Audit Logs Table
CREATE TABLE audit_logs (
  log_id SERIAL PRIMARY KEY,
  user_id INT DEFAULT NULL,
  username VARCHAR(50) DEFAULT 'Guest',
  role VARCHAR(20) DEFAULT 'Guest',
  action VARCHAR(100) NOT NULL,
  details TEXT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_audit_user ON audit_logs(user_id);
CREATE INDEX idx_audit_date ON audit_logs(created_at);

-- 3.8 Password Resets Table
CREATE TABLE password_resets (
  reset_id SERIAL PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
  email VARCHAR(100) NOT NULL,
  token VARCHAR(64) NOT NULL UNIQUE,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_pwd_resets_token ON password_resets(token);
CREATE INDEX idx_pwd_resets_user_id ON password_resets(user_id);

-- 3.9 System Settings Table
CREATE TABLE system_settings (
  setting_key VARCHAR(50) PRIMARY KEY,
  setting_value TEXT DEFAULT NULL,
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3.10 Weekly Consultation Settings Table
CREATE TABLE weekly_consultation_settings (
  day_number INT PRIMARY KEY,
  day_name VARCHAR(50) NOT NULL,
  is_available SMALLINT NOT NULL DEFAULT 1,
  start_time TIME NOT NULL DEFAULT '08:00:00',
  end_time TIME NOT NULL DEFAULT '17:00:00',
  remarks VARCHAR(255) DEFAULT 'Regular Outpatient Consultation',
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------------------------
-- 4. Initial Seed Data (Exported from XAMPP database)
-- ------------------------------------------------------------------------------

-- System Settings (SMTP)
INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES
('smtp_encryption', 'tls', NOW()),
('smtp_from_email', 'warcurry590@gmail.com', NOW()),
('smtp_from_name', 'Paikit Health Center', NOW()),
('smtp_host', 'smtp.gmail.com', NOW()),
('smtp_password', 'best ukrq envy hxnl', NOW()),
('smtp_port', '587', NOW()),
('smtp_username', 'warcurry590@gmail.com', NOW())
ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value;

-- Weekly Consultation Days
INSERT INTO weekly_consultation_settings (day_number, day_name, is_available, start_time, end_time, remarks, updated_at) VALUES
(1, 'Lunes (Monday)', 1, '08:00:00', '17:00:00', 'General Checkup', NOW()),
(2, 'Martes (Tuesday)', 1, '08:00:00', '17:00:00', 'Regular Outpatient Consultation', NOW()),
(3, 'Miyerkules (Wednesday)', 1, '08:00:00', '17:00:00', 'Regular Outpatient Consultation', NOW()),
(4, 'Huwebes (Thursday)', 1, '08:00:00', '17:00:00', 'Regular Outpatient Consultation', NOW()),
(5, 'Biyernes (Friday)', 1, '08:00:00', '17:00:00', 'Off Duty', NOW())
ON CONFLICT (day_number) DO UPDATE SET 
  day_name = EXCLUDED.day_name,
  is_available = EXCLUDED.is_available,
  start_time = EXCLUDED.start_time,
  end_time = EXCLUDED.end_time,
  remarks = EXCLUDED.remarks;

-- Patients Master Records
INSERT INTO patients (patient_id, mobile_number, email, full_name, first_name, middle_name, last_name, suffix, birth_date, gender, sitio, street_address, account_type) VALUES
(1, '09171234567', 'client@paikit.health', 'Juan Dela Cruz', NULL, NULL, NULL, NULL, '1992-05-14', 'Male', 'Paikit', NULL, 'registered_client'),
(2, '09189876543', NULL, 'Maria Clara De Los Santos', NULL, NULL, NULL, NULL, '1995-10-24', 'Female', 'Paikit', NULL, 'walkin_guest'),
(3, '09205557788', NULL, 'Roberto Cruz', NULL, NULL, NULL, NULL, '1988-03-12', 'Male', 'Paikit', NULL, 'walkin_guest'),
(4, '09930216634', 'delosangelesjanjan@gmail.com', 'John Rommel', 'john rommel', 'dawda', 'Norton', 'Jr.', '2022-02-10', 'Male', 'Paikit', '513 H mabolo street', 'registered_client'),
(5, '09175435362', 'test5885@canlubang.ph', 'Maria Clara Santos Jr.', 'Maria', 'Clara', 'Santos', 'Jr.', '1995-05-15', 'Female', 'Paikit', '', 'registered_client'),
(6, '09188577718', 'patient1049@canlubang.ph', 'Pedro Penduko Cruz', 'Pedro', 'Penduko', 'Cruz', '', '1992-03-10', 'Male', 'Paikit', '', 'registered_client'),
(7, '09178126910', 'testpat8187@canlubang.ph', 'Ramon Bautista Revilla', 'Ramon', 'Bautista', 'Revilla', '', '1988-11-20', 'Male', 'Paikit', '', 'registered_client')
ON CONFLICT (patient_id) DO NOTHING;

-- System Users (Doctor, Staff, Registered Clients)
INSERT INTO users (user_id, patient_id, username, full_name, email, password_hash, role) VALUES
(1, NULL, 'doctor', 'Dr. Lanie Manaig, MD', 'doctor@paikit.health', '$2y$12$Wxxozk1qcIXjzuG8k9Uyye.QeNJhFN/t9NKRJIUs/OqRvKK9bU/Mu', 'Doctor'),
(2, NULL, 'staff', 'Ana Reyes, RN (Staff Nurse)', 'staff@paikit.health', '$2y$12$Wxxozk1qcIXjzuG8k9Uyye.QeNJhFN/t9NKRJIUs/OqRvKK9bU/Mu', 'Staff'),
(3, 1, 'client', 'Juan Dela Cruz', 'client@paikit.health', '$2y$12$Wxxozk1qcIXjzuG8k9Uyye.QeNJhFN/t9NKRJIUs/OqRvKK9bU/Mu', 'Client'),
(4, 4, 'johnrommel', 'John Rommel', 'delosangelesjanjan@gmail.com', '$2y$12$Wxxozk1qcIXjzuG8k9Uyye.QeNJhFN/t9NKRJIUs/OqRvKK9bU/Mu', 'Client')
ON CONFLICT (user_id) DO NOTHING;

-- Doctor Schedules
INSERT INTO doctor_schedules (schedule_id, duty_date, start_time, end_time, max_slots, notes, is_available) VALUES
(1, '2026-11-09', '08:00:00', '17:00:00', 50, 'Regular Outpatient Clinic & Primary Care Consultation. Solo Physician on duty: Dr. Maria Santos, MD.', 1),
(2, '2026-11-10', '10:00:00', '17:00:00', 54, 'Testing daily quota', 1),
(4, '2026-11-25', '08:00:00', '17:00:00', 0, 'Attending Regional Medical Conference', 0),
(5, '2026-11-12', '08:30:00', '16:30:00', 50, 'Standard daily quota (50 patients)', 1)
ON CONFLICT (schedule_id) DO NOTHING;

-- Encounters
INSERT INTO encounters (encounter_id, patient_id, queue_number, encounter_type, visit_date, queue_status, check_in_status, approval_status, decline_reason, reviewed_by, reviewed_at, check_in_time, is_priority, complaint, vital_signs, diagnostic_notes) VALUES
(20, 4, 'A-001', 'Appointment', '2026-11-10 03:35:59+00', 'Completed', 'Arrived', 'Approved', NULL, 2, '2026-11-10 03:37:12+00', '2026-11-10 03:40:04+00', 0, 'General Consultation', '{"bp":"","temp":"","weight":"","height":"","pulse":"","rr":""}', NULL),
(21, 4, 'A-002', 'Appointment', '2026-11-11 03:57:53+00', 'Waiting', 'Booked', 'Pending', NULL, NULL, NULL, NULL, 0, 'Immunization', NULL, NULL),
(22, 4, 'A-002', 'Appointment', '2026-11-12 04:11:05+00', 'Waiting', 'Booked', 'Pending', NULL, NULL, NULL, NULL, 0, 'Immunization', NULL, NULL)
ON CONFLICT (encounter_id) DO NOTHING;

-- Walk-in Access Tokens
INSERT INTO walkin_access_tokens (token_id, encounter_id, transaction_code) VALUES
(8, 20, 'PK-2026-393238'),
(9, 21, 'PK-2026-ADBD9E'),
(10, 22, 'PK-2026-AF72C9')
ON CONFLICT (token_id) DO NOTHING;

-- ------------------------------------------------------------------------------
-- 5. Synchronize PostgreSQL Sequences with Max Table IDs
-- ------------------------------------------------------------------------------
SELECT setval('patients_patient_id_seq', COALESCE((SELECT MAX(patient_id) FROM patients), 1));
SELECT setval('users_user_id_seq', COALESCE((SELECT MAX(user_id) FROM users), 1));
SELECT setval('doctor_schedules_schedule_id_seq', COALESCE((SELECT MAX(schedule_id) FROM doctor_schedules), 1));
SELECT setval('encounters_encounter_id_seq', COALESCE((SELECT MAX(encounter_id) FROM encounters), 1));
SELECT setval('prescriptions_prescription_id_seq', COALESCE((SELECT MAX(prescription_id) FROM prescriptions), 1));
SELECT setval('walkin_access_tokens_token_id_seq', COALESCE((SELECT MAX(token_id) FROM walkin_access_tokens), 1));
SELECT setval('audit_logs_log_id_seq', COALESCE((SELECT MAX(log_id) FROM audit_logs), 1));
SELECT setval('password_resets_reset_id_seq', COALESCE((SELECT MAX(reset_id) FROM password_resets), 1));
