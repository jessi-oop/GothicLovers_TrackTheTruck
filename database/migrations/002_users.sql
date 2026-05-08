-- Users & Sessions migration
-- Run after setup.sql: mysql -u root -p equipment_tracker < database/migrations/002_users.sql

USE equipment_tracker;

-- ─────────────────────────────────────────
-- USERS
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)   NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    full_name     VARCHAR(120)  NOT NULL,
    role          ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─────────────────────────────────────────
-- USER SESSIONS (bearer tokens)
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token      CHAR(64)     NOT NULL UNIQUE,
    expires_at DATETIME     NOT NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token    (token),
    INDEX idx_expires  (expires_at)
) ENGINE=InnoDB;
