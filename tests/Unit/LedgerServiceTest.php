<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Db\Connection;
use App\Core\Ledger\Exceptions\InsufficientFundsException;
use App\Core\Ledger\Exceptions\WalletFrozenException;
use App\Core\Ledger\LedgerService;
use App\Core\Ledger\WalletService;
use App\Core\Support\Money;
use PDO;
use PHPUnit\Framework\TestCase;

final class LedgerServiceTest extends TestCase
{
    private PDO $pdo;
    private WalletService $wallets;
    private LedgerService $ledger;

    protected function setUp(): void
    {
        $this->pdo = Connection::get();

        // Reset to a known-empty state between tests, preserving the system
        // house account that LedgerService's external deposit/withdraw paths
        // depend on.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('TRUNCATE TABLE ledger_entries');
        $this->pdo->exec('TRUNCATE TABLE transactions');
        $this->pdo->exec('TRUNCATE TABLE wallets');
        $this->pdo->exec("DELETE FROM users WHERE email != 'system@hinace.internal'");
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->wallets = new WalletService($this->pdo);
        $this->ledger = new LedgerService($this->pdo, $this->wallets);
    }

    private function createUser(string $email): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (email, password_hash, referral_code) VALUES (:email, 'hash', :code)"
        );
        $stmt->execute(['email' => $email, 'code' => strtoupper(substr(md5($email), 0, 10))]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testDepositExternalCreditsAvailableWallet(): void
    {
        $userId = $this->createUser('depositor@example.com');

        $this->ledger->depositExternal($userId, Money::fromString('100.00'), 'ref-1');

        $wallet = $this->wallets->getOrCreateWallet($userId, 'available');
        $this->assertTrue(Money::fromString($wallet['balance'])->equals(Money::fromString('100.00')));

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testTransferInternalMovesBetweenLedgers(): void
    {
        $userId = $this->createUser('transferer@example.com');
        $this->ledger->depositExternal($userId, Money::fromString('100.00'), 'ref-2');

        $this->ledger->transferInternal($userId, 'available', 'investment', Money::fromString('40.00'));

        $available = $this->wallets->getOrCreateWallet($userId, 'available');
        $investment = $this->wallets->getOrCreateWallet($userId, 'investment');

        $this->assertTrue(Money::fromString($available['balance'])->equals(Money::fromString('60.00')));
        $this->assertTrue(Money::fromString($investment['balance'])->equals(Money::fromString('40.00')));
    }

    public function testWithdrawExternalDebitsAvailableWallet(): void
    {
        $userId = $this->createUser('withdrawer@example.com');
        $this->ledger->depositExternal($userId, Money::fromString('100.00'), 'ref-3');

        $this->ledger->withdrawExternal($userId, Money::fromString('30.00'), 'ref-4', approvedByAdminId: null);

        $wallet = $this->wallets->getOrCreateWallet($userId, 'available');
        $this->assertTrue(Money::fromString($wallet['balance'])->equals(Money::fromString('70.00')));
    }

    public function testInsufficientFundsBlocksOverdraw(): void
    {
        $userId = $this->createUser('shortfunds@example.com');
        $this->ledger->depositExternal($userId, Money::fromString('10.00'), 'ref-5');

        $this->expectException(InsufficientFundsException::class);
        $this->ledger->transferInternal($userId, 'available', 'forex', Money::fromString('50.00'));
    }

    public function testFrozenDebitWalletBlocksDebitButAllowsCredit(): void
    {
        $userId = $this->createUser('frozen-debit@example.com');
        $wallet = $this->wallets->getOrCreateWallet($userId, 'available');
        $this->wallets->setStatus((int) $wallet['id'], 'frozen_debit');

        // Credits still land - freezing debits never blocks incoming funds.
        $this->ledger->depositExternal($userId, Money::fromString('25.00'), 'ref-6');
        $wallet = $this->wallets->getOrCreateWallet($userId, 'available');
        $this->assertTrue(Money::fromString($wallet['balance'])->equals(Money::fromString('25.00')));

        $this->expectException(WalletFrozenException::class);
        $this->ledger->transferInternal($userId, 'available', 'investment', Money::fromString('5.00'));
    }

    public function testFrozenAllWalletBlocksCredits(): void
    {
        $userId = $this->createUser('frozen-all@example.com');
        $wallet = $this->wallets->getOrCreateWallet($userId, 'available');
        $this->wallets->setStatus((int) $wallet['id'], 'frozen_all');

        $this->expectException(WalletFrozenException::class);
        $this->ledger->depositExternal($userId, Money::fromString('25.00'), 'ref-7');
    }

    public function testReverseTransactionPostsBalancedOffsettingEntries(): void
    {
        $userId = $this->createUser('reversed@example.com');
        $transactionId = $this->ledger->depositExternal($userId, Money::fromString('75.00'), 'ref-8');

        $reversalId = $this->ledger->reverseTransaction($transactionId, adminId: 1, reason: 'fraud review');

        $wallet = $this->wallets->getOrCreateWallet($userId, 'available');
        $this->assertTrue(Money::fromString($wallet['balance'])->isZero());

        $status = $this->pdo->prepare('SELECT status FROM transactions WHERE id = :id');
        $status->execute(['id' => $transactionId]);
        $this->assertSame('reversed', $status->fetchColumn());

        $entries = $this->pdo->prepare('SELECT direction, amount FROM ledger_entries WHERE transaction_id = :id ORDER BY direction');
        $entries->execute(['id' => $reversalId]);
        $rows = $entries->fetchAll();
        $this->assertCount(2, $rows);

        $debits = Money::zero();
        $credits = Money::zero();
        foreach ($rows as $row) {
            $amount = Money::fromString($row['amount']);
            $debits = $row['direction'] === 'debit' ? $debits->add($amount) : $debits;
            $credits = $row['direction'] === 'credit' ? $credits->add($amount) : $credits;
        }
        $this->assertTrue($debits->equals($credits));
    }

    public function testUnbalancedTransactionIsRejected(): void
    {
        $userId = $this->createUser('unbalanced@example.com');
        $wallet = $this->wallets->getOrCreateWallet($userId, 'available');
        $house = $this->wallets->getSystemHouseWallet();

        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->postTransaction(
            entries: [
                ['wallet_id' => $house['id'], 'direction' => 'debit', 'amount' => Money::fromString('10.00'), 'entry_type' => 'test'],
                ['wallet_id' => $wallet['id'], 'direction' => 'credit', 'amount' => Money::fromString('5.00'), 'entry_type' => 'test'],
            ],
            type: 'test',
        );
    }
}
