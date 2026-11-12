USE test;
/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) DEFAULT 'Guest',
  `role` varchar(20) DEFAULT 'Guest',
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_date` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=239 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES
(1,NULL,'system','System','SYSTEM_INITIALIZATION','Seeded database schema and initial test accounts for Paikit Health Center.','127.0.0.1','2026-11-09 16:19:06'),
(2,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:23:12'),
(3,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' logged in as Doctor.','::1','2026-11-09 16:26:59'),
(4,1,'doctor','Doctor','CALL_NEXT_PATIENT','Doctor called Ticket #A-001 (Encounter ID 2) to Serving.','::1','2026-11-09 16:27:05'),
(5,1,'doctor','Doctor','RECORD_VIEWED','User \'doctor\' viewed Encounter #2.','::1','2026-11-09 16:27:15'),
(6,1,'doctor','Doctor','ENCOUNTER_RECORD_UPDATED','Doctor saved clinical notes & e-prescription for Encounter #2 (Status: Serving).','::1','2026-11-09 16:27:32'),
(7,1,'doctor','Doctor','ENCOUNTER_RECORD_UPDATED','Doctor saved clinical notes & e-prescription for Encounter #2 (Status: Completed).','::1','2026-11-09 16:27:37'),
(8,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 16:28:00'),
(9,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:28:11'),
(10,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:31:47'),
(11,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' authenticated with role Doctor.','::1','2026-11-09 16:32:54'),
(12,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 16:34:06'),
(13,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:36:15'),
(14,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:37:46'),
(15,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:39:40'),
(16,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:46:52'),
(17,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:47:31'),
(18,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 16:49:26'),
(19,4,'ewqeqw','Client','PATIENT_SELF_REGISTERED','Patient john rommel registered account \'ewqeqw\' (Email: delosangelesjanjan@gmail.com, Mobile: 09930216634).','::1','2026-11-09 16:49:54'),
(20,4,'ewqeqw','Client','APPOINTMENT_BOOKED','Client booked appointment for 2026-11-10. Ticket: A-002.','::1','2026-11-09 16:50:08'),
(21,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #3.','::1','2026-11-09 16:50:11'),
(22,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #3.','::1','2026-11-09 16:50:26'),
(23,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 16:51:02'),
(24,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:51:03'),
(25,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 16:53:29'),
(26,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 16:53:32'),
(27,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 16:53:40'),
(28,3,'client','Client','LOGIN_SUCCESS','User \'client\' (client@paikit.health) authenticated as Client.','::1','2026-11-09 16:53:51'),
(29,3,'client','Client','LOGOUT','User \'client\' logged out.','::1','2026-11-09 16:53:56'),
(30,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 16:54:25'),
(31,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 16:54:46'),
(32,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 16:55:08'),
(33,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 16:56:40'),
(34,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 17:10:03'),
(35,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 17:13:34'),
(36,1,'doctor','Doctor','ENCOUNTER_RECORD_UPDATED','Doctor saved clinical notes & e-prescription for Encounter #3 (Status: Serving).','::1','2026-11-09 17:14:56'),
(37,1,'doctor','Doctor','ENCOUNTER_RECORD_UPDATED','Doctor saved clinical notes & e-prescription for Encounter #3 (Status: Completed).','::1','2026-11-09 17:15:16'),
(38,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 17:15:30'),
(39,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 17:15:44'),
(40,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #3.','::1','2026-11-09 17:15:49'),
(41,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'wrong@test.com\'.','::1','2026-11-09 17:16:42'),
(42,3,'client','Client','LOGIN_SUCCESS','User \'client\' (client@paikit.health) authenticated as Client.','::1','2026-11-09 17:19:09'),
(43,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 17:19:14'),
(44,5,'user5885','Client','PATIENT_SELF_REGISTERED','Patient Maria Clara Santos Jr. registered account \'user5885\' (Email: test5885@canlubang.ph, Mobile: 09175435362).','::1','2026-11-09 17:19:19'),
(45,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 17:20:40'),
(46,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 17:21:38'),
(47,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 17:22:35'),
(48,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 17:22:36'),
(49,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 17:22:40'),
(50,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 17:23:35'),
(51,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 17:23:43'),
(52,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 17:24:24'),
(53,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 17:24:50'),
(54,3,'client','Client','LOGIN_SUCCESS','User \'client\' (client@paikit.health) authenticated as Client.','::1','2026-11-09 17:24:56'),
(55,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 17:25:02'),
(56,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 17:25:08'),
(57,3,'client','Client','LOGIN_SUCCESS','User \'client\' (client@paikit.health) authenticated as Client.','::1','2026-11-09 17:26:35'),
(58,6,'pat1049','Client','PATIENT_SELF_REGISTERED','Patient Pedro Penduko Cruz registered account \'pat1049\' (Email: patient1049@canlubang.ph, Mobile: 09188577718).','::1','2026-11-09 17:26:41'),
(59,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 17:27:21'),
(60,1,'doctor','Doctor','DUTY_SCHEDULE_POSTED','Doctor updated duty hours for 2026-11-10 (08:00-17:00, Max Slots: 60).','::1','2026-11-09 17:28:10'),
(61,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 17:28:58'),
(62,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 17:29:21'),
(63,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 17:29:30'),
(64,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 17:29:59'),
(65,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 17:30:16'),
(66,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 17:30:25'),
(67,3,'client','Client','LOGIN_SUCCESS','User \'client\' (client@paikit.health) authenticated as Client.','::1','2026-11-09 17:31:28'),
(68,7,'testpat8187','Client','PATIENT_SELF_REGISTERED','Patient Ramon Bautista Revilla registered account \'testpat8187\' (Email: testpat8187@canlubang.ph, Mobile: 09178126910).','::1','2026-11-09 17:31:35'),
(69,2,'staff','Staff','PRESCRIPTION_DISPENSED','Staff updated Prescription ID 2 dispense status to \'Dispensed\'.','::1','2026-11-09 17:32:15'),
(70,2,'staff','Staff','PRESCRIPTION_DISPENSED','Staff updated Prescription ID 2 dispense status to \'Pending\'.','::1','2026-11-09 17:32:47'),
(71,2,'staff','Staff','PRESCRIPTION_DISPENSED','Staff updated Prescription ID 1 dispense status to \'Dispensed\'.','::1','2026-11-09 17:32:52'),
(72,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 17:33:33'),
(73,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 17:33:41'),
(74,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 17:33:51'),
(75,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 17:39:56'),
(76,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 17:40:03'),
(77,2,'staff','Staff','WEEKLY_SCHEDULE_UPDATED','Staff/Admin updated weekly consultation schedule (Mon-Fri only, weekends excluded).','::1','2026-11-09 17:40:03'),
(78,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 17:40:15'),
(79,1,'doctor','Doctor','DUTY_SCHEDULE_POSTED','Doctor updated duty hours for 2026-11-10 (08:00-17:00, Max Slots: 45).','::1','2026-11-09 17:40:16'),
(80,3,'client','Client','LOGIN_SUCCESS','User \'client\' (client@paikit.health) authenticated as Client.','::1','2026-11-09 17:40:22'),
(81,3,'client','Client','LOGIN_SUCCESS','User \'client\' (client@paikit.health) authenticated as Client.','::1','2026-11-09 17:40:29'),
(82,3,'client','Client','APPOINTMENT_BOOKED','Patient #1 booked appointment for 2026-11-11. Assigned queue ticket: A-003.','::1','2026-11-09 17:40:29'),
(83,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 17:44:00'),
(84,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #3.','::1','2026-11-09 17:44:45'),
(85,4,'ewqeqw','Client','APPOINTMENT_BOOKED','Patient #4 booked appointment for 2026-11-16. Assigned queue ticket: A-003.','::1','2026-11-09 17:48:43'),
(86,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #5.','::1','2026-11-09 17:48:51'),
(87,4,'ewqeqw','Client','APPOINTMENT_BOOKED','Patient #4 booked appointment for 2026-11-20. Assigned queue ticket: A-003.','::1','2026-11-09 17:49:20'),
(88,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 17:49:32'),
(89,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 17:49:45'),
(90,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 17:49:52'),
(91,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 17:51:15'),
(92,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 17:51:34'),
(93,1,'doctor','Doctor','DUTY_SCHEDULE_POSTED','Doctor updated duty hours for 2026-11-10 (08:00:00-17:00:00, Max Slots: 60).','::1','2026-11-09 17:52:09'),
(94,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 17:55:14'),
(95,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 17:59:55'),
(96,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #3.','::1','2026-11-09 18:00:09'),
(97,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 18:00:24'),
(98,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-43D901\' accessed for Encounter #3.','::1','2026-11-09 18:00:49'),
(99,3,'client','Client','LOGIN_SUCCESS','User \'client\' (client@paikit.health) authenticated as Client.','::1','2026-11-09 18:01:31'),
(100,3,'client','Client','LOGOUT','User \'client\' logged out.','::1','2026-11-09 18:01:38'),
(101,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 18:01:48'),
(102,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 18:01:57'),
(103,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 18:03:52'),
(104,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 18:04:50'),
(105,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 18:10:43'),
(106,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 18:11:28'),
(107,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 18:11:43'),
(108,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 18:11:55'),
(109,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 18:15:46'),
(110,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 18:16:53'),
(111,1,'doctor','Doctor','DUTY_SCHEDULE_POSTED','Doctor updated duty hours for 2026-11-10 (10:00-17:00:00, Max Slots: 54).','::1','2026-11-09 18:17:27'),
(112,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 18:18:05'),
(113,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 18:18:20'),
(114,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 18:19:55'),
(115,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 18:21:23'),
(116,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 18:22:17'),
(117,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 18:22:28'),
(118,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 18:25:15'),
(119,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 18:25:21'),
(120,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 18:25:33'),
(121,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 18:25:40'),
(122,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 18:37:27'),
(123,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 18:38:07'),
(124,1,'doctor','Doctor','DOCTOR_UNAVAILABLE_SET','Doctor marked 2026-11-25 as unavailable. Reason: Official Vacation / Personal Leave. Pre-booked tickets on date: 0.','::1','2026-11-09 18:38:38'),
(125,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 18:38:44'),
(126,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 18:39:00'),
(127,4,'ewqeqw','Client','APPOINTMENT_BOOKED','Patient #4 booked appointment for 2026-11-18. Assigned queue ticket: A-003.','::1','2026-11-09 18:40:03'),
(128,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 18:40:58'),
(129,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 18:43:26'),
(130,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 18:46:47'),
(131,NULL,'Guest','Guest','PASSWORD_RESET_REQUESTED','Password reset token generated for user #4 (delosangelesjanjan@gmail.com). PHPMailer sent: NO','::1','2026-11-09 18:47:03'),
(132,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 18:49:45'),
(133,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 18:50:31'),
(134,NULL,'Guest','Guest','PASSWORD_RESET_FAILED','PHPMailer failed to send to delosangelesjanjan@gmail.com: SMTP Authentication credentials are not yet configured. Please set your Gmail / SMTP Email and 16-character App Password in config/mail.php or in the Admin Settings.','::1','2026-11-09 18:52:29'),
(135,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-DEMO12\' accessed for Encounter #1.','::1','2026-11-09 18:55:36'),
(136,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 18:56:05'),
(137,2,'staff','Staff','SMTP_SETTINGS_UPDATED','Staff updated outgoing SMTP configuration (Host: smtp.gmail.com, Port: 587, User: warcurry590@gmail.com).','::1','2026-11-09 18:58:37'),
(138,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 18:58:44'),
(139,NULL,'Guest','Guest','PASSWORD_RESET_DISPATCHED','Password reset email successfully delivered to delosangelesjanjan@gmail.com via live SMTP.','::1','2026-11-09 18:58:58'),
(140,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 19:00:40'),
(141,2,'staff','Staff','WEEKLY_SCHEDULE_UPDATED','Staff/Admin updated weekly consultation schedule (Mon-Fri only, weekends excluded).','::1','2026-11-09 19:04:35'),
(142,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 19:04:39'),
(143,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 19:05:03'),
(144,1,'doctor','Doctor','DUTY_SCHEDULE_POSTED','Doctor updated duty hours for 2026-11-10 (10:00:00-17:00:00, Max Slots: 54, Available: 1).','::1','2026-11-09 19:06:48'),
(145,1,'doctor','Doctor','DOCTOR_UNAVAILABLE_SET','Doctor marked 2026-11-25 as unavailable. Reason: Attending Regional Medical Conference. Pre-booked tickets on date: 0.','::1','2026-11-09 19:07:16'),
(146,1,'doctor','Doctor','DUTY_SCHEDULE_POSTED','Doctor updated duty hours for 2026-11-10 (01:00-17:00:00, Max Slots: 54, Available: 1).','::1','2026-11-09 19:07:51'),
(147,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 19:13:58'),
(148,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 19:17:33'),
(149,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 19:18:34'),
(150,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 19:18:53'),
(151,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 19:19:01'),
(152,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 19:19:21'),
(153,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 19:19:29'),
(154,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-A25E61\' accessed for Encounter #5.','::1','2026-11-09 19:19:37'),
(155,NULL,'Guest','Guest','GUEST_RECORD_VIEWED','Walk-in slip code \'PK-2026-A25E61\' accessed for Encounter #5.','::1','2026-11-09 19:20:23'),
(156,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 19:27:45'),
(157,2,'staff','Staff','SMTP_SETTINGS_UPDATED','Staff updated outgoing SMTP configuration (Host: smtp.gmail.com, Port: 587, User: delosangelesjanjan@gmail.com).','::1','2026-11-09 19:29:33'),
(158,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 19:33:42'),
(159,4,'ewqeqw','Client','APPOINTMENT_REQUESTED','Patient #4 requested appointment for 2026-11-10. Ticket #A-001 pending Staff review.','::1','2026-11-09 19:35:59'),
(160,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 19:36:41'),
(161,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 19:37:07'),
(162,2,'staff','Staff','APPOINTMENT_APPROVED','Staff approved Ticket #A-001 for john rommel scheduled on 2026-11-10 03:35:59.','::1','2026-11-09 19:37:12'),
(163,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 19:37:25'),
(164,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 19:38:50'),
(165,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 19:39:37'),
(166,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 19:39:54'),
(167,2,'staff','Staff','APPOINTMENT_CHECKED_IN','Staff marked Ticket #A-001 (john rommel) as Arrived.','::1','2026-11-09 19:40:04'),
(168,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 19:40:09'),
(169,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 19:40:41'),
(170,1,'doctor','Doctor','CALL_NEXT_PATIENT','Doctor called Ticket #A-001 (Appointment, john rommel) to Serving.','::1','2026-11-09 19:40:46'),
(171,1,'doctor','Doctor','ENCOUNTER_RECORD_UPDATED','Doctor saved clinical notes & e-prescription for Encounter #20 (Status: Completed).','::1','2026-11-09 19:41:07'),
(172,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 19:41:45'),
(173,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 19:41:59'),
(174,2,'staff','Staff','SMTP_SETTINGS_UPDATED','Staff updated outgoing SMTP configuration (Host: smtp.gmail.com, Port: 587, User: hdawda@gmail.com).','::1','2026-11-09 19:42:47'),
(175,2,'staff','Staff','UNAUTHORIZED_ACCESS_ATTEMPT','User \'staff\' with role \'Staff\' attempted to access restricted resource. Required: Client','::1','2026-11-09 19:43:08'),
(176,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 19:43:09'),
(177,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 19:43:17'),
(178,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #20.','::1','2026-11-09 19:43:23'),
(179,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #20.','::1','2026-11-09 19:43:35'),
(180,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #20.','::1','2026-11-09 19:44:14'),
(181,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #20.','::1','2026-11-09 19:44:38'),
(182,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 19:44:45'),
(183,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'doctor@paikit.health\'.','::1','2026-11-09 19:45:27'),
(184,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 19:45:35'),
(185,1,'doctor','Doctor','RECORD_VIEWED','User \'doctor\' viewed Encounter #20.','::1','2026-11-09 19:45:54'),
(186,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 19:47:14'),
(187,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 19:47:29'),
(188,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 19:47:34'),
(189,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 19:50:20'),
(190,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 19:53:34'),
(191,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #20.','::1','2026-11-09 19:53:38'),
(192,4,'ewqeqw','Client','APPOINTMENT_REQUESTED','Patient #4 requested appointment for 2026-11-11. Ticket #A-002 pending Staff review.','::1','2026-11-09 19:57:53'),
(193,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 19:58:02'),
(194,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 19:58:15'),
(195,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 19:59:46'),
(196,4,'ewqeqw','Client','LOGIN_SUCCESS','User \'ewqeqw\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 19:59:59'),
(197,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #21.','::1','2026-11-09 20:02:54'),
(198,4,'ewqeqw','Client','RECORD_VIEWED','User \'ewqeqw\' viewed Encounter #21.','::1','2026-11-09 20:05:18'),
(199,4,'ewqeqw','Client','APPOINTMENT_REQUESTED','Patient #4 requested appointment for 2026-11-12. Ticket #A-002 pending Staff review.','::1','2026-11-09 20:11:05'),
(200,4,'ewqeqw','Client','PROFILE_UPDATED','Client patient #4 updated contact details (Sitio: Asia 1, Mobile: 09930216634).','::1','2026-11-09 20:12:15'),
(201,4,'ewqeqw','Client','PROFILE_UPDATED','Client patient #4 updated contact details (Sitio: Asia 1, Mobile: 09930216634).','::1','2026-11-09 20:12:18'),
(202,4,'ewqeqw','Client','LOGOUT','User \'ewqeqw\' logged out.','::1','2026-11-09 20:12:47'),
(203,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:13:01'),
(204,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:13:09'),
(205,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:13:16'),
(206,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:13:27'),
(207,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:13:39'),
(208,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:13:49'),
(209,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:13:58'),
(210,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:14:04'),
(211,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:14:34'),
(212,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:14:38'),
(213,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:15:04'),
(214,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:15:58'),
(215,8,'wqeqeq','Client','PATIENT_SELF_REGISTERED','Patient john rommel dawda Norton Jr. registered account \'wqeqeq\' (Email: warcurry590@gmail.com, Mobile: 09930216634).','::1','2026-11-09 20:16:50'),
(216,8,'wqeqeq','Client','LOGOUT','User \'wqeqeq\' logged out.','::1','2026-11-09 20:17:09'),
(217,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:17:22'),
(218,8,'wqeqeq','Client','LOGIN_SUCCESS','User \'wqeqeq\' (warcurry590@gmail.com) authenticated as Client.','::1','2026-11-09 20:17:34'),
(219,8,'wqeqeq','Client','LOGOUT','User \'wqeqeq\' logged out.','::1','2026-11-09 20:19:21'),
(220,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:21:54'),
(221,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:22:23'),
(222,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:22:37'),
(223,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:23:06'),
(224,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:23:14'),
(225,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:28:07'),
(226,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:28:14'),
(227,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'staff@paikit.health\'.','::1','2026-11-09 20:28:33'),
(228,NULL,'Guest','Guest','ACCOUNTS_RECREATED','Admin recreated and synchronized credentials for all 3 roles (Doctor, Staff, Client).','127.0.0.1','2026-11-09 20:29:45'),
(229,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'doctor@paikit.health\'.','::1','2026-11-09 20:30:40'),
(230,1,'doctor','Doctor','LOGIN_SUCCESS','User \'doctor\' (doctor@paikit.health) authenticated as Doctor.','::1','2026-11-09 20:30:56'),
(231,1,'doctor','Doctor','LOGOUT','User \'doctor\' logged out.','::1','2026-11-09 20:31:00'),
(232,3,'client','Client','LOGIN_SUCCESS','User \'client\' (client@paikit.health) authenticated as Client.','::1','2026-11-09 20:31:18'),
(233,3,'client','Client','LOGOUT','User \'client\' logged out.','::1','2026-11-09 20:31:34'),
(234,2,'staff','Staff','LOGIN_SUCCESS','User \'staff\' (staff@paikit.health) authenticated as Staff.','::1','2026-11-09 20:31:43'),
(235,2,'staff','Staff','SMTP_SETTINGS_UPDATED','Staff updated outgoing SMTP configuration (Host: smtp.gmail.com, Port: 587, User: warcurry590@gmail.com).','::1','2026-11-09 20:32:34'),
(236,2,'staff','Staff','LOGOUT','User \'staff\' logged out.','::1','2026-11-09 20:32:42'),
(237,NULL,'Guest','Guest','LOGIN_FAILURE','Failed login attempt for identifier \'delosangelesjanjan@gmail.com\'.','::1','2026-11-09 20:32:58'),
(238,4,'johnrommel','Client','LOGIN_SUCCESS','User \'johnrommel\' (delosangelesjanjan@gmail.com) authenticated as Client.','::1','2026-11-09 20:33:09');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `doctor_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `doctor_schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `duty_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `max_slots` int(11) DEFAULT 50,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`schedule_id`),
  KEY `idx_duty_date` (`duty_date`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `doctor_schedules` WRITE;
/*!40000 ALTER TABLE `doctor_schedules` DISABLE KEYS */;
INSERT INTO `doctor_schedules` VALUES
(1,'2026-11-09','08:00:00','17:00:00',50,'Regular Outpatient Clinic & Primary Care Consultation. Solo Physician on duty: Dr. Maria Santos, MD.','2026-11-09 16:19:06',1),
(2,'2026-11-10','10:00:00','17:00:00',54,'Testing daily quota','2026-11-09 17:28:10',1),
(4,'2026-11-25','08:00:00','17:00:00',0,'Attending Regional Medical Conference','2026-11-09 18:38:38',0),
(5,'2026-11-12','08:30:00','16:30:00',50,'Standard daily quota (50 patients)','2026-11-09 19:28:10',1);
/*!40000 ALTER TABLE `doctor_schedules` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `encounters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `encounters` (
  `encounter_id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `queue_number` varchar(10) NOT NULL,
  `encounter_type` enum('Walk-in','Appointment') DEFAULT 'Walk-in',
  `visit_date` timestamp NULL DEFAULT current_timestamp(),
  `queue_status` enum('Waiting','Serving','Completed','Cancelled') DEFAULT 'Waiting',
  `check_in_status` enum('Booked','Arrived') NOT NULL DEFAULT 'Arrived',
  `approval_status` enum('Pending','Approved','Declined') NOT NULL DEFAULT 'Approved',
  `decline_reason` varchar(255) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `check_in_time` datetime DEFAULT NULL,
  `is_priority` tinyint(1) NOT NULL DEFAULT 0,
  `priority_reason` varchar(100) DEFAULT NULL,
  `complaint` text DEFAULT NULL,
  `vital_signs` text DEFAULT NULL,
  `diagnostic_notes` text DEFAULT NULL,
  PRIMARY KEY (`encounter_id`),
  KEY `patient_id` (`patient_id`),
  KEY `idx_visit_date` (`visit_date`),
  KEY `idx_queue_status` (`queue_status`),
  KEY `idx_queue_number` (`queue_number`),
  CONSTRAINT `encounters_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `encounters` WRITE;
/*!40000 ALTER TABLE `encounters` DISABLE KEYS */;
INSERT INTO `encounters` VALUES
(20,4,'A-001','Appointment','2026-11-09 19:35:59','Completed','Arrived','Approved',NULL,2,'2026-11-10 03:37:12','2026-11-10 03:40:04',0,NULL,'General Consultation','{\"bp\":\"\",\"temp\":\"\",\"weight\":\"\",\"height\":\"\",\"pulse\":\"\",\"rr\":\"\"}',''),
(21,4,'A-002','Appointment','2026-11-10 19:57:53','Waiting','Booked','Pending',NULL,NULL,NULL,NULL,0,NULL,'Immunization',NULL,NULL),
(22,4,'A-002','Appointment','2026-11-11 20:11:05','Waiting','Booked','Pending',NULL,NULL,NULL,NULL,0,NULL,'Immunization',NULL,NULL);
/*!40000 ALTER TABLE `encounters` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `reset_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
INSERT INTO `password_resets` VALUES
(10,4,'delosangelesjanjan@gmail.com','325118','2026-11-10 04:48:16','2026-11-09 20:33:16');
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL AUTO_INCREMENT,
  `mobile_number` varchar(15) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `birth_date` date NOT NULL,
  `gender` enum('Male','Female','Other') NOT NULL,
  `sitio` varchar(100) NOT NULL DEFAULT 'Paikit',
  `street_address` varchar(255) DEFAULT NULL,
  `account_type` enum('registered_client','walkin_guest') DEFAULT 'walkin_guest',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`patient_id`),
  UNIQUE KEY `mobile_number` (`mobile_number`),
  KEY `idx_mobile` (`mobile_number`),
  KEY `idx_name` (`full_name`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patients` WRITE;
/*!40000 ALTER TABLE `patients` DISABLE KEYS */;
INSERT INTO `patients` VALUES
(1,'09171234567','client@paikit.health','Juan Dela Cruz',NULL,NULL,NULL,NULL,'1992-05-14','Male','Paikit',NULL,'registered_client','2026-11-09 16:19:06'),
(2,'09189876543',NULL,'Maria Clara De Los Santos',NULL,NULL,NULL,NULL,'1995-10-24','Female','Paikit',NULL,'walkin_guest','2026-11-09 16:19:06'),
(3,'09205557788',NULL,'Roberto Cruz',NULL,NULL,NULL,NULL,'1988-03-12','Male','Paikit',NULL,'walkin_guest','2026-11-09 16:23:49'),
(4,'09930216634','delosangelesjanjan@gmail.com','John Rommel','john rommel','dawda','Norton','Jr.','2022-02-10','Male','Paikit','513 H mabolo street','registered_client','2026-11-09 16:49:54'),
(5,'09175435362','test5885@canlubang.ph','Maria Clara Santos Jr.','Maria','Clara','Santos','Jr.','1995-05-15','Female','Paikit','','registered_client','2026-11-09 17:19:19'),
(6,'09188577718','patient1049@canlubang.ph','Pedro Penduko Cruz','Pedro','Penduko','Cruz','','1992-03-10','Male','Paikit','','registered_client','2026-11-09 17:26:41'),
(7,'09178126910','testpat8187@canlubang.ph','Ramon Bautista Revilla','Ramon','Bautista','Revilla','','1988-11-20','Male','Paikit','','registered_client','2026-11-09 17:31:35');
/*!40000 ALTER TABLE `patients` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `prescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prescriptions` (
  `prescription_id` int(11) NOT NULL AUTO_INCREMENT,
  `encounter_id` int(11) NOT NULL,
  `medication_details` text NOT NULL,
  `dispense_status` enum('Pending','Dispensed') DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`prescription_id`),
  KEY `encounter_id` (`encounter_id`),
  KEY `idx_dispense_status` (`dispense_status`),
  CONSTRAINT `prescriptions_ibfk_1` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`encounter_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `prescriptions` WRITE;
/*!40000 ALTER TABLE `prescriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `prescriptions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES
('smtp_encryption','tls','2026-11-09 18:48:20'),
('smtp_from_email','warcurry590@gmail.com','2026-11-09 20:32:34'),
('smtp_from_name','Paikit Health Center','2026-11-09 18:48:20'),
('smtp_host','smtp.gmail.com','2026-11-09 18:48:20'),
('smtp_password','best ukrq envy hxnl','2026-11-09 18:58:37'),
('smtp_port','587','2026-11-09 18:48:20'),
('smtp_username','warcurry590@gmail.com','2026-11-09 20:32:34');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Doctor','Staff','Client') NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,NULL,'doctor','Dr. Lanie Manaig, MD','doctor@paikit.health','$2y$12$Wxxozk1qcIXjzuG8k9Uyye.QeNJhFN/t9NKRJIUs/OqRvKK9bU/Mu','Doctor','2026-11-09 16:19:06'),
(2,NULL,'staff','Ana Reyes, RN (Staff Nurse)','staff@paikit.health','$2y$12$Wxxozk1qcIXjzuG8k9Uyye.QeNJhFN/t9NKRJIUs/OqRvKK9bU/Mu','Staff','2026-11-09 16:19:06'),
(3,1,'client','Juan Dela Cruz','client@paikit.health','$2y$12$Wxxozk1qcIXjzuG8k9Uyye.QeNJhFN/t9NKRJIUs/OqRvKK9bU/Mu','Client','2026-11-09 16:19:06'),
(4,4,'johnrommel','John Rommel','delosangelesjanjan@gmail.com','$2y$12$Wxxozk1qcIXjzuG8k9Uyye.QeNJhFN/t9NKRJIUs/OqRvKK9bU/Mu','Client','2026-11-09 16:49:54');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `walkin_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `walkin_access_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `encounter_id` int(11) NOT NULL,
  `transaction_code` varchar(32) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `transaction_code` (`transaction_code`),
  KEY `encounter_id` (`encounter_id`),
  KEY `idx_transaction_code` (`transaction_code`),
  CONSTRAINT `walkin_access_tokens_ibfk_1` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`encounter_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `walkin_access_tokens` WRITE;
/*!40000 ALTER TABLE `walkin_access_tokens` DISABLE KEYS */;
INSERT INTO `walkin_access_tokens` VALUES
(8,20,'PK-2026-393238','2026-11-09 19:35:59'),
(9,21,'PK-2026-ADBD9E','2026-11-09 19:57:53'),
(10,22,'PK-2026-AF72C9','2026-11-09 20:11:05');
/*!40000 ALTER TABLE `walkin_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `weekly_consultation_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `weekly_consultation_settings` (
  `day_number` int(1) NOT NULL,
  `day_name` varchar(20) NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `start_time` time NOT NULL DEFAULT '08:00:00',
  `end_time` time NOT NULL DEFAULT '17:00:00',
  `remarks` varchar(255) DEFAULT 'Regular Outpatient Consultation',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`day_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `weekly_consultation_settings` WRITE;
/*!40000 ALTER TABLE `weekly_consultation_settings` DISABLE KEYS */;
INSERT INTO `weekly_consultation_settings` VALUES
(1,'Lunes (Monday)',1,'08:00:00','17:00:00','General Checkup','2026-11-09 17:40:03'),
(2,'Martes (Tuesday)',1,'08:00:00','17:00:00','Regular Outpatient Consultation','2026-11-09 17:40:03'),
(3,'Miyerkules (Wednesda',1,'08:00:00','17:00:00','Regular Outpatient Consultation','2026-11-09 17:40:03'),
(4,'Huwebes (Thursday)',1,'08:00:00','17:00:00','Regular Outpatient Consultation','2026-11-09 17:40:03'),
(5,'Biyernes (Friday)',1,'08:00:00','17:00:00','Off Duty','2026-11-09 17:40:09');
/*!40000 ALTER TABLE `weekly_consultation_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

