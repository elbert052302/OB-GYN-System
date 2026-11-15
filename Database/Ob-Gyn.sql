-- GentleCare database schema (structure only).
-- Generated from the working schema on 2026-11-15.
-- Import with: mysql -u root gentlecare_v2 < Database/Ob-Gyn.sql
-- Then load demo data with: php Backend/tests/prepare_demo.php
-- Do not apply to an existing database without a backup.

CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role` enum('patient','doctor','clinic') NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `verification_code_hash` varchar(255) DEFAULT NULL,
  `verification_expires_at` datetime DEFAULT NULL,
  `verification_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `doctor_profiles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `phone` varchar(16) DEFAULT NULL,
  `license_number` varchar(80) NOT NULL,
  `specialization` varchar(120) NOT NULL,
  `time_zone` varchar(64) NOT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_doctor_license` (`license_number`),
  CONSTRAINT `fk_doctor_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `practice_locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(160) NOT NULL,
  `address` varchar(255) NOT NULL,
  `time_zone` varchar(64) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_locations_owner` (`owner_user_id`,`is_active`),
  CONSTRAINT `fk_locations_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `patient_profiles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `phone` varchar(16) DEFAULT NULL,
  `date_of_birth` date NOT NULL,
  `emergency_contact` varchar(160) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_patient_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointment_slots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doctor_user_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `starts_at_utc` datetime NOT NULL,
  `ends_at_utc` datetime NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doctor_slot_start` (`doctor_user_id`,`starts_at_utc`),
  KEY `ix_slots_location_time` (`location_id`,`starts_at_utc`),
  KEY `fk_slots_created_by` (`created_by`),
  CONSTRAINT `fk_slots_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_slots_doctor` FOREIGN KEY (`doctor_user_id`) REFERENCES `doctor_profiles` (`user_id`),
  CONSTRAINT `fk_slots_location` FOREIGN KEY (`location_id`) REFERENCES `practice_locations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_user_id` bigint(20) unsigned NOT NULL,
  `doctor_user_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `slot_id` bigint(20) unsigned NOT NULL,
  `status` enum('confirmed','completed','cancelled','rescheduled','no_show') NOT NULL DEFAULT 'confirmed',
  `booked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appointment_reservation_match` (`id`,`patient_user_id`,`slot_id`),
  KEY `ix_appointments_patient_status` (`patient_user_id`,`status`),
  KEY `ix_appointments_doctor_time` (`doctor_user_id`,`slot_id`,`status`),
  KEY `fk_appointments_location` (`location_id`),
  KEY `fk_appointments_slot` (`slot_id`),
  KEY `fk_appointments_created_by` (`created_by`),
  CONSTRAINT `fk_appointments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_appointments_doctor` FOREIGN KEY (`doctor_user_id`) REFERENCES `doctor_profiles` (`user_id`),
  CONSTRAINT `fk_appointments_location` FOREIGN KEY (`location_id`) REFERENCES `practice_locations` (`id`),
  CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_user_id`) REFERENCES `patient_profiles` (`user_id`),
  CONSTRAINT `fk_appointments_slot` FOREIGN KEY (`slot_id`) REFERENCES `appointment_slots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `active_appointment_reservations` (
  `appointment_id` bigint(20) unsigned NOT NULL,
  `patient_user_id` bigint(20) unsigned NOT NULL,
  `slot_id` bigint(20) unsigned NOT NULL,
  `reserved_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`appointment_id`),
  UNIQUE KEY `uq_active_reservation_patient` (`patient_user_id`),
  UNIQUE KEY `uq_active_reservation_slot` (`slot_id`),
  KEY `fk_reservations_appointment_match` (`appointment_id`,`patient_user_id`,`slot_id`),
  CONSTRAINT `fk_reservations_appointment_match` FOREIGN KEY (`appointment_id`, `patient_user_id`, `slot_id`) REFERENCES `appointments` (`id`, `patient_user_id`, `slot_id`),
  CONSTRAINT `fk_reservations_patient` FOREIGN KEY (`patient_user_id`) REFERENCES `patient_profiles` (`user_id`),
  CONSTRAINT `fk_reservations_slot` FOREIGN KEY (`slot_id`) REFERENCES `appointment_slots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointment_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` bigint(20) unsigned NOT NULL,
  `replacement_appointment_id` bigint(20) unsigned DEFAULT NULL,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `action` enum('booked','cancelled','rescheduled','completed','no_show','schedule_change_rejected') NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_appointment_history_appointment` (`appointment_id`,`occurred_at`),
  KEY `fk_history_replacement` (`replacement_appointment_id`),
  KEY `fk_history_actor` (`actor_user_id`),
  CONSTRAINT `fk_history_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_history_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`),
  CONSTRAINT `fk_history_replacement` FOREIGN KEY (`replacement_appointment_id`) REFERENCES `appointments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointment_purposes` (
  `appointment_id` bigint(20) unsigned NOT NULL,
  `purpose` varchar(80) NOT NULL,
  `other_description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`appointment_id`,`purpose`),
  CONSTRAINT `fk_purposes_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `result` varchar(20) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_rate_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(32) NOT NULL,
  `limit_key` char(64) NOT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_auth_rate_limits_bucket_key_time` (`bucket`,`limit_key`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `clinic_profiles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `clinic_name` varchar(160) NOT NULL,
  `phone` varchar(16) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `time_zone` varchar(64) NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_clinic_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `clinic_permit_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `clinic_user_id` bigint(20) unsigned NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` char(64) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `byte_size` int(10) unsigned NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_stored_filename` (`stored_filename`),
  KEY `ix_permits_clinic` (`clinic_user_id`,`submitted_at`),
  CONSTRAINT `fk_permits_clinic` FOREIGN KEY (`clinic_user_id`) REFERENCES `clinic_profiles` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `clinical_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_user_id` bigint(20) unsigned NOT NULL,
  `author_user_id` bigint(20) unsigned NOT NULL,
  `appointment_id` bigint(20) unsigned DEFAULT NULL,
  `prenatal_classification` varchar(80) DEFAULT NULL,
  `risk_assessment` text DEFAULT NULL,
  `care_plan` text DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `follow_up_suggestion` text DEFAULT NULL,
  `updated_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_clinical_records_patient` (`patient_user_id`,`created_at`),
  KEY `fk_clinical_records_author` (`author_user_id`),
  KEY `fk_clinical_records_updated_by` (`updated_by`),
  KEY `fk_clinical_records_appointment` (`appointment_id`),
  CONSTRAINT `fk_clinical_records_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`),
  CONSTRAINT `fk_clinical_records_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_clinical_records_patient` FOREIGN KEY (`patient_user_id`) REFERENCES `patient_profiles` (`user_id`),
  CONSTRAINT `fk_clinical_records_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `clinical_record_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `clinical_record_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `change_summary` varchar(500) NOT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_clinical_record_history` (`clinical_record_id`,`occurred_at`),
  KEY `fk_clinical_history_actor` (`actor_user_id`),
  CONSTRAINT `fk_clinical_history_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_clinical_history_record` FOREIGN KEY (`clinical_record_id`) REFERENCES `clinical_records` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `doctor_availability` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doctor_user_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `weekday` tinyint(3) unsigned NOT NULL,
  `local_start` time NOT NULL,
  `local_end` time NOT NULL,
  `valid_from` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_availability_doctor_date` (`doctor_user_id`,`weekday`,`valid_from`,`valid_until`),
  KEY `fk_availability_location` (`location_id`),
  CONSTRAINT `fk_availability_doctor` FOREIGN KEY (`doctor_user_id`) REFERENCES `doctor_profiles` (`user_id`),
  CONSTRAINT `fk_availability_location` FOREIGN KEY (`location_id`) REFERENCES `practice_locations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `doctor_clinic_affiliations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doctor_user_id` bigint(20) unsigned NOT NULL,
  `clinic_user_id` bigint(20) unsigned NOT NULL,
  `status` enum('requested','active','rejected','ended') NOT NULL DEFAULT 'requested',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_at` datetime DEFAULT NULL,
  `decided_by` bigint(20) unsigned DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doctor_clinic_affiliation` (`doctor_user_id`,`clinic_user_id`),
  KEY `ix_affiliations_clinic_status` (`clinic_user_id`,`status`),
  KEY `fk_affiliations_decided_by` (`decided_by`),
  CONSTRAINT `fk_affiliations_clinic` FOREIGN KEY (`clinic_user_id`) REFERENCES `clinic_profiles` (`user_id`),
  CONSTRAINT `fk_affiliations_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_affiliations_doctor` FOREIGN KEY (`doctor_user_id`) REFERENCES `doctor_profiles` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `doctor_clinic_affiliation_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `affiliation_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `action` enum('requested','accepted','rejected','ended') NOT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_affiliation_history` (`affiliation_id`,`occurred_at`),
  KEY `fk_affiliation_history_actor` (`actor_user_id`),
  CONSTRAINT `fk_affiliation_history_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_affiliation_history_affiliation` FOREIGN KEY (`affiliation_id`) REFERENCES `doctor_clinic_affiliations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `appointment_id` bigint(20) unsigned DEFAULT NULL,
  `notification_type` varchar(60) NOT NULL,
  `message` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_notifications_user_unread` (`user_id`,`read_at`,`created_at`),
  KEY `fk_notifications_appointment` (`appointment_id`),
  CONSTRAINT `fk_notifications_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `patient_care_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_user_id` bigint(20) unsigned NOT NULL,
  `professional_user_id` bigint(20) unsigned NOT NULL,
  `assigned_by` bigint(20) unsigned NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_care_assignment_patient` (`patient_user_id`,`ended_at`),
  KEY `ix_care_assignment_professional` (`professional_user_id`,`ended_at`),
  KEY `fk_care_assignment_actor` (`assigned_by`),
  CONSTRAINT `fk_care_assignment_actor` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_care_assignment_patient` FOREIGN KEY (`patient_user_id`) REFERENCES `patient_profiles` (`user_id`),
  CONSTRAINT `fk_care_assignment_professional` FOREIGN KEY (`professional_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
