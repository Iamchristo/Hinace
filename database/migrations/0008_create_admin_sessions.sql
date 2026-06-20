-- Kept separate from user_sessions: admin sessions get a much shorter idle
-- timeout and mandatory 2FA, enforced in AdminAuthService/AdminAuthMiddleware.
CREATE TABLE admin_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    revoked_at TIMESTAMP NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_sessions_token_hash (token_hash),
    KEY idx_admin_sessions_admin (admin_user_id),
    CONSTRAINT fk_admin_sessions_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
