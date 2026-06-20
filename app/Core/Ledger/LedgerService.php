<?php

declare(strict_types=1);

namespace App\Core\Ledger;

use App\Core\Ledger\Exceptions\InsufficientFundsException;
use App\Core\Ledger\Exceptions\WalletFrozenException;
use App\Core\Support\Money;
use PDO;

/**
 * The only code path permitted to write wallets.balance or ledger_entries.
 * Every module (investment payouts, forex trade settlement, real estate
 * distributions, the internal transfer page) calls into this class rather
 * than touching the ledger tables directly.
 */
final class LedgerService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WalletService $wallets,
    ) {
    }

    /**
     * Posts a balanced set of debit/credit entries atomically. $entries is a
     * list of ['wallet_id' => int, 'direction' => 'debit'|'credit',
     * 'amount' => Money, 'entry_type' => string, 'created_by_type' => string,
     * 'created_by_id' => ?int]. Debits must equal credits or this throws.
     */
    public function postTransaction(
        array $entries,
        string $type,
        ?string $module = null,
        ?int $initiatedByUserId = null,
        ?string $reference = null,
        ?int $reversalOfTransactionId = null,
    ): int {
        $this->assertBalanced($entries);

        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $txnStmt = $this->pdo->prepare(
                'INSERT INTO transactions (type, status, module, initiated_by_user_id, reference, reversal_of_transaction_id, completed_at)
                 VALUES (:type, "completed", :module, :initiated_by, :reference, :reversal_of, NOW())'
            );
            $txnStmt->execute([
                'type' => $type,
                'module' => $module,
                'initiated_by' => $initiatedByUserId,
                'reference' => $reference,
                'reversal_of' => $reversalOfTransactionId,
            ]);
            $transactionId = (int) $this->pdo->lastInsertId();

            // Lock every distinct wallet involved, in a stable order, before
            // mutating any of them - avoids deadlocks under concurrent transfers.
            $walletIds = array_unique(array_map(static fn (array $e) => $e['wallet_id'], $entries));
            sort($walletIds);
            $lockedWallets = [];
            foreach ($walletIds as $walletId) {
                $lockedWallets[$walletId] = $this->wallets->lockWalletForUpdate($walletId);
            }

            foreach ($entries as $entry) {
                $wallet = $lockedWallets[$entry['wallet_id']];
                $amount = $entry['amount'];
                assert($amount instanceof Money);

                if ($entry['direction'] === 'debit') {
                    if (in_array($wallet['status'], ['frozen_debit', 'frozen_all'], true)) {
                        throw new WalletFrozenException("Wallet {$wallet['id']} is frozen for debits.");
                    }

                    if (!$this->wallets->isSystemHouseWallet($wallet)) {
                        $available = $this->wallets->availableBalance($wallet);
                        if ($available->subtract($amount)->isNegative()) {
                            throw new InsufficientFundsException("Wallet {$wallet['id']} has insufficient available balance.");
                        }
                    }

                    $newBalance = Money::fromString($wallet['balance'])->subtract($amount);
                } else {
                    if ($wallet['status'] === 'frozen_all') {
                        throw new WalletFrozenException("Wallet {$wallet['id']} is frozen for all activity.");
                    }

                    $newBalance = Money::fromString($wallet['balance'])->add($amount);
                }

                $this->pdo->prepare('UPDATE wallets SET balance = :balance WHERE id = :id')->execute([
                    'balance' => $newBalance->toString(),
                    'id' => $wallet['id'],
                ]);
                // Keep the in-memory snapshot current in case the same wallet
                // appears in multiple entries within this transaction.
                $lockedWallets[$wallet['id']]['balance'] = $newBalance->toString();

                $entryStmt = $this->pdo->prepare(
                    'INSERT INTO ledger_entries
                        (transaction_id, wallet_id, direction, amount, balance_after, entry_type, created_by_type, created_by_id)
                     VALUES (:transaction_id, :wallet_id, :direction, :amount, :balance_after, :entry_type, :created_by_type, :created_by_id)'
                );
                $entryStmt->execute([
                    'transaction_id' => $transactionId,
                    'wallet_id' => $wallet['id'],
                    'direction' => $entry['direction'],
                    'amount' => $amount->toString(),
                    'balance_after' => $newBalance->toString(),
                    'entry_type' => $entry['entry_type'],
                    'created_by_type' => $entry['created_by_type'] ?? 'system',
                    'created_by_id' => $entry['created_by_id'] ?? null,
                ]);
            }

            if (!$alreadyInTransaction) {
                $this->pdo->commit();
            }

            return $transactionId;
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Moves the user's own money between their 4 sub-ledgers. Instant, free,
     * no external payment rail involved - just a debit/credit pair.
     */
    public function transferInternal(int $userId, string $fromLedgerType, string $toLedgerType, Money $amount, string $currency = 'USD'): int
    {
        if ($fromLedgerType === $toLedgerType) {
            throw new \InvalidArgumentException('Cannot transfer a ledger to itself.');
        }

        $fromWallet = $this->wallets->getOrCreateWallet($userId, $fromLedgerType, $currency);
        $toWallet = $this->wallets->getOrCreateWallet($userId, $toLedgerType, $currency);

        return $this->postTransaction(
            entries: [
                ['wallet_id' => $fromWallet['id'], 'direction' => 'debit', 'amount' => $amount, 'entry_type' => 'internal_transfer_out', 'created_by_type' => 'user', 'created_by_id' => $userId],
                ['wallet_id' => $toWallet['id'], 'direction' => 'credit', 'amount' => $amount, 'entry_type' => 'internal_transfer_in', 'created_by_type' => 'user', 'created_by_id' => $userId],
            ],
            type: 'internal_transfer',
            module: null,
            initiatedByUserId: $userId,
        );
    }

    /**
     * Credits a user's `available` wallet from an external rail (payment
     * gateway, bank, crypto deposit address), with the system house wallet
     * as the double-entry counterparty so nothing is ever a one-sided entry.
     */
    public function depositExternal(int $userId, Money $amount, string $reference, string $currency = 'USD'): int
    {
        $userWallet = $this->wallets->getOrCreateWallet($userId, 'available', $currency);
        $houseWallet = $this->wallets->getSystemHouseWallet($currency);

        return $this->postTransaction(
            entries: [
                ['wallet_id' => $houseWallet['id'], 'direction' => 'debit', 'amount' => $amount, 'entry_type' => 'external_deposit_clearing', 'created_by_type' => 'system'],
                ['wallet_id' => $userWallet['id'], 'direction' => 'credit', 'amount' => $amount, 'entry_type' => 'deposit', 'created_by_type' => 'user', 'created_by_id' => $userId],
            ],
            type: 'deposit',
            initiatedByUserId: $userId,
            reference: $reference,
        );
    }

    /**
     * Debits a user's `available` wallet to pay out to an external rail.
     * Called once an approved withdrawal_requests row is ready to execute -
     * never directly from a controller.
     */
    public function withdrawExternal(int $userId, Money $amount, string $reference, ?int $approvedByAdminId, string $currency = 'USD'): int
    {
        $userWallet = $this->wallets->getOrCreateWallet($userId, 'available', $currency);
        $houseWallet = $this->wallets->getSystemHouseWallet($currency);

        return $this->postTransaction(
            entries: [
                ['wallet_id' => $userWallet['id'], 'direction' => 'debit', 'amount' => $amount, 'entry_type' => 'withdrawal', 'created_by_type' => $approvedByAdminId ? 'admin' : 'user', 'created_by_id' => $approvedByAdminId ?? $userId],
                ['wallet_id' => $houseWallet['id'], 'direction' => 'credit', 'amount' => $amount, 'entry_type' => 'external_withdrawal_clearing', 'created_by_type' => 'system'],
            ],
            type: 'withdrawal',
            initiatedByUserId: $userId,
            reference: $reference,
        );
    }

    /**
     * Reverses a completed transaction by posting a new transaction with
     * every entry's direction flipped - the original row is never edited,
     * only marked reversed.
     */
    public function reverseTransaction(int $transactionId, int $adminId, string $reason): int
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ledger_entries WHERE transaction_id = :id');
        $stmt->execute(['id' => $transactionId]);
        $originalEntries = $stmt->fetchAll();

        if ($originalEntries === []) {
            throw new \RuntimeException("Transaction {$transactionId} has no ledger entries to reverse.");
        }

        $reversedEntries = array_map(static function (array $entry) {
            return [
                'wallet_id' => (int) $entry['wallet_id'],
                'direction' => $entry['direction'] === 'debit' ? 'credit' : 'debit',
                'amount' => Money::fromString($entry['amount']),
                'entry_type' => 'reversal',
                'created_by_type' => 'admin',
            ];
        }, $originalEntries);

        $this->pdo->beginTransaction();
        try {
            $reversalTransactionId = $this->postTransaction(
                entries: $reversedEntries,
                type: 'reversal',
                initiatedByUserId: null,
                reference: $reason,
                reversalOfTransactionId: $transactionId,
            );

            $this->pdo->prepare('UPDATE transactions SET status = "reversed" WHERE id = :id')
                ->execute(['id' => $transactionId]);

            $this->pdo->commit();

            return $reversalTransactionId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function assertBalanced(array $entries): void
    {
        if ($entries === []) {
            throw new \InvalidArgumentException('A transaction must have at least one entry.');
        }

        $debits = Money::zero();
        $credits = Money::zero();

        foreach ($entries as $entry) {
            if (!($entry['amount'] instanceof Money) || !$entry['amount']->isPositive()) {
                throw new \InvalidArgumentException('Every ledger entry amount must be a positive Money value.');
            }

            if ($entry['direction'] === 'debit') {
                $debits = $debits->add($entry['amount']);
            } elseif ($entry['direction'] === 'credit') {
                $credits = $credits->add($entry['amount']);
            } else {
                throw new \InvalidArgumentException("Invalid entry direction: {$entry['direction']}");
            }
        }

        if (!$debits->equals($credits)) {
            throw new \InvalidArgumentException("Unbalanced transaction: debits={$debits} credits={$credits}");
        }
    }
}
