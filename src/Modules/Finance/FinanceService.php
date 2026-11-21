<?php

declare(strict_types=1);

namespace Bic\Modules\Finance;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Núcleo financiero. Reglas: el saldo se calcula (saldo inicial + asientos vigentes); los asientos
 * no se editan ni se borran, se anulan; ninguna cuenta queda en negativo; cada documento ↔ un asiento.
 */
final class FinanceService
{
    public const SOURCES = [
        'capital' => 'Capital', 'transfer' => 'Transferencia', 'other_income' => 'Otro ingreso', 'adjustment' => 'Ajuste',
        'order_payment' => 'Cobro de pedido', 'purchase_payment' => 'Pago a proveedor', 'expense' => 'Gasto',
    ];
    /** Asientos que pertenecen a un documento de otro módulo: se anulan desde su documento. */
    private const DOCUMENT_SOURCES = ['order_payment', 'purchase_payment', 'expense'];
    public const TYPES = [
        'other_income' => 'Otro ingreso', 'adjustment_in' => 'Ajuste de entrada', 'adjustment_out' => 'Ajuste de salida',
        'contribution' => 'Aporte de capital', 'withdrawal' => 'Retiro de capital', 'transfer' => 'Transferencia entre cuentas',
    ];

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    // ---------- cuentas ----------

    /** @return list<array<string,mixed>> cuentas con 'balance' (string, 2 decimales) */
    public function accounts(int $biz): array
    {
        $accounts = $this->db->select('SELECT * FROM financial_accounts WHERE business_id = ? ORDER BY is_active DESC, name, id', [$biz]);
        $sums = [];
        foreach ($this->db->select(
            "SELECT account_id, direction, SUM(amount) AS total FROM financial_transactions WHERE business_id = ? AND voided_at IS NULL GROUP BY account_id, direction",
            [$biz]
        ) as $r) {
            $sums[(int) $r['account_id']][(string) $r['direction']] = Decimal::from($r['total'], 2);
        }
        foreach ($accounts as &$a) {
            $s = $sums[(int) $a['id']] ?? [];
            $a['balance'] = Decimal::from($a['opening_balance'], 2)->add($s['in'] ?? Decimal::zero())->sub($s['out'] ?? Decimal::zero())->toString();
        }
        unset($a);

        return $accounts;
    }

    public function balance(int $biz, int $accountId): Decimal
    {
        $row = $this->db->first('SELECT opening_balance FROM financial_accounts WHERE id = ? AND business_id = ?', [$accountId, $biz]);
        $bal = Decimal::from($row['opening_balance'] ?? 0, 2);
        foreach ($this->db->select(
            'SELECT direction, SUM(amount) AS total FROM financial_transactions WHERE account_id = ? AND business_id = ? AND voided_at IS NULL GROUP BY direction',
            [$accountId, $biz]
        ) as $r) {
            $t = Decimal::from($r['total'], 2);
            $bal = $r['direction'] === 'in' ? $bal->add($t) : $bal->sub($t);
        }

        return $bal;
    }

    /** @return array<string,mixed>|null */
    public function account(int $biz, int $id): ?array
    {
        return $this->db->first('SELECT * FROM financial_accounts WHERE id = ? AND business_id = ?', [$id, $biz]);
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function saveAccount(array $actor, ?int $id, array $in): array
    {
        $biz = (int) $actor['business_id'];
        $old = $id !== null ? $this->account($biz, $id) : null;
        if ($id !== null && $old === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'El nombre es obligatorio (máx. 120 caracteres).';
        } elseif ((int) $this->db->value('SELECT COUNT(*) FROM financial_accounts WHERE business_id = ? AND LOWER(name) = LOWER(?) AND id <> ?', [$biz, $name, $id ?? 0]) > 0) {
            $errors['name'] = 'Ya existe una cuenta con ese nombre.';
        }
        $type = (string) ($in['type'] ?? 'cash');
        if (!in_array($type, ['cash', 'bank', 'wallet', 'other'], true)) {
            $errors['type'] = 'Tipo no válido.';
        }
        $opening = Decimal::fromInput(($in['opening_balance'] ?? '') === '' ? '0' : $in['opening_balance'], 2);
        if ($opening === null || $opening->isNegative()) {
            $errors['opening_balance'] = 'El saldo inicial debe ser un número mayor o igual a 0.';
        }
        $active = $id === null || !empty($in['is_active']) ? 1 : 0;
        if ($old !== null && $opening !== null) {
            $hasTx = (int) $this->db->value('SELECT COUNT(*) FROM financial_transactions WHERE account_id = ?', [$id]) > 0;
            if ($hasTx && !$opening->equals(Decimal::from($old['opening_balance'], 2))) {
                $errors['opening_balance'] = 'El saldo inicial no se puede cambiar cuando la cuenta ya tiene movimientos; registra un ajuste.';
            }
            if ($active === 0 && (int) $old['is_active'] === 1 && !$this->balance($biz, (int) $id)->isZero()) {
                $errors['is_active'] = 'Solo se puede desactivar una cuenta con saldo cero.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => $id, 'status' => 422];
        }
        $currency = (string) $this->db->value('SELECT base_currency FROM businesses WHERE id = ?', [$biz]);
        $v = ['name' => $name, 'type' => $type, 'opening_balance' => $opening->toString(), 'is_active' => $active];

        $newId = $this->db->transaction(function () use ($biz, $id, $v, $currency, $old, $actor): int {
            if ($id === null) {
                $newId = $this->db->insert('INSERT INTO financial_accounts (business_id, name, type, currency, opening_balance, is_active) VALUES (?, ?, ?, ?, ?, ?)',
                    [$biz, $v['name'], $v['type'], $currency, $v['opening_balance'], $v['is_active']]);
            } else {
                $this->db->execute('UPDATE financial_accounts SET name = ?, type = ?, opening_balance = ?, is_active = ? WHERE id = ? AND business_id = ?',
                    [$v['name'], $v['type'], $v['opening_balance'], $v['is_active'], $id, $biz]);
                $newId = $id;
            }
            $this->log($actor, $id === null ? 'finance.account_create' : 'finance.account_update', 'financial_accounts', $newId, $old === null ? null : array_intersect_key($old, $v), $v);

            return $newId;
        });

        return ['ok' => true, 'errors' => [], 'id' => $newId, 'status' => 200];
    }

    // ---------- movimientos ----------

    /**
     * Punto único de registro manual: otro ingreso, ajustes, capital y transferencias.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in  type, account_id, to_account_id, amount, occurred_at (UTC 'Y-m-d H:i:s'), description, partner_name
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function register(array $actor, array $in, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $errors = [];
        $type = (string) ($in['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            return ['ok' => false, 'errors' => ['type' => 'Tipo de movimiento no válido.'], 'id' => null, 'status' => 422];
        }
        $accountId = (int) ($in['account_id'] ?? 0);
        $account = $this->account($biz, $accountId);
        if ($account === null || (int) $account['is_active'] !== 1) {
            $errors['account_id'] = 'Selecciona una cuenta activa.';
        }
        $amount = Decimal::fromInput($in['amount'] ?? '', 2);
        if ($amount === null || !$amount->isPositive()) {
            $errors['amount'] = 'El importe debe ser mayor que 0 (máximo 2 decimales).';
        }
        $at = (string) ($in['occurred_at'] ?? $nowUtc);
        $when = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $at, new DateTimeZone('UTC'));
        $limit = (new DateTimeImmutable($nowUtc, new DateTimeZone('UTC')))->modify('+5 minutes');
        if ($when === false || $when->format('Y-m-d H:i:s') !== $at || $when > $limit) {
            $errors['occurred_at'] = 'La fecha no es válida ni puede ser futura.';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        $partner = trim((string) ($in['partner_name'] ?? ''));
        if (in_array($type, ['adjustment_in', 'adjustment_out'], true) && $desc === '') {
            $errors['description'] = 'Un ajuste exige explicar el motivo.';
        }
        if (mb_strlen($desc) > 255 || mb_strlen($partner) > 150) {
            $errors['description'] = 'Texto demasiado largo.';
        }
        $to = null;
        if ($type === 'transfer') {
            $to = $this->account($biz, (int) ($in['to_account_id'] ?? 0));
            if ($to === null || (int) $to['is_active'] !== 1) {
                $errors['to_account_id'] = 'Selecciona la cuenta de destino.';
            } elseif ($accountId === (int) $to['id']) {
                $errors['to_account_id'] = 'El origen y el destino deben ser distintos.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null, 'status' => 422];
        }

        try {
            $id = $this->db->transaction(function () use ($biz, $actor, $type, $accountId, $amount, $at, $desc, $partner, $to, $in): int {
                $locks = $to === null ? [$accountId] : [min($accountId, (int) $to['id']), max($accountId, (int) $to['id'])];
                foreach ($locks as $lock) { // orden fijo: evita bloqueos mutuos
                    $this->db->value('SELECT id FROM financial_accounts WHERE id = ? AND business_id = ?' . $this->db->forUpdate(), [$lock, $biz]);
                }
                $notes = $desc !== '' ? $desc : null;
                switch ($type) {
                    case 'other_income':
                    case 'adjustment_in':
                    case 'adjustment_out':
                        $source = $type === 'other_income' ? 'other_income' : 'adjustment';
                        $dir = $type === 'adjustment_out' ? 'out' : 'in';

                        return $this->post($biz, $accountId, $dir, $amount, $at, $source, $desc !== '' ? $desc : 'Otro ingreso', null, $actor);
                    case 'contribution':
                    case 'withdrawal':
                        $dir = $type === 'contribution' ? 'in' : 'out';
                        $txId = $this->post($biz, $accountId, $dir, $amount, $at, 'capital', $desc !== '' ? $desc : self::TYPES[$type], null, $actor);
                        $this->db->execute('INSERT INTO capital_movements (business_id, financial_transaction_id, kind, partner_name, notes) VALUES (?, ?, ?, ?, ?)',
                            [$biz, $txId, $type, $partner === '' ? null : $partner, $notes]);

                        return $txId;
                    default: // transfer
                        $label = 'Transferencia ' . ($desc !== '' ? '— ' . $desc : '');
                        $out = $this->post($biz, $accountId, 'out', $amount, $at, 'transfer', trim($label), null, $actor);
                        $inId = $this->post($biz, (int) $to['id'], 'in', $amount, $at, 'transfer', trim($label), null, $actor);
                        $this->db->execute('INSERT INTO account_transfers (business_id, out_transaction_id, in_transaction_id, notes) VALUES (?, ?, ?, ?)', [$biz, $out, $inId, $notes]);

                        return $out;
                }
            });
        } catch (InsufficientFunds $e) {
            return ['ok' => false, 'errors' => ['amount' => $e->getMessage()], 'id' => null, 'status' => 422];
        }

        return ['ok' => true, 'errors' => [], 'id' => $id, 'status' => 200];
    }

    /**
     * Anula un asiento (y su contraparte si es una transferencia). Exige motivo.
     *
     * @param array<string,mixed> $actor
     * @return array{ok:bool,error:?string,status:int}
     */
    public function void(array $actor, int $txId, string $reason, string $nowUtc, bool $fromDocument = false): array
    {
        $biz = (int) $actor['business_id'];
        $reason = trim($reason);
        $tx = $this->db->first('SELECT * FROM financial_transactions WHERE id = ? AND business_id = ?', [$txId, $biz]);
        if ($tx === null) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            return ['ok' => false, 'error' => 'Indica el motivo de la anulación (máx. 255 caracteres).', 'status' => 422];
        }
        if ($tx['voided_at'] !== null) {
            return ['ok' => false, 'error' => 'El movimiento ya está anulado.', 'status' => 422];
        }
        if (!$fromDocument && in_array($tx['source_type'], self::DOCUMENT_SOURCES, true)) {
            return ['ok' => false, 'error' => 'Este asiento pertenece a otro documento: anúlalo desde ese documento.', 'status' => 422];
        }

        try {
            $this->db->transaction(function () use ($biz, $actor, $tx, $reason, $nowUtc): void {
                $ids = [(int) $tx['id']];
                if ($tx['source_type'] === 'transfer') {
                    $pair = $this->db->first('SELECT out_transaction_id AS o, in_transaction_id AS i FROM account_transfers WHERE business_id = ? AND (out_transaction_id = ? OR in_transaction_id = ?)', [$biz, $tx['id'], $tx['id']]);
                    if ($pair !== null) {
                        $ids = [(int) $pair['o'], (int) $pair['i']];
                    }
                }
                $accounts = array_unique(array_map(fn (int $i): int => (int) $this->db->value('SELECT account_id FROM financial_transactions WHERE id = ?', [$i]), $ids));
                sort($accounts);
                foreach ($accounts as $acc) {
                    $this->db->value('SELECT id FROM financial_accounts WHERE id = ?' . $this->db->forUpdate(), [$acc]);
                }
                foreach ($ids as $i) {
                    $n = $this->db->execute('UPDATE financial_transactions SET voided_at = ?, voided_by = ?, void_reason = ? WHERE id = ? AND business_id = ? AND voided_at IS NULL', [$nowUtc, $actor['id'], $reason, $i, $biz]);
                    if ($n !== 1) {
                        throw new InsufficientFunds('El movimiento ya estaba anulado.');
                    }
                }
                foreach ($accounts as $acc) { // anular una entrada puede dejar la cuenta en negativo
                    if ($this->balance($biz, $acc)->isNegative()) {
                        throw new InsufficientFunds('No se puede anular: la cuenta quedaría con saldo negativo.');
                    }
                }
                $this->log($actor, 'finance.void', 'financial_transactions', (int) $tx['id'], ['voided' => false], ['voided' => true, 'reason' => $reason, 'transactions' => $ids]);
            });
        } catch (InsufficientFunds $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 422];
        }

        return ['ok' => true, 'error' => null, 'status' => 200];
    }

    /**
     * Inserta un asiento verificando saldo suficiente en salidas. Debe llamarse dentro de una transacción
     * con la cuenta bloqueada. Lo reutilizarán cobros, pagos y gastos de las etapas siguientes.
     *
     * @param array<string,mixed> $actor
     */
    public function post(int $biz, int $accountId, string $direction, Decimal $amount, string $occurredAt, string $source, string $description, ?string $reference, array $actor): int
    {
        if ($direction === 'out' && $this->balance($biz, $accountId)->compare($amount) < 0) {
            $name = (string) $this->db->value('SELECT name FROM financial_accounts WHERE id = ?', [$accountId]);
            throw new InsufficientFunds("Saldo insuficiente en «{$name}» (disponible " . $this->balance($biz, $accountId)->format() . ').');
        }
        $id = $this->db->insert(
            'INSERT INTO financial_transactions (business_id, account_id, direction, amount, occurred_at, source_type, description, reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$biz, $accountId, $direction, $amount->toString(), $occurredAt, $source, mb_substr($description, 0, 255), $reference, $actor['id']]
        );
        $this->log($actor, 'finance.' . $source, 'financial_transactions', $id, null, ['account_id' => $accountId, 'direction' => $direction, 'amount' => $amount->toString()]);

        return $id;
    }

    /**
     * @param array{account_id?:int,direction?:string,source?:string,from?:string,to?:string} $f  from/to en UTC (to exclusivo)
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function ledger(int $biz, array $f, int $limit, int $offset): array
    {
        $where = ['t.business_id = ?'];
        $params = [$biz];
        if (!empty($f['account_id'])) {
            $where[] = 't.account_id = ?';
            $params[] = (int) $f['account_id'];
        }
        if (in_array($f['direction'] ?? '', ['in', 'out'], true)) {
            $where[] = 't.direction = ?';
            $params[] = $f['direction'];
        }
        if (!empty($f['source']) && isset(self::SOURCES[$f['source']])) {
            $where[] = 't.source_type = ?';
            $params[] = $f['source'];
        }
        if (!empty($f['from'])) {
            $where[] = 't.occurred_at >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 't.occurred_at < ?';
            $params[] = $f['to'];
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM financial_transactions t WHERE {$w}", $params);
        $rows = $this->db->select(
            "SELECT t.*, a.name AS account_name FROM financial_transactions t JOIN financial_accounts a ON a.id = t.account_id WHERE {$w} ORDER BY t.occurred_at DESC, t.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Conciliación: devuelve una lista de inconsistencias (vacía = todo cuadra).
     *
     * @return list<string>
     */
    public function reconcile(int $biz): array
    {
        $issues = [];
        $count = fn (string $sql): int => (int) $this->db->value($sql, [$biz]);

        if (($n = $count("SELECT COUNT(*) FROM financial_transactions t WHERE t.business_id = ? AND t.source_type = 'capital' AND NOT EXISTS (SELECT 1 FROM capital_movements c WHERE c.financial_transaction_id = t.id)")) > 0) {
            $issues[] = "{$n} asiento(s) de capital sin movimiento de capital.";
        }
        if (($n = $count("SELECT COUNT(*) FROM financial_transactions t WHERE t.business_id = ? AND t.source_type = 'transfer' AND NOT EXISTS (SELECT 1 FROM account_transfers x WHERE x.out_transaction_id = t.id OR x.in_transaction_id = t.id)")) > 0) {
            $issues[] = "{$n} asiento(s) de transferencia sin transferencia.";
        }
        if (($n = $count("SELECT COUNT(*) FROM capital_movements c JOIN financial_transactions t ON t.id = c.financial_transaction_id
            WHERE c.business_id = ? AND (t.source_type <> 'capital' OR (c.kind = 'contribution' AND t.direction <> 'in') OR (c.kind = 'withdrawal' AND t.direction <> 'out'))")) > 0) {
            $issues[] = "{$n} movimiento(s) de capital con asiento incoherente.";
        }
        if (($n = $count("SELECT COUNT(*) FROM account_transfers x JOIN financial_transactions o ON o.id = x.out_transaction_id JOIN financial_transactions i ON i.id = x.in_transaction_id
            WHERE x.business_id = ? AND (o.direction <> 'out' OR i.direction <> 'in' OR o.amount <> i.amount OR o.account_id = i.account_id OR (o.voided_at IS NULL) <> (i.voided_at IS NULL))")) > 0) {
            $issues[] = "{$n} transferencia(s) con patas que no cuadran.";
        }
        if (($n = $count("SELECT COUNT(*) FROM financial_transactions t WHERE t.business_id = ? AND t.source_type = 'purchase_payment' AND NOT EXISTS (SELECT 1 FROM purchase_payments p WHERE p.financial_transaction_id = t.id)")) > 0) {
            $issues[] = "{$n} asiento(s) de pago a proveedor sin pago registrado.";
        }
        foreach ($this->accounts($biz) as $a) {
            if (Decimal::from($a['balance'], 2)->isNegative()) {
                $issues[] = 'La cuenta «' . $a['name'] . '» tiene saldo negativo.';
            }
        }

        return $issues;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed>|null $old @param array<string,mixed>|null $new */
    private function log(array $actor, string $action, string $entity, int $id, ?array $old, ?array $new): void
    {
        $this->audit->log((int) $actor['business_id'], (int) $actor['id'], $action, $entity, $id, $old, $new, $actor['ip'] ?? null, $actor['ua'] ?? null);
    }
}
