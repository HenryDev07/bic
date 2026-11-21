<?php

declare(strict_types=1);

namespace Bic\Tests\Database;

use Bic\Core\Database\Provisioner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProvisionerTest extends TestCase
{
    /** @return list<string> */
    private function statements(): array
    {
        return Provisioner::grantStatements(
            'business_intelligence_commerce',
            'bic_app',
            'localhost',
            ['users', 'audit_logs', 'stock_movements', 'schema_migrations', 'settings', 'financial_transactions', 'product_price_history', 'supplier_prices', 'purchase_receipts', 'purchase_receipt_lines', 'purchase_payments', 'order_status_history', 'order_payments', 'order_returns', 'order_return_lines', 'research_prices']
        );
    }

    public function testAppUserNeverReceivesStructuralPrivileges(): void
    {
        foreach ($this->statements() as $sql) {
            if (str_starts_with($sql, 'GRANT')) {
                $this->assertDoesNotMatchRegularExpression('/\b(CREATE|ALTER|DROP|INDEX|REFERENCES|ALL|GRANT OPTION|SUPER|FILE)\b/', $sql);
            }
        }
    }

    public function testAuditAndLedgerAreAppendOnly(): void
    {
        $sql = implode("\n", $this->statements());
        $this->assertStringContainsString("GRANT SELECT, INSERT ON `business_intelligence_commerce`.`audit_logs`", $sql);
        $this->assertStringContainsString("GRANT SELECT, INSERT ON `business_intelligence_commerce`.`stock_movements`", $sql);
        $this->assertStringContainsString("GRANT SELECT ON `business_intelligence_commerce`.`schema_migrations`", $sql);
        $this->assertStringContainsString("GRANT SELECT, INSERT, UPDATE, DELETE ON `business_intelligence_commerce`.`users`", $sql);
    }

    public function testLedgerCannotBeDeletedAndHistoriesAreAppendOnly(): void
    {
        $sql = implode("\n", $this->statements());
        $this->assertStringContainsString("GRANT SELECT, INSERT, UPDATE ON `business_intelligence_commerce`.`financial_transactions`", $sql);
        $this->assertStringContainsString("GRANT SELECT, INSERT ON `business_intelligence_commerce`.`product_price_history`", $sql);
        $this->assertStringContainsString("GRANT SELECT, INSERT ON `business_intelligence_commerce`.`supplier_prices`", $sql);
        foreach (['stock_movements', 'purchase_receipts', 'purchase_receipt_lines', 'purchase_payments', 'order_payments', 'order_status_history', 'order_returns', 'order_return_lines', 'research_prices'] as $t) {
            $this->assertStringContainsString("GRANT SELECT, INSERT ON `business_intelligence_commerce`.`{$t}`", $sql);
        }
    }

    public function testGrantsAreResetFirstAndNeverTargetRoot(): void
    {
        $statements = $this->statements();
        $this->assertStringStartsWith('REVOKE ALL PRIVILEGES', $statements[0]);
        $this->assertStringNotContainsString("'root'", implode("\n", $statements));
    }

    public function testNoGlobalGrantIsEverIssued(): void
    {
        foreach ($this->statements() as $sql) {
            $this->assertStringNotContainsString('.*', $sql);
        }
    }

    public function testRejectsInjectionInIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Provisioner::grantStatements('db', "bic_app'; DROP USER 'x", 'localhost', []);
    }
}
