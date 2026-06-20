<?php

declare(strict_types=1);

namespace App\Core\Ledger;

use App\Core\Support\Money;
use PDO;

/**
 * The four ledgers (available/investment/forex/realestate) are sub-balances
 * of one user, plus a system "house" wallet used as the double-entry
 * counterparty for external deposits/withdrawals (payment gateway, bank,
 * crypto rail). No code outside Core\Ledger should write wallets.balance.
 */
final class WalletService
{
    public const LEDGER_TYPES = ['available', 'investment', 'forex', 'realestate'];

    public const SYSTEM_HOUSE_USER_EMAIL = 'system@hinace.internal';

    private ?int $systemHouseUserId = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getOrCreateWallet(int $userId, string $ledgerType, string $currency = 'USD'): array
    {
        $this->assertValidLedgerType($ledgerType);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM wallets WHERE user_id = :user_id AND ledger_type = :ledger_type AND currency = :currency'
        );
        $stmt->execute(['user_id' => $userId, 'ledger_type' => $ledgerType, 'currency' => $currency]);
        $wallet = $stmt->fetch();

        if ($wallet !== false) {
            return $wallet;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, ledger_type, currency, balance, locked_amount, status)
             VALUES (:user_id, :ledger_type, :currency, 0, 0, "active")'
        );
        $insert->execute(['user_id' => $userId, 'ledger_type' => $ledgerType, 'currency' => $currency]);

        return $this->getOrCreateWallet($userId, $ledgerType, $currency);
    }

    public function getSystemHouseWallet(string $currency = 'USD'): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => self::SYSTEM_HOUSE_USER_EMAIL]);
        $systemUser = $stmt->fetch();

        if ($systemUser === false) {
            throw new \RuntimeException(
                'System house user not found - run database/seeders/0001_system_house_account.sql first.'
            );
        }

        return $this->getOrCreateWallet((int) $systemUser['id'], 'available', $currency);
    }

    public function lockWalletForUpdate(int $walletId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wallets WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $walletId]);
        $wallet = $stmt->fetch();

        if ($wallet === false) {
            throw new \RuntimeException("Wallet not found: {$walletId}");
        }

        return $wallet;
    }

    public function availableBalance(array $wallet): Money
    {
        return Money::fromString($wallet['balance'])->subtract(Money::fromString($wallet['locked_amount']));
    }

    /**
     * The house wallet is the double-entry counterparty for external
     * deposits/withdrawals, not a real fund holder - it nets to negative
     * whenever deposits exceed withdrawals, so it's exempt from the
     * insufficient-funds check that protects genuine user balances.
     */
    public function isSystemHouseWallet(array $wallet): bool
    {
        return (int) $wallet['user_id'] === $this->systemHouseUserId();
    }

    private function systemHouseUserId(): int
    {
        if ($this->systemHouseUserId === null) {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
            $stmt->execute(['email' => self::SYSTEM_HOUSE_USER_EMAIL]);
            $row = $stmt->fetch();
            $this->systemHouseUserId = $row !== false ? (int) $row['id'] : 0;
        }

        return $this->systemHouseUserId;
    }

    public function setStatus(int $walletId, string $status): void
    {
        if (!in_array($status, ['active', 'frozen_debit', 'frozen_all'], true)) {
            throw new \InvalidArgumentException("Invalid wallet status: {$status}");
        }

        $stmt = $this->pdo->prepare('UPDATE wallets SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $walletId]);
    }

    public function adjustLockedAmount(int $walletId, Money $delta): void
    {
        $wallet = $this->lockWalletForUpdate($walletId);
        $newLocked = Money::fromString($wallet['locked_amount'])->add($delta);

        if ($newLocked->isNegative()) {
            throw new \RuntimeException("Locked amount cannot go negative for wallet {$walletId}");
        }

        $stmt = $this->pdo->prepare('UPDATE wallets SET locked_amount = :locked WHERE id = :id');
        $stmt->execute(['locked' => $newLocked->toString(), 'id' => $walletId]);
    }

    private function assertValidLedgerType(string $ledgerType): void
    {
        if (!in_array($ledgerType, self::LEDGER_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid ledger type: {$ledgerType}");
        }
    }
}
