-- Paikit Health Center Information System
-- Ready-to-import SQL for Cloud MySQL Databases (TiDB Cloud, Aiven, Clever Cloud, etc.)

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `walkin_access_tokens`;
DROP TABLE IF EXISTS `prescriptions`;
DROP TABLE IF EXISTS `encounters`;
DROP TABLE IF EXISTS `doctor_schedules`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `patients`;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Patients Table
CREATE TABLE `patients` (
  `patient_id` INT AUTO_INCREMENT PRIMARY KEY,
  `mobile_number` VARCHAR(15) NOT NULL UNIQUE,
  `email` VARCHAR(100) DEFAULT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `first_name` VARCHAR(100) DEFAULT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) DEFAULT NULL,
  `suffix` VARCHAR(20) DEFAULT NULL,
  `birth_date` DATE NOT NULL,
  `gender` ENUM('Male', 'Female', 'Other') NOT NULL,
  `sitio` VARCHAR(100) NOT NULL DEFAULT 'Paikit',
  `street_address` VARCHAR(255) DEFAULT NULL,
  `account_type` ENUM('registered_client', 'walkin_guest') DEFAULT 'walkin_guest',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_mobile` (`mobile_number`),
  INDEX `idx_email` (`email`),
  INDEX `idx_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. System Users Table
CREATE TABLE `users` (
  `user_id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT DEFAULT NULL,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `full_name` VARCHAR(150) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('Doctor', 'Staff', 'Client') NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Doctor Schedules Table
CREATE TABLE `doctor_schedules` (
  `schedule_id` INT AUTO_INCREMENT PRIMARY KEY,
  `duty_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `max_slots` INT DEFAULT 50,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_duty_date` (`duty_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Clinical Encounters Table
CREATE TABLE `encounters` (
  `encounter_id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `queue_number` VARCHAR(10) NOT NULL,
  `encounter_type` ENUM('Walk-in', 'Appointment') DEFAULT 'Walk-in',
  `visit_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `queue_status` ENUM('Waiting', 'Serving', 'Completed', 'Cancelled') DEFAULT 'Waiting',
  `check_in_status` ENUM('Booked', 'Arrived') DEFAULT 'Arrived',
  `approval_status` ENUM('Pending', 'Approved', 'Declined') NOT NULL DEFAULT 'Approved',
  `decline_reason` VARCHAR(255) DEFAULT NULL,
  `reviewed_by` INT DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `complaint` TEXT DEFAULT NULL,
  `vital_signs` TEXT DEFAULT NULL,
  `diagnostic_notes` TEXT DEFAULT NULL,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE,
  INDEX `idx_visit_date` (`visit_date`),
  INDEX `idx_queue_status` (`queue_status`),
  INDEX `idx_queue_number` (`queue_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Prescriptions Table
CREATE TABLE `prescriptions` (
  `prescription_id` INT AUTO_INCREMENT PRIMARY KEY,
  `encounter_id` INT NOT NULL,
  `medication_details` TEXT NOT NULL,
  `dispense_status` ENUM('Pending', 'Dispensed') DEFAULT 'Pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`encounter_id`) REFERENCES `encounters`(`encounter_id`) ON DELETE CASCADE,
  INDEX `idx_dispense_status` (`dispense_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Walk-in Access Tokens Table
CREATE TABLE `walkin_access_tokens` (
  `token_id` INT AUTO_INCREMENT PRIMARY KEY,
  `encounter_id` INT NOT NULL,
  `transaction_code` VARCHAR(32) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`encounter_id`) REFERENCES `encounters`(`encounter_id`) ON DELETE CASCADE,
  INDEX `idx_transaction_code` (`transaction_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Audit Logs Table
CREATE TABLE `audit_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `username` VARCHAR(50) DEFAULT 'Guest',
  `role` VARCHAR(20) DEFAULT 'Guest',
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Password Resets Table
CREATE TABLE `password_resets` (
  `reset_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token` (`token`),
  INDEX `idx_user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Seed Data (Default Accounts: Password123!)
INSERT INTO `users` (`user_id`, `username`, `full_name`, `email`, `password_hash`, `role`) VALUES
(1, 'doctor', 'Dr. Maria Santos, MD', 'doctor@paikit.health', '$2y$12$jnvpOK8KUble1GVfKhmnxumgmotam0ttrwQejGYOuAKKnwO/GIKl2', 'Doctor'),
(2, 'staff', 'Ana Reyes, RN (Staff Nurse)', 'staff@paikit.health', '$2y$12$jnvpOK8KUble1GVfKhmnxumgmotam0ttrwQejGYOuAKKnwO/GIKl2', 'Staff');

-- Initial Patient and Client Account
INSERT INTO `patients` (`patient_id`, `mobile_number`, `email`, `full_name`, `first_name`, `last_name`, `birth_date`, `gender`, `sitio`, `account_type`) VALUES
(1, '09171234567', 'client@paikit.health', 'Juan Dela Cruz', 'Juan', 'Dela Cruz', '1992-05-14', 'Male', 'Paikit', 'registered_client');

INSERT INTO `users` (`user_id`, `patient_id`, `username`, `full_name`, `email`, `password_hash`, `role`) VALUES
(3, 1, 'client', 'Juan Dela Cruz', 'client@paikit.health', '$2y$12$jnvpOK8KUble1GVfKhmnxumgmotam0ttrwQejGYOuAKKnwO/GIKl2', 'Client');

-- Initial Schedule for Today
INSERT INTO `doctor_schedules` (`duty_date`, `start_time`, `end_time`, `max_slots`, `notes`) VALUES
(CURRENT_DATE(), '08:00:00', '17:00:00', 50, 'Regular Outpatient Clinic & Primary Care Consultation. Solo Physician on duty: Dr. Maria Santos, MD.');
