CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('email', 'sms', 'push', 'in_app') NOT NULL,
    type VARCHAR(64) NOT NULL,
    payload_json JSON NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user (user_id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE referrals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referrer_user_id BIGINT UNSIGNED NOT NULL,
    referred_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_referrals_referred (referred_user_id),
    KEY idx_referrals_referrer (referrer_user_id),
    CONSTRAINT fk_referrals_referrer FOREIGN KEY (referrer_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_referrals_referred FOREIGN KEY (referred_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE affiliate_commissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referral_id BIGINT UNSIGNED NOT NULL,
    module VARCHAR(32) NULL,
    commission_amount DECIMAL(20, 8) NOT NULL,
    status ENUM('pending', 'paid', 'voided') NOT NULL DEFAULT 'pending',
    transaction_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_commissions_referral (referral_id),
    CONSTRAINT fk_commissions_referral FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE CASCADE,
    CONSTRAINT fk_commissions_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
