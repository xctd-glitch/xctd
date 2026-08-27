CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(100) NOT NULL,
    alias VARCHAR(100) NOT NULL,
    location VARCHAR(120) NOT NULL,
    normalized_name VARCHAR(100) NOT NULL,
    normalized_alias VARCHAR(100) NOT NULL,
    team ENUM('XCTD', 'MNX') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    tracking_start_week DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_team_members_normalized_name_active (normalized_name, is_active),
    UNIQUE KEY uq_team_members_normalized_alias (normalized_alias),
    KEY idx_team_members_team_active (team, is_active),
    KEY idx_team_members_alias_active (normalized_alias, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    image_sha256 CHAR(64) NOT NULL,
    reference_no VARCHAR(40) NULL,
    receipt_date DATE NULL,
    receipt_time TIME NULL,
    sender_name VARCHAR(100) NOT NULL,
    sender_alias VARCHAR(100) NULL,
    team_member_id BIGINT UNSIGNED NULL,
    source_account_last4 CHAR(4) NOT NULL,
    team ENUM('XCTD', 'MNX') NOT NULL,
    original_amount BIGINT UNSIGNED NOT NULL,
    adjusted_amount DECIMAL(20,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_transactions_image_sha256 (image_sha256),
    UNIQUE KEY uq_payment_transactions_reference_no (reference_no),
    KEY idx_payment_transactions_sender_created (sender_name, created_at),
    KEY idx_payment_transactions_member_created (team_member_id, created_at),
    KEY idx_payment_transactions_team_created (team, created_at),
    KEY idx_payment_transactions_receipt_team (receipt_date, team)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE weekly_payment_obligations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_member_id BIGINT UNSIGNED NOT NULL,
    sender_name VARCHAR(100) NOT NULL,
    team ENUM('XCTD', 'MNX') NOT NULL,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    status ENUM('pending', 'unpaid', 'paid') NOT NULL DEFAULT 'pending',
    payment_transaction_id BIGINT UNSIGNED NULL,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_weekly_payment_member_week (team_member_id, week_start),
    UNIQUE KEY uq_weekly_payment_transaction (payment_transaction_id),
    KEY idx_weekly_payment_status_week (status, week_start),
    KEY idx_weekly_payment_team_status (team, status),
    KEY idx_weekly_payment_sender_status (sender_name, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARBINARY(16) NOT NULL,
    username VARCHAR(50) NOT NULL DEFAULT '',
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_ip_time (ip_address, attempted_at),
    KEY idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
