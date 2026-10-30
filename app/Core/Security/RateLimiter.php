<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Database;

/** Fixed-window counters stored in MySQL (works on shared hosting, no Redis needed). */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /** Record a hit and return the hit count in the current window. */
    public function hit(string $key, int $decaySeconds): int
    {
        $key = $this->key($key);
        $now = time();
        // Column assignments run left to right, so "hits" still sees the old reset_at.
        $this->db->query(
            'INSERT INTO rate_limits (k, hits, reset_at) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE hits = IF(reset_at <= ?, 1, hits + 1), reset_at = IF(reset_at <= ?, ?, reset_at)',
            [$key, $now + $decaySeconds, $now, $now, $now + $decaySeconds],
        );
        return (int) $this->db->scalar('SELECT hits FROM rate_limits WHERE k = ?', [$key]);
    }

    public function tooManyAttempts(string $key, int $max): bool
    {
        $row = $this->db->first('SELECT hits, reset_at FROM rate_limits WHERE k = ?', [$this->key($key)]);
        return $row !== null && (int) $row['reset_at'] > time() && (int) $row['hits'] >= $max;
    }

    public function availableIn(string $key): int
    {
        $reset = (int) $this->db->scalar('SELECT reset_at FROM rate_limits WHERE k = ?', [$this->key($key)]);
        return max(0, $reset - time());
    }

    public function clear(string $key): void
    {
        $this->db->delete('rate_limits', ['k' => $this->key($key)]);
    }

    public function prune(): int
    {
        return $this->db->query('DELETE FROM rate_limits WHERE reset_at < ?', [time()])->rowCount();
    }

    private function key(string $key): string
    {
        return strlen($key) > 120 ? substr($key, 0, 56) . ':' . hash('sha256', $key) : $key;
    }
}
