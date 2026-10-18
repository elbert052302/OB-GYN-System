-- GentleCare foundation schema. Do not apply to an existing database without a reviewed migration plan and verified backup.
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role ENUM('patient', 'doctor', 'clinic') NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(16) NULL,
    password_hash VARCHAR(255) NOT NULL,
    email_verified_at DATETIME NULL,
    verification_code_hash VARCHAR(255) NULL,
    verification_expires_at DATETIME NULL,
    verification_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patient_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    date_of_birth DATE NOT NULL,
    emergency_contact VARCHAR(160) NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_patient_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doctor_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    license_number VARCHAR(80) NOT NULL,
    specialization VARCHAR(120) NOT NULL,
    time_zone VARCHAR(64) NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_doctor_license (license_number),
    CONSTRAINT fk_doctor_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clinic_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    clinic_name VARCHAR(160) NOT NULL,
    address VARCHAR(255) NOT NULL,
    time_zone VARCHAR(64) NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_clinic_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE practice_locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    address VARCHAR(255) NOT NULL,
    time_zone VARCHAR(64) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_locations_owner (owner_user_id, is_active),
    CONSTRAINT fk_locations_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doctor_clinic_affiliations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    doctor_user_id BIGINT UNSIGNED NOT NULL,
    clinic_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('requested', 'active', 'rejected', 'ended') NOT NULL DEFAULT 'requested',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    decided_by BIGINT UNSIGNED NULL,
    ended_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doctor_clinic_affiliation (doctor_user_id, clinic_user_id),
    KEY ix_affiliations_clinic_status (clinic_user_id, status),
    CONSTRAINT fk_affiliations_doctor FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles (user_id),
    CONSTRAINT fk_affiliations_clinic FOREIGN KEY (clinic_user_id) REFERENCES clinic_profiles (user_id),
    CONSTRAINT fk_affiliations_decided_by FOREIGN KEY (decided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doctor_clinic_affiliation_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    affiliation_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    action ENUM('requested', 'accepted', 'rejected', 'ended') NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_affiliation_history (affiliation_id, occurred_at),
    CONSTRAINT fk_affiliation_history_affiliation FOREIGN KEY (affiliation_id) REFERENCES doctor_clinic_affiliations (id),
    CONSTRAINT fk_affiliation_history_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doctor_availability (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    doctor_user_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    local_start TIME NOT NULL,
    local_end TIME NOT NULL,
    valid_from DATE NOT NULL,
    valid_until DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_availability_doctor_date (doctor_user_id, weekday, valid_from, valid_until),
    CONSTRAINT fk_availability_doctor FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles (user_id),
    CONSTRAINT fk_availability_location FOREIGN KEY (location_id) REFERENCES practice_locations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    doctor_user_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    starts_at_utc DATETIME NOT NULL,
    ends_at_utc DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doctor_slot_start (doctor_user_id, starts_at_utc),
    KEY ix_slots_location_time (location_id, starts_at_utc),
    CONSTRAINT fk_slots_doctor FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles (user_id),
    CONSTRAINT fk_slots_location FOREIGN KEY (location_id) REFERENCES practice_locations (id),
    CONSTRAINT fk_slots_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_user_id BIGINT UNSIGNED NOT NULL,
    doctor_user_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    status ENUM('confirmed', 'completed', 'cancelled', 'rescheduled', 'no_show') NOT NULL DEFAULT 'confirmed',
    booked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_appointment_reservation_match (id, patient_user_id, slot_id),
    KEY ix_appointments_patient_status (patient_user_id, status),
    KEY ix_appointments_doctor_time (doctor_user_id, slot_id, status),
    CONSTRAINT fk_appointments_patient FOREIGN KEY (patient_user_id) REFERENCES patient_profiles (user_id),
    CONSTRAINT fk_appointments_doctor FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles (user_id),
    CONSTRAINT fk_appointments_location FOREIGN KEY (location_id) REFERENCES practice_locations (id),
    CONSTRAINT fk_appointments_slot FOREIGN KEY (slot_id) REFERENCES appointment_slots (id),
    CONSTRAINT fk_appointments_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_purposes (
    appointment_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(80) NOT NULL,
    other_description VARCHAR(255) NULL,
    PRIMARY KEY (appointment_id, purpose),
    CONSTRAINT fk_purposes_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE active_appointment_reservations (
    appointment_id BIGINT UNSIGNED NOT NULL,
    patient_user_id BIGINT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (appointment_id),
    UNIQUE KEY uq_active_reservation_patient (patient_user_id),
    UNIQUE KEY uq_active_reservation_slot (slot_id),
    -- These three references must identify the same appointment row.
    CONSTRAINT fk_reservations_appointment_match
        FOREIGN KEY (appointment_id, patient_user_id, slot_id)
        REFERENCES appointments (id, patient_user_id, slot_id),
    CONSTRAINT fk_reservations_patient FOREIGN KEY (patient_user_id) REFERENCES patient_profiles (user_id),
    CONSTRAINT fk_reservations_slot FOREIGN KEY (slot_id) REFERENCES appointment_slots (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id BIGINT UNSIGNED NOT NULL,
    replacement_appointment_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    action ENUM('booked', 'cancelled', 'rescheduled', 'completed', 'no_show', 'schedule_change_rejected') NOT NULL,
    reason VARCHAR(500) NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_appointment_history_appointment (appointment_id, occurred_at),
    CONSTRAINT fk_history_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id),
    CONSTRAINT fk_history_replacement FOREIGN KEY (replacement_appointment_id) REFERENCES appointments (id),
    CONSTRAINT fk_history_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patient_care_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_user_id BIGINT UNSIGNED NOT NULL,
    professional_user_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_care_assignment_patient (patient_user_id, ended_at),
    KEY ix_care_assignment_professional (professional_user_id, ended_at),
    CONSTRAINT fk_care_assignment_patient FOREIGN KEY (patient_user_id) REFERENCES patient_profiles (user_id),
    CONSTRAINT fk_care_assignment_professional FOREIGN KEY (professional_user_id) REFERENCES users (id),
    CONSTRAINT fk_care_assignment_actor FOREIGN KEY (assigned_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clinical_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_user_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    prenatal_classification VARCHAR(80) NULL,
    risk_assessment TEXT NULL,
    care_plan TEXT NULL,
    findings TEXT NULL,
    follow_up_suggestion TEXT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_clinical_records_patient (patient_user_id, created_at),
    CONSTRAINT fk_clinical_records_patient FOREIGN KEY (patient_user_id) REFERENCES patient_profiles (user_id),
    CONSTRAINT fk_clinical_records_author FOREIGN KEY (author_user_id) REFERENCES users (id),
    CONSTRAINT fk_clinical_records_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT fk_clinical_records_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clinical_record_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clinical_record_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    change_summary VARCHAR(500) NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_clinical_record_history (clinical_record_id, occurred_at),
    CONSTRAINT fk_clinical_history_record FOREIGN KEY (clinical_record_id) REFERENCES clinical_records (id),
    CONSTRAINT fk_clinical_history_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    notification_type VARCHAR(60) NOT NULL,
    message VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_notifications_user_unread (user_id, read_at, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_notifications_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clinic_permit_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clinic_user_id BIGINT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename CHAR(64) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permit_stored_filename (stored_filename),
    KEY ix_permits_clinic (clinic_user_id, submitted_at),
    CONSTRAINT fk_permits_clinic FOREIGN KEY (clinic_user_id) REFERENCES clinic_profiles (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
