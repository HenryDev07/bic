<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Core\Audit\AuditLogger;
use Bic\Modules\Finance\FinanceService;
use Bic\Tests\Support\DatabaseTestCase;

final class FinanceServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-01-15 12:00:00';

    private function fin(): FinanceService
    {
        return new FinanceService($this->db, new AuditLogger($this->db, $this->clock));
    }

    private function actor(): array
    {
        return $this->actorFor($this->makeUser('admin'));
    }

    private function account(array $actor, string $name, string $opening = '0'): int
    {
        $r = $this->fin()->saveAccount($actor, null, ['name' => $name, 'type' => 'cash', 'opening_balance' => $opening, 'is_active' => 1]);
        $this->assertTrue($r['ok']);

        return (int) $r['id'];
    }

    /** @return array<string,mixed> */
    private function reg(array $actor, array $in): array
    {
        return $this->fin()->register($actor, $in + ['occurred_at' => self::NOW], self::NOW);
    }

    private function bal(int $account): string
    {
        return $this->fin()->balance($this->businessId(), $account)->toString();
    }

    public function testAccountCreationUsesBaseCurrencyAndValidates(): void
    {
        $actor = $this->actor();
        $id = $this->account($actor, 'Caja', '100.50');
        $this->assertSame('USD', $this->db->value('SELECT currency FROM financial_accounts WHERE id = ?', [$id]));
        $this->assertSame('100.50', $this->bal($id));
        $e = $this->fin()->saveAccount($actor, null, ['name' => 'caja', 'type' => 'x', 'opening_balance' => '-5'])['errors'];
        $this->assertSame(['name', 'type', 'opening_balance'], array_keys($e));
    }

    public function testBalanceIsOpeningPlusLedgerAndIgnoresVoided(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '100');
        $this->assertTrue($this->reg($actor, ['type' => 'other_income', 'account_id' => $a, 'amount' => '50.25', 'description' => 'Venta suelta'])['ok']);
        $out = $this->reg($actor, ['type' => 'adjustment_out', 'account_id' => $a, 'amount' => '20', 'description' => 'Faltante de caja']);
        $this->assertSame('130.25', $this->bal($a));
        $this->assertTrue($this->fin()->void($actor, $out['id'], 'Error de digitación', self::NOW)['ok']);
        $this->assertSame('150.25', $this->bal($a));
        $accounts = $this->fin()->accounts($this->businessId());
        $this->assertSame('150.25', $accounts[0]['balance'], 'la lista y el cálculo individual coinciden');
    }

    public function testNoOverdraftAndNothingIsPartiallyWritten(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '10');
        $r = $this->reg($actor, ['type' => 'adjustment_out', 'account_id' => $a, 'amount' => '10.01', 'description' => 'x']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Saldo insuficiente', $r['errors']['amount']);
        $this->assertSame(0, $this->countRows('financial_transactions'));
        $this->assertTrue($this->reg($actor, ['type' => 'adjustment_out', 'account_id' => $a, 'amount' => '10.00', 'description' => 'exacto'])['ok']);
        $this->assertSame('0.00', $this->bal($a));
    }

    public function testValidationOfMovements(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '10');
        $this->assertArrayHasKey('type', $this->reg($actor, ['type' => 'nada', 'account_id' => $a, 'amount' => '1'])['errors']);
        $this->assertArrayHasKey('amount', $this->reg($actor, ['type' => 'other_income', 'account_id' => $a, 'amount' => '0'])['errors']);
        $this->assertArrayHasKey('amount', $this->reg($actor, ['type' => 'other_income', 'account_id' => $a, 'amount' => '1.999'])['errors'] + ['amount' => 'x'], 'x');
        $this->assertArrayHasKey('description', $this->reg($actor, ['type' => 'adjustment_in', 'account_id' => $a, 'amount' => '1'])['errors'], 'un ajuste exige motivo');
        $this->assertArrayHasKey('account_id', $this->reg($actor, ['type' => 'other_income', 'account_id' => 999, 'amount' => '1'])['errors']);
        $this->assertArrayHasKey('occurred_at', $this->reg($actor, ['type' => 'other_income', 'account_id' => $a, 'amount' => '1', 'occurred_at' => '2027-01-01 00:00:00'])['errors'], 'sin fechas futuras');
        $this->assertArrayHasKey('occurred_at', $this->reg($actor, ['type' => 'other_income', 'account_id' => $a, 'amount' => '1', 'occurred_at' => 'ayer'])['errors']);
    }

    public function testInactiveAccountRejectsMovementsAndNeedsZeroBalanceToClose(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '5');
        $e = $this->fin()->saveAccount($actor, $a, ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '5'])['errors'];
        $this->assertArrayHasKey('is_active', $e);
        $this->reg($actor, ['type' => 'adjustment_out', 'account_id' => $a, 'amount' => '5', 'description' => 'vaciar']);
        $this->assertTrue($this->fin()->saveAccount($actor, $a, ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '5'])['ok']);
        $this->assertArrayHasKey('account_id', $this->reg($actor, ['type' => 'other_income', 'account_id' => $a, 'amount' => '1'])['errors']);
    }

    public function testOpeningBalanceLockedOnceThereAreMovements(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '5');
        $this->assertTrue($this->fin()->saveAccount($actor, $a, ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '8', 'is_active' => 1])['ok']);
        $this->reg($actor, ['type' => 'other_income', 'account_id' => $a, 'amount' => '1']);
        $this->assertArrayHasKey('opening_balance', $this->fin()->saveAccount($actor, $a, ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '9', 'is_active' => 1])['errors']);
    }

    public function testCapitalCreatesOneLedgerEntryPerMovement(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Banco');
        $c = $this->reg($actor, ['type' => 'contribution', 'account_id' => $a, 'amount' => '1000', 'partner_name' => 'Ana', 'description' => 'Aporte inicial']);
        $this->assertTrue($c['ok']);
        $this->assertSame(1, $this->countRows('capital_movements', 'financial_transaction_id = ?', [$c['id']]));
        $w = $this->reg($actor, ['type' => 'withdrawal', 'account_id' => $a, 'amount' => '300']);
        $this->assertTrue($w['ok']);
        $this->assertSame('700.00', $this->bal($a));
        $this->assertSame('out', $this->db->value('SELECT direction FROM financial_transactions WHERE id = ?', [$w['id']]));
        $this->assertFalse($this->reg($actor, ['type' => 'withdrawal', 'account_id' => $a, 'amount' => '701'])['ok']);
        $this->assertSame([], $this->fin()->reconcile($this->businessId()));
    }

    public function testTransferMovesMoneyOnceAndVoidsBothLegs(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '200');
        $b = $this->account($actor, 'Banco', '0');
        $t = $this->reg($actor, ['type' => 'transfer', 'account_id' => $a, 'to_account_id' => $b, 'amount' => '75.5', 'description' => 'Depósito']);
        $this->assertTrue($t['ok']);
        $this->assertSame(['124.50', '75.50'], [$this->bal($a), $this->bal($b)]);
        $this->assertSame(2, $this->countRows('financial_transactions', "source_type = 'transfer'"));
        $this->assertSame(1, $this->countRows('account_transfers'));
        $this->assertSame([], $this->fin()->reconcile($this->businessId()));

        $this->assertArrayHasKey('to_account_id', $this->reg($actor, ['type' => 'transfer', 'account_id' => $a, 'to_account_id' => $a, 'amount' => '1'])['errors']);
        $this->assertFalse($this->reg($actor, ['type' => 'transfer', 'account_id' => $b, 'to_account_id' => $a, 'amount' => '80'])['ok'], 'sin saldo suficiente');

        $v = $this->fin()->void($actor, $t['id'], 'Transferencia equivocada', self::NOW);
        $this->assertTrue($v['ok']);
        $this->assertSame(['200.00', '0.00'], [$this->bal($a), $this->bal($b)]);
        $this->assertSame(2, $this->countRows('financial_transactions', 'voided_at IS NOT NULL'));
        $this->assertSame([], $this->fin()->reconcile($this->businessId()));
    }

    public function testVoidRules(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '0');
        $in = $this->reg($actor, ['type' => 'other_income', 'account_id' => $a, 'amount' => '50']);
        $this->reg($actor, ['type' => 'adjustment_out', 'account_id' => $a, 'amount' => '40', 'description' => 'retiro']);

        $this->assertArrayHasKey('error', $this->fin()->void($actor, $in['id'], '', self::NOW));
        $this->assertSame('No se puede anular: la cuenta quedaría con saldo negativo.', $this->fin()->void($actor, $in['id'], 'x', self::NOW)['error']);
        $this->assertSame('10.00', $this->bal($a), 'el intento fallido no cambia nada');
        $this->assertSame(404, $this->fin()->void($actor, 9999, 'x', self::NOW)['status']);

        $other = $this->account($actor, 'Otra', '5');
        $tx = $this->reg($actor, ['type' => 'other_income', 'account_id' => $other, 'amount' => '1']);
        $this->assertTrue($this->fin()->void($actor, $tx['id'], 'dup', self::NOW)['ok']);
        $this->assertSame('El movimiento ya está anulado.', $this->fin()->void($actor, $tx['id'], 'dup', self::NOW)['error']);

        $this->db->execute("INSERT INTO financial_transactions (business_id, account_id, direction, amount, occurred_at, source_type, description) VALUES (?, ?, 'in', 5, ?, 'expense', 'x')", [$this->businessId(), $other, self::NOW]);
        $doc = (int) $this->db->value("SELECT id FROM financial_transactions WHERE source_type = 'expense'");
        $this->assertStringContainsString('otro documento', (string) $this->fin()->void($actor, $doc, 'x', self::NOW)['error']);
    }

    public function testLedgerFiltersAndAudit(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '100');
        $b = $this->account($actor, 'Banco', '0');
        $this->reg($actor, ['type' => 'other_income', 'account_id' => $a, 'amount' => '10']);
        $this->reg($actor, ['type' => 'transfer', 'account_id' => $a, 'to_account_id' => $b, 'amount' => '20']);
        $biz = $this->businessId();
        $this->assertSame(3, $this->fin()->ledger($biz, [], 50, 0)['total']);
        $this->assertSame(1, $this->fin()->ledger($biz, ['source' => 'other_income'], 50, 0)['total']);
        $this->assertSame(1, $this->fin()->ledger($biz, ['account_id' => $b], 50, 0)['total']);
        $this->assertSame(2, $this->fin()->ledger($biz, ['direction' => 'in'], 50, 0)['total']);
        $this->assertSame(0, $this->fin()->ledger($biz, ['from' => '2026-02-01 00:00:00'], 50, 0)['total']);
        $this->assertSame(0, $this->fin()->ledger($biz, ['source' => 'inventada'], 50, 0)['total'] - 3, 'origen desconocido se ignora');
        $this->assertGreaterThan(0, $this->countRows('audit_logs', "action = 'finance.other_income'"));
    }

    public function testReconciliationDetectsOrphans(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '0');
        $this->db->execute("INSERT INTO financial_transactions (business_id, account_id, direction, amount, occurred_at, source_type, description) VALUES (?, ?, 'in', 5, ?, 'capital', 'huérfano')", [$this->businessId(), $a, self::NOW]);
        $issues = $this->fin()->reconcile($this->businessId());
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('capital sin movimiento', $issues[0]);
    }

    public function testDatabaseRejectsNonPositiveAmountsAndDuplicateDocuments(): void
    {
        $actor = $this->actor();
        $a = $this->account($actor, 'Caja', '0');
        $threw = false;
        try {
            $this->db->execute("INSERT INTO financial_transactions (business_id, account_id, direction, amount, occurred_at, source_type, description) VALUES (?, ?, 'in', 0, ?, 'other_income', 'x')", [$this->businessId(), $a, self::NOW]);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'CHECK amount > 0');

        $c = $this->reg($actor, ['type' => 'contribution', 'account_id' => $a, 'amount' => '5']);
        $threw = false;
        try {
            $this->db->execute("INSERT INTO capital_movements (business_id, financial_transaction_id, kind) VALUES (?, ?, 'contribution')", [$this->businessId(), $c['id']]);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'un asiento solo puede tener un documento (UNIQUE)');
    }
}
