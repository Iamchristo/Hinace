-- The double-entry ledger core. Every module's money movement settles through
-- these three tables via App\Core\Ledger\LedgerService - no other table in the
-- system stores a balance.

CREATE TABLE wallets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    ledger_type ENUM('available', 'investment', 'forex', 'realestate') NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    balance DECIMAL(20, 8) NOT NULL DEFAULT 0,
    locked_amount DECIMAL(20, 8) NOT NULL DEFAULT 0,
    status ENUM('active', 'frozen_debit', 'frozen_all') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wallets_user_ledger_currency (user_id, ledger_type, currency),
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(64) NOT NULL,
    status ENUM('pending', 'completed', 'reversed', 'failed') NOT NULL DEFAULT 'pending',
    module VARCHAR(32) NULL,
    initiated_by_user_id BIGINT UNSIGNED NULL,
    reference VARCHAR(190) NULL,
    reversal_of_transaction_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    KEY idx_transactions_initiated_by (initiated_by_user_id),
    KEY idx_transactions_reversal_of (reversal_of_transaction_id),
    CONSTRAINT fk_transactions_user FOREIGN KEY (initiated_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_reversal_of FOREIGN KEY (reversal_of_transaction_id) REFERENCES transactions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only: the application layer never issues UPDATE/DELETE against this
-- table. Corrections are new offsetting entries linked via transactions.reversal_of_transaction_id.
CREATE TABLE ledger_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT UNSIGNED NOT NULL,
    wallet_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('debit', 'credit') NOT NULL,
    amount DECIMAL(20, 8) NOT NULL,
    balance_after DECIMAL(20, 8) NOT NULL,
    entry_type VARCHAR(64) NOT NULL,
    created_by_type ENUM('user', 'system', 'admin') NOT NULL DEFAULT 'system',
    created_by_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ledger_entries_transaction (transaction_id),
    KEY idx_ledger_entries_wallet (wallet_id),
    CONSTRAINT fk_ledger_entries_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_entries_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
