<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Tests\Support\AppTestCase;

/** Peticiones completas de gastos, estado de resultados e investigación (etapa 4f). */
final class ExpensesResearchFlowTest extends AppTestCase
{
    public function testAdminFlow(): void
    {
        $this->loginAs('admin');
        $this->post('/finance/accounts', ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '200', 'is_active' => '1']);
        $acc = (string) $this->db->value('SELECT id FROM financial_accounts');
        $this->assertSame(200, $this->get('/expenses')->status);
        $this->assertSame(200, $this->get('/expenses/create')->status);
        $cat = (string) $this->db->value('SELECT id FROM expense_categories LIMIT 1');
        $bad = $this->post('/expenses', ['category_id' => $cat, 'account_id' => $acc, 'amount' => '0', 'description' => 'x']);
        $this->assertSame(422, $bad->status);
        $this->assertSame(302, $this->post('/expenses', ['category_id' => $cat, 'account_id' => $acc, 'amount' => '40', 'description' => 'Alquiler de enero'])->status);
        $this->assertStringContainsString('Alquiler de enero', $this->get('/expenses')->body);
        $this->assertStringContainsString('40.00', $this->get('/expenses/profit')->body);
        $eid = (int) $this->db->value('SELECT id FROM expenses');
        $this->post("/expenses/{$eid}/void", ['reason' => 'Duplicado']);
        $this->assertSame(1, $this->countRows('financial_transactions', "source_type = 'expense' AND voided_at IS NOT NULL"));

        $this->assertSame(422, $this->post('/research', ['name' => ''])->status);
        $this->assertSame(302, $this->post('/research', ['name' => 'Termo', 'estimated_cost' => '6', 'target_price' => '10'])->status);
        $rid = (int) $this->db->value('SELECT id FROM research_items');
        $this->post("/research/{$rid}/prices", ['source' => 'Tienda A', 'observed_price' => '9.50']);
        $page = $this->get("/research/{$rid}");
        $this->assertStringContainsString('Tienda A', $page->body);
        $this->assertStringContainsString('40.00', $page->body, 'margen');
    }

    public function testPermissions(): void
    {
        $this->loginAs('seller');
        foreach (['/expenses', '/expenses/profit', '/expenses/create', '/research', '/research/create'] as $p) {
            $this->assertSame(403, $this->get($p)->status, $p);
        }
        $this->post('/logout');
        $this->loginAs('analyst');
        $this->assertSame(200, $this->get('/expenses/profit')->status);
        $this->assertSame(200, $this->get('/research')->status);
        $this->assertSame(403, $this->get('/expenses/create')->status);
        $this->assertSame(403, $this->post('/research', ['name' => 'x'])->status);
        $this->assertSame(404, $this->get('/research/9999')->status);
    }
}
