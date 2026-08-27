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
    KEY idx_team_members_team_active (team, is_active)
    -- idx_team_members_alias_active (normalized_alias, is_active) was dropped:
    -- uq_team_members_normalized_alias already makes normalized_alias unique, so a
    -- lookup on it returns at most one row and the trailing is_active column can
    -- never narrow anything further.
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
    -- Scoped per sender, not global. A reference number is only unique within the
    -- issuing bank's own numbering, so two banks can legitimately mint the same
    -- string; a global unique rejected the second receipt as a duplicate. Note
    -- team_member_id is always populated on insert (it comes from the resolved
    -- sender), so the scope is real at write time. It can later become NULL via
    -- fk_payment_transactions_member's SET NULL, after which those rows no longer
    -- constrain each other - acceptable, since the sender they belonged to is gone.
    UNIQUE KEY uq_payment_transactions_member_reference (team_member_id, reference_no),
    KEY idx_payment_transactions_sender_created (sender_name, created_at),
    KEY idx_payment_transactions_member_created (team_member_id, created_at),
    KEY idx_payment_transactions_team_created (team, created_at),
    KEY idx_payment_transactions_receipt_team (receipt_date, team),
    -- SET NULL rather than RESTRICT: deleting a sender is already allowed and the
    -- transaction history must outlive it. Readers LEFT JOIN team_members and fall
    -- back to the denormalised pt.sender_alias, so a null here is already handled.
    CONSTRAINT fk_payment_transactions_member FOREIGN KEY (team_member_id)
        REFERENCES team_members (id) ON DELETE SET NULL
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
    KEY idx_weekly_payment_sender_status (sender_name, status),
    -- RESTRICT is free today because nothing in the application deletes a
    -- transaction; it exists so that a future delete path cannot silently strip
    -- the evidence from an obligation already marked paid.
    CONSTRAINT fk_weekly_payment_transaction FOREIGN KEY (payment_transaction_id)
        REFERENCES payment_transactions (id) ON DELETE RESTRICT,
    -- CASCADE is a deliberate retention choice: a deleted sender takes its whole
    -- weekly ledger with it, settled weeks included. TeamRepository::delete()
    -- still refuses while 'pending' or 'unpaid' rows exist, so in practice only
    -- 'paid' rows can ever cascade. The payment_transactions rows themselves are
    -- NOT removed - that FK is ON DELETE SET NULL - so the money history survives
    -- even though the per-week obligation record does not.
    -- uq_weekly_payment_member_week supplies the leftmost team_member_id prefix
    -- this constraint needs, so no extra index is created.
    CONSTRAINT fk_weekly_payment_member FOREIGN KEY (team_member_id)
        REFERENCES team_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_member_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_member_id BIGINT UNSIGNED NOT NULL,
    bank_code VARCHAR(20) NOT NULL,
    account_number VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_team_member_accounts_bank_account (bank_code, account_number),
    KEY idx_team_member_accounts_member (team_member_id),
    CONSTRAINT fk_team_member_accounts_member FOREIGN KEY (team_member_id)
        REFERENCES team_members (id) ON DELETE CASCADE
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
