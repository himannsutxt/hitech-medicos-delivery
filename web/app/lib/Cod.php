<?php
declare(strict_types=1);
namespace HMD;

final class Cod
{
    public static function cashInHand(int $riderId): float
    {
        return Ledger::cashInHand(Db::all('SELECT type, mode, amount FROM hm_cod_txn WHERE rider_id=?', [$riderId]));
    }

    public static function shortPending(int $riderId): float
    {
        return Ledger::shortPending(Db::all('SELECT type, mode, amount FROM hm_cod_txn WHERE rider_id=?', [$riderId]));
    }

    /** Accounts confirms a deposit. Returns [ok, message]. */
    public static function settle(int $settlementId, float $received, string $remark, int $userId): array
    {
        $s = Db::one("SELECT * FROM hm_settlement WHERE settlement_id=? AND status='requested'", [$settlementId]);
        if (!$s) {
            return [false, 'This settlement is already closed or does not exist.'];
        }
        $expected = self::cashInHand((int)$s['rider_id']);
        $r = Ledger::settle($expected, $received);
        if ($r['status'] !== 'settled' && mb_strlen(trim($remark)) < 5) {
            return [false, 'Amount does not match. Write a remark explaining the difference.'];
        }
        return Db::tx(function () use ($s, $expected, $received, $r, $remark, $userId) {
            $now = Fmt::nowUtc();
            $dep = min($received, $expected);
            if ($dep > 0) {
                Db::insert('hm_cod_txn', ['rider_id' => $s['rider_id'], 'settlement_id' => $s['settlement_id'], 'type' => 'deposit', 'mode' => 'cash', 'amount' => $dep, 'created_by' => $userId, 'created_at' => $now]);
            }
            if ($r['status'] === 'short') {
                Db::insert('hm_cod_txn', ['rider_id' => $s['rider_id'], 'settlement_id' => $s['settlement_id'], 'type' => 'short', 'mode' => 'cash', 'amount' => abs($r['difference']), 'reference' => mb_substr($remark, 0, 100), 'created_by' => $userId, 'created_at' => $now]);
                $name = (string)Db::val('SELECT name FROM hm_rider WHERE rider_id=?', [$s['rider_id']]);
                Exceptions::raise('cod_short', 'high', (int)$s['rider_id'], null, "COD short " . Fmt::inr(abs($r['difference'])) . " for $name. Remark: $remark");
            }
            Db::update('hm_settlement', ['expected' => $expected, 'received' => $received, 'difference' => $r['difference'], 'remark' => $remark ?: null,
                'status' => $r['status'] === 'excess' ? 'settled' : $r['status'], 'accepted_by' => $userId, 'accepted_at' => $now], 'settlement_id=?', [$s['settlement_id']]);
            Audit::log('cod.settle', 'settlement', $s['settlement_id'], ['expected' => $expected], ['received' => $received, 'status' => $r['status'], 'remark' => $remark]);
            $msg = match ($r['status']) {
                'settled' => 'Settled. ' . Fmt::inr($received) . ' received.',
                'short' => 'Recorded as short by ' . Fmt::inr(abs($r['difference'])) . '. It stays on the rider ledger.',
                default => 'Received ' . Fmt::inr($r['difference']) . ' more than expected. Noted in the remark.',
            };
            return [true, $msg];
        });
    }

    /** Rider pays back a shortage, or Super Admin writes it off. */
    public static function adjust(int $riderId, string $type, float $amount, string $note, int $userId): array
    {
        if (!in_array($type, ['recover', 'writeoff'], true) || $amount <= 0) {
            return [false, 'Invalid adjustment.'];
        }
        $pending = self::shortPending($riderId);
        if ($amount - $pending > 0.009) {
            return [false, 'Amount is more than the pending shortage of ' . Fmt::inr($pending) . '.'];
        }
        if (mb_strlen(trim($note)) < 5) {
            return [false, 'Write a reason or reference.'];
        }
        Db::insert('hm_cod_txn', ['rider_id' => $riderId, 'type' => $type, 'mode' => 'cash', 'amount' => round($amount, 2), 'reference' => mb_substr($note, 0, 100), 'created_by' => $userId, 'created_at' => Fmt::nowUtc()]);
        Audit::log('cod.' . $type, 'rider', $riderId, null, ['amount' => $amount, 'note' => $note]);
        return [true, ($type === 'recover' ? 'Recovery' : 'Write-off') . ' of ' . Fmt::inr($amount) . ' recorded.'];
    }
}
