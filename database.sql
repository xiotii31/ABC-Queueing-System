-- ============================================================
-- Animal Bite Center (ABC) Integrated Queueing System
-- Database: abc_queue
-- Compatible with: MySQL 5.7+ / MariaDB (XAMPP/phpMyAdmin)
-- ============================================================

CREATE DATABASE IF NOT EXISTS abc_queue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE abc_queue;

-- ============================================================
-- Table: patients
-- Stores every registered patient per session/day
-- ============================================================
CREATE TABLE IF NOT EXISTS patients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ticket_number   VARCHAR(10)     NOT NULL UNIQUE,          -- e.g. P001, R003, F002
    patient_type    ENUM('priority','regular','followup') NOT NULL,
    status          ENUM('waiting','inside','called','done','skipped') NOT NULL DEFAULT 'waiting',
    severity        ENUM('cat1','cat2','cat3','pending')      NOT NULL DEFAULT 'pending',
    queue_position  INT             NOT NULL DEFAULT 0,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    called_at       TIMESTAMP       NULL,
    done_at         TIMESTAMP       NULL,
    notes           TEXT            NULL
) ENGINE=InnoDB;

-- ============================================================
-- Table: queue_counters
-- Tracks the auto-increment per ticket prefix per day
-- ============================================================
CREATE TABLE IF NOT EXISTS queue_counters (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    prefix          CHAR(1)         NOT NULL,                 -- P, R, F
    current_count   INT             NOT NULL DEFAULT 0,
    date_active     DATE            NOT NULL DEFAULT (CURDATE()),
    UNIQUE KEY unique_prefix_date (prefix, date_active)
) ENGINE=InnoDB;

-- Seed initial counters
INSERT IGNORE INTO queue_counters (prefix, current_count, date_active)
VALUES ('P', 0, CURDATE()), ('R', 0, CURDATE()), ('F', 0, CURDATE());

-- ============================================================
-- Table: inside_log
-- Tracks how many patients are physically inside (max 5)
-- ============================================================
CREATE TABLE IF NOT EXISTS inside_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    patient_id      INT             NOT NULL,
    entered_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    exited_at       TIMESTAMP       NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Table: now_serving
-- Single-row table for the TV display
-- ============================================================
CREATE TABLE IF NOT EXISTS now_serving (
    id              INT             NOT NULL DEFAULT 1,
    ticket_number   VARCHAR(10)     NULL,
    patient_type    VARCHAR(20)     NULL,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

-- Seed single row
INSERT IGNORE INTO now_serving (id, ticket_number, patient_type) VALUES (1, NULL, NULL);

-- ============================================================
-- Table: activity_log
-- Audit trail of all queue actions
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    action          VARCHAR(50)     NOT NULL,                 -- registered, called, done, skipped
    ticket_number   VARCHAR(10)     NOT NULL,
    performed_by    VARCHAR(50)     NOT NULL DEFAULT 'system',
    performed_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- Stored Procedure: reset_daily
-- Run this every morning to reset all queues for the new day
-- ============================================================
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS reset_daily()
BEGIN
    UPDATE patients SET status = 'done' WHERE status IN ('waiting','inside','called');
    UPDATE now_serving SET ticket_number = NULL, patient_type = NULL WHERE id = 1;
    INSERT IGNORE INTO queue_counters (prefix, current_count, date_active)
        VALUES ('P', 0, CURDATE()), ('R', 0, CURDATE()), ('F', 0, CURDATE());
END$$
DELIMITER ;
