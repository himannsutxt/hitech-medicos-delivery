<?php
declare(strict_types=1);
namespace HMD;

/** COD ledger maths. UPI never sits in the rider's hand. */
final class Ledger
{
    public static function cashInHand(array $txns): float
    {
        $c = 0.0;
        foreach ($txns as $t) {
            $a = (float)$t['amount'];
            switch ($t['type']) {
                case 'collect':
                    if (($t['mode'] ?? '') === 'cash') {
                        $c += $a;
                    }
                    break;
                case 'deposit':
                case 'recover':
                case 'writeoff':
                    $c -= $a;
                    break;
            }
        }
        return round($c, 2);
    }

    public static function shortPending(array $txns): float
    {
        $s = 0.0;
        foreach ($txns as $t) {
            if ($t['type'] === 'short') {
                $s += (float)$t['amount'];
            } elseif (in_array($t['type'], ['recover', 'writeoff'], true)) {
                $s -= (float)$t['amount'];
            }
        }
        return round(max(0.0, $s), 2);
    }

    public static function settle(float $expected, float $received): array
    {
        $diff = round($received - $expected, 2);
        $status = abs($diff) < 0.005 ? 'settled' : ($diff < 0 ? 'short' : 'excess');
        return ['difference' => $diff, 'status' => $status];
    }
}
