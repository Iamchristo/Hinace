-- audit_log is write-once from the application's perspective: there is no
-- UPDATE/DELETE code path against it anywhere, by design (see App\Core\Audit\AuditLogger).
CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    action_type VARCHAR(96) NOT NULL,
    target_type VARCHAR(64) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    before_state JSON NULL,
    after_state JSON NULL,
    reason VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_admin (admin_id),
    KEY idx_audit_target (target_type, target_id),
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_access_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    target_type VARCHAR(64) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_access_log_admin (admin_id),
    KEY idx_access_log_target (target_type, target_id),
    CONSTRAINT fk_access_log_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
