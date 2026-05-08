-- Heavy Equipment Tracker — Database Setup
-- Run this in phpMyAdmin or: mysql -u root -p < setup.sql

CREATE DATABASE IF NOT EXISTS equipment_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE equipment_tracker;

-- ─────────────────────────────────────────
-- SITES
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sites (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    address     VARCHAR(255) NOT NULL,
    latitude    DECIMAL(10,7) NOT NULL,
    longitude   DECIMAL(10,7) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─────────────────────────────────────────
-- EQUIPMENT
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS equipment (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    make            VARCHAR(80)  NOT NULL,
    model           VARCHAR(80)  NOT NULL,
    serial_number   VARCHAR(80)  NOT NULL UNIQUE,
    status          ENUM('available','checked_out','maintenance','decommissioned') NOT NULL DEFAULT 'available',
    site_id         INT UNSIGNED NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─────────────────────────────────────────
-- KEY CHECKOUTS
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS key_checkouts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_id        INT UNSIGNED NOT NULL,
    employee_name       VARCHAR(120) NOT NULL,
    employee_id         VARCHAR(60)  NOT NULL,
    checked_out_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expected_return_at  DATETIME NULL,
    returned_at         DATETIME NULL,
    notes               TEXT NULL,
    is_returned         TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─────────────────────────────────────────
-- AUDIT LOG
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_id    INT UNSIGNED NOT NULL,
    employee_id     VARCHAR(60)  NOT NULL,
    employee_name   VARCHAR(120) NOT NULL,
    action          ENUM('checkout','return','status_change','created','updated','deleted') NOT NULL,
    details         TEXT NULL,
    ip_address      VARCHAR(45)  NULL,
    timestamp       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─────────────────────────────────────────
-- SEED DATA
-- ─────────────────────────────────────────

INSERT INTO sites (name, address, latitude, longitude) VALUES
('Northgate Plaza Project',  '123 Commerce Ave, Davao City',         7.0707,  125.6087),
('Riverside Bridge Works',   '456 Riverside Rd, Tagum City',         7.4479,  125.8076),
('South Coastal Highway',    '789 Coastal Blvd, Digos City',         6.7497,  125.3572),
('Airport Expansion Site',   '1 Airport Rd, Davao City',             7.1256,  125.6477),
('Industrial Park Phase 2',  'Km 12 Diversion Rd, Panabo City',      7.3019,  125.6858);

INSERT INTO equipment (name, make, model, serial_number, status, site_id) VALUES
('Excavator Alpha',   'Komatsu',     'PC200-8',      'KOM-PC200-0001', 'checked_out',  1),
('Bulldozer Bravo',   'Caterpillar', 'D6T',          'CAT-D6T-0042',   'available',    2),
('Crane Charlie',     'Liebherr',    'LTM 1055-3.2', 'LIE-LTM-1055',   'available',    3),
('Loader Delta',      'Komatsu',     'WA380-6',      'KOM-WA380-0009', 'maintenance',  NULL),
('Grader Echo',       'Caterpillar', '140M3',        'CAT-140M3-0017', 'available',    5),
('Compactor Foxtrot', 'Dynapac',     'CA3500',       'DYN-CA3500-003', 'checked_out',  1),
('Backhoe Golf',      'JCB',         '3CX',          'JCB-3CX-00072',  'available',    4),
('Dumper Hotel',      'Volvo',       'A40G',         'VOL-A40G-00055', 'available',    2);

-- Active checkout for Excavator Alpha (equipment id=1)
INSERT INTO key_checkouts (equipment_id, employee_name, employee_id, checked_out_at, expected_return_at, notes, is_returned)
VALUES (1, 'Juan dela Cruz', 'EMP-001', NOW() - INTERVAL 2 HOUR, NOW() + INTERVAL 6 HOUR, 'Morning shift operation', 0);

-- Active checkout for Compactor Foxtrot (equipment id=6)
INSERT INTO key_checkouts (equipment_id, employee_name, employee_id, checked_out_at, expected_return_at, notes, is_returned)
VALUES (6, 'Maria Santos', 'EMP-017', NOW() - INTERVAL 1 HOUR, NOW() + INTERVAL 7 HOUR, 'Compaction run — Block C', 0);

-- Audit entries for the two checkouts
INSERT INTO audit_log (equipment_id, employee_id, employee_name, action, details, ip_address)
VALUES
(1, 'EMP-001', 'Juan dela Cruz', 'checkout', 'Key checked out. Expected return: +8h.', '127.0.0.1'),
(6, 'EMP-017', 'Maria Santos',   'checkout', 'Key checked out. Expected return: +8h.', '127.0.0.1');
