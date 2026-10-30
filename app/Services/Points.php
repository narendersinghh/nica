<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Loyalty points: append-only ledger + users.points_balance, never below zero. */
final class Points
{
    public function __construct(private Database $db)
    {
    }

    public static function enabled(): bool
    {
        return (bool) Settings::get('points.enabled', true);
    }

    public static function rate(): int
    {
        return max(1, (int) Settings::get('points.points_per_dollar_redeemed', 100));
    }

    /** Cents a number of points is worth at checkout. */
    public static function value(int $points): int
    {
        return intdiv($points * 100, self::rate());
    }

    public function balance(int $userId): int
    {
        return (int) $this->db->scalar('SELECT points_balance FROM users WHERE id = ?', [$userId]);
    }

    /**
     * Add (or with a negative number, spend) points. Returns false if it would go below zero.
     * Must run inside the caller's transaction when combined with other writes.
     */
    public function add(int $userId, int $points, string $reason, ?int $orderId = null, ?string $note = null): bool
    {
        if ($points === 0) {
            return true;
        }
        $updated = $this->db->query(
            'UPDATE users SET points_balance = points_balance + ? WHERE id = ? AND points_balance + ? >= 0',
            [$points, $userId, $points],
        )->rowCount();
        if ($updated === 0) {
            return false;
        }
        $this->db->insert('points_ledger', ['user_id' => $userId, 'points' => $points, 'reason' => $reason, 'order_id' => $orderId, 'note' => $note]);
        return true;
    }

    public function history(int $userId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT pl.*, o.number AS order_number FROM points_ledger pl LEFT JOIN orders o ON o.id = pl.order_id WHERE pl.user_id = ? ORDER BY pl.id DESC LIMIT ' . $limit,
            [$userId],
        );
    }
}
