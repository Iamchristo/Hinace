-- The system house user is the double-entry counterparty for external
-- deposits/withdrawals (see App\Core\Ledger\WalletService::getSystemHouseWallet).
-- It is never a real customer and should never appear in user-facing lists.
INSERT INTO users (email, password_hash, status, referral_code)
SELECT 'system@hinace.internal', '', 'closed', 'SYSTEMHOUSE'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'system@hinace.internal');
