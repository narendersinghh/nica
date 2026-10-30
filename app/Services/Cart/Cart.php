<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Catalog\ProductIndexer;
use App\Services\Settings;

/**
 * The shopping cart. Guests are identified by a random cookie token (only its
 * SHA-256 is stored); logged-in customers by user id. Prices are never stored:
 * every read re-prices lines from the live catalog, so the cart can't be tampered with.
 */
final class Cart
{
    public const COOKIE = 'nica_cart';
    private const MAX_QTY = 99;
    private const MAX_LINES = 50;

    private ?int $id = null;
    private bool $loaded = false;
    private ?string $newToken = null;
    /** @var string[] messages about lines that changed since they were added */
    private array $notices = [];

    public function __construct(private Database $db, private Request $request, private ?int $userId)
    {
    }

    /* ------------------------------------------------------------- identity */

    public function id(bool $create = false): ?int
    {
        if (!$this->loaded) {
            $this->loaded = true;
            if ($this->userId) {
                $this->id = $this->db->scalar('SELECT id FROM carts WHERE user_id = ?', [$this->userId]);
            }
            $token = (string) $this->request->cookie(self::COOKIE, '');
            if ($this->id === null && preg_match('/^[a-f0-9]{64}$/', $token)) {
                $this->id = $this->db->scalar('SELECT id FROM carts WHERE token_hash = ?', [hash('sha256', $token)]);
            }
            $this->id = $this->id !== null ? (int) $this->id : null;
        }
        if ($this->id === null && $create) {
            if ($this->userId) {
                $this->id = $this->db->insert('carts', ['user_id' => $this->userId]);
            } else {
                $this->newToken = bin2hex(random_bytes(32));
                $this->id = $this->db->insert('carts', ['token_hash' => hash('sha256', $this->newToken)]);
            }
        }
        return $this->id;
    }

    /** Send the cart cookie when a guest cart was just created. */
    public function attach(Response $response): Response
    {
        if ($this->newToken !== null) {
            $response->cookie(self::COOKIE, $this->newToken, ['expires' => time() + 60 * 60 * 24 * 30, 'httponly' => true, 'samesite' => 'Lax']);
        }
        return $response;
    }

    /** After login: move guest cart lines into the user's cart. */
    public function mergeGuestCart(): void
    {
        $token = (string) $this->request->cookie(self::COOKIE, '');
        if (!$this->userId || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return;
        }
        $guest = $this->db->scalar('SELECT id FROM carts WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($guest === null) {
            return;
        }
        $this->loaded = false;
        $target = $this->id(true);
        $this->db->transaction(function () use ($guest, $target) {
            $this->db->query(
                'INSERT INTO cart_items (cart_id, variant_id, quantity) SELECT ?, variant_id, quantity FROM cart_items WHERE cart_id = ?
                 ON DUPLICATE KEY UPDATE quantity = LEAST(?, cart_items.quantity + VALUES(quantity))',
                [$target, $guest, self::MAX_QTY],
            );
            $this->db->delete('carts', ['id' => (int) $guest]);
        });
    }

    /* ---------------------------------------------------------------- items */

    /** @return array{ok:bool,message:string} */
    public function add(int $variantId, int $quantity): array
    {
        $quantity = max(1, min(self::MAX_QTY, $quantity));
        $variant = $this->variantRow($variantId);
        if ($variant === null) {
            return ['ok' => false, 'message' => 'This product is not available.'];
        }
        if (!ProductIndexer::isPurchasable($variant)) {
            return ['ok' => false, 'message' => 'Sorry, this item is out of stock.'];
        }
        $cartId = $this->id(true);
        $current = (int) $this->db->scalar('SELECT quantity FROM cart_items WHERE cart_id = ? AND variant_id = ?', [$cartId, $variantId]);
        if ($current === 0 && (int) $this->db->scalar('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$cartId]) >= self::MAX_LINES) {
            return ['ok' => false, 'message' => 'Your cart is full.'];
        }
        $wanted = min(self::MAX_QTY, $current + $quantity);
        $available = $this->availableQty($variant);
        if ($wanted > $available) {
            $wanted = $available;
            if ($wanted <= $current) {
                return ['ok' => false, 'message' => 'You already have the available stock (' . $available . ') in your cart.'];
            }
        }
        $this->db->query('INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)', [$cartId, $variantId, $wanted]);
        $this->touch();
        return ['ok' => true, 'message' => '“' . $variant['product_name'] . '” has been added to your cart.'];
    }

    public function setQuantity(int $variantId, int $quantity): void
    {
        $cartId = $this->id();
        if ($cartId === null) {
            return;
        }
        if ($quantity <= 0) {
            $this->remove($variantId);
            return;
        }
        $variant = $this->variantRow($variantId);
        $quantity = min(self::MAX_QTY, $quantity, $variant ? $this->availableQty($variant) : 0);
        $this->db->query('UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND variant_id = ?', [max(1, $quantity), $cartId, $variantId]);
        $this->touch();
    }

    public function remove(int $variantId): void
    {
        if ($cartId = $this->id()) {
            $this->db->delete('cart_items', ['cart_id' => $cartId, 'variant_id' => $variantId]);
            $this->touch();
        }
    }

    public function clear(): void
    {
        if ($cartId = $this->id()) {
            $this->db->delete('cart_items', ['cart_id' => $cartId]);
            $this->db->update('carts', ['coupon_codes' => null, 'shipping_choices' => null, 'points_to_redeem' => 0], ['id' => $cartId]);
        }
    }

    /**
     * Current lines priced from the live catalog. Unavailable lines are dropped and
     * quantities clamped to stock, with a notice for the customer.
     */
    public function lines(): array
    {
        $cartId = $this->id();
        if ($cartId === null) {
            return [];
        }
        $rows = $this->db->select(
            "SELECT ci.variant_id, ci.quantity, pv.*, p.id AS product_id, p.name AS product_name, p.slug, p.type, p.status AS product_status,
                    p.featured_media_id, p.shipping_class, v.id AS vendor_id, v.store_name AS vendor_name, v.status AS vendor_status, v.vacation_mode,
                    v.commission_rate AS vendor_commission,
                    (SELECT GROUP_CONCAT(pc.category_id) FROM product_category pc WHERE pc.product_id = p.id) AS category_ids,
                    (SELECT GROUP_CONCAT(av.name ORDER BY a.sort_order SEPARATOR ' / ') FROM variant_values vv JOIN attribute_values av ON av.id = vv.attribute_value_id JOIN attributes a ON a.id = vv.attribute_id WHERE vv.variant_id = pv.id) AS variant_label
             FROM cart_items ci JOIN product_variants pv ON pv.id = ci.variant_id JOIN products p ON p.id = pv.product_id JOIN vendors v ON v.id = p.vendor_id
             WHERE ci.cart_id = ? ORDER BY ci.added_at",
            [$cartId],
        );
        $lines = [];
        foreach ($rows as $r) {
            $usable = $r['product_status'] === 'published' && $r['vendor_status'] === 'approved' && !(int) $r['vacation_mode'] && ProductIndexer::isPurchasable($r);
            if (!$usable) {
                $this->notices[] = '“' . $r['product_name'] . '” is no longer available and was removed from your cart.';
                $this->db->delete('cart_items', ['cart_id' => $cartId, 'variant_id' => (int) $r['variant_id']]);
                continue;
            }
            $qty = (int) $r['quantity'];
            $available = $this->availableQty($r);
            if ($qty > $available) {
                $qty = $available;
                $this->notices[] = 'Only ' . $available . ' of “' . $r['product_name'] . '” ' . ($available === 1 ? 'is' : 'are') . ' available; your cart was updated.';
                $this->db->query('UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND variant_id = ?', [$qty, $cartId, $r['variant_id']]);
            }
            $price = ProductIndexer::effectivePrice($r);
            $lines[] = [
                'variant_id' => (int) $r['variant_id'],
                'product_id' => (int) $r['product_id'],
                'vendor_id' => (int) $r['vendor_id'],
                'vendor_name' => $r['vendor_name'],
                'vendor_commission' => $r['vendor_commission'] !== null ? (float) $r['vendor_commission'] : null,
                'name' => $r['product_name'],
                'slug' => $r['slug'],
                'variant_label' => $r['variant_label'],
                'sku' => $r['sku'],
                'image_media_id' => $r['image_media_id'] ? (int) $r['image_media_id'] : ($r['featured_media_id'] ? (int) $r['featured_media_id'] : null),
                'quantity' => $qty,
                'max_quantity' => min(self::MAX_QTY, $available),
                'unit_cents' => $price,
                'regular_cents' => (int) $r['regular_price_cents'],
                'on_sale' => $price < (int) $r['regular_price_cents'],
                'category_ids' => array_map('intval', array_filter(explode(',', (string) $r['category_ids']))),
                'shipping_class' => $r['shipping_class'],
            ];
        }
        return $lines;
    }

    public function notices(): array
    {
        return $this->notices;
    }

    public function count(): int
    {
        $cartId = $this->id();
        return $cartId ? (int) $this->db->scalar('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE cart_id = ?', [$cartId]) : 0;
    }

    /* -------------------------------------------------------------- coupons */

    public function couponCodes(): array
    {
        $cartId = $this->id();
        $raw = $cartId ? $this->db->scalar('SELECT coupon_codes FROM carts WHERE id = ?', [$cartId]) : null;
        return json_decode((string) $raw, true) ?: [];
    }

    /** @return string|null error message */
    public function applyCoupon(string $code, ?string $email = null): ?string
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 64) {
            return 'Please enter a coupon code.';
        }
        $coupon = Coupons::find($this->db, $code);
        if ($coupon === null) {
            return 'Coupon “' . $code . '” does not exist!';
        }
        $codes = $this->couponCodes();
        if (in_array(strtolower($coupon['code']), array_map('strtolower', $codes), true)) {
            return 'Coupon code already applied!';
        }
        $error = Coupons::validate($this->db, $coupon, $this->lines(), $email ?? $this->userEmail(), $this->userId);
        if ($error !== null) {
            return $error;
        }
        // "Individual use" coupons cannot be combined with others.
        $others = array_filter(array_map(fn ($c) => Coupons::find($this->db, $c), $codes));
        if ((int) $coupon['individual_use'] === 1) {
            $codes = [];
        } elseif (array_filter($others, static fn ($c) => (int) $c['individual_use'] === 1)) {
            return 'Sorry, coupon “' . reset($codes) . '” has already been applied and cannot be used in conjunction with other coupons.';
        }
        $codes[] = $coupon['code'];
        $this->db->update('carts', ['coupon_codes' => json_encode(array_values($codes))], ['id' => $this->id(true)]);
        return null;
    }

    public function removeCoupon(string $code): void
    {
        $codes = array_values(array_filter($this->couponCodes(), static fn ($c) => strcasecmp($c, $code) !== 0));
        if ($cartId = $this->id()) {
            $this->db->update('carts', ['coupon_codes' => $codes ? json_encode($codes) : null], ['id' => $cartId]);
        }
    }

    /* ------------------------------------------------------------- shipping */

    public function shippingChoices(): array
    {
        $cartId = $this->id();
        $raw = $cartId ? $this->db->scalar('SELECT shipping_choices FROM carts WHERE id = ?', [$cartId]) : null;
        return array_map('strval', json_decode((string) $raw, true) ?: []);
    }

    public function chooseShipping(int $vendorId, string $method): void
    {
        if (!in_array($method, ['flat_rate', 'free_shipping'], true) || !($cartId = $this->id())) {
            return;
        }
        $choices = $this->shippingChoices();
        $choices[$vendorId] = $method;
        $this->db->update('carts', ['shipping_choices' => json_encode($choices)], ['id' => $cartId]);
    }

    /* --------------------------------------------------------------- points */

    public function pointsToRedeem(): int
    {
        $cartId = $this->id();
        return $cartId ? (int) $this->db->scalar('SELECT points_to_redeem FROM carts WHERE id = ?', [$cartId]) : 0;
    }

    public function setPointsToRedeem(int $points): void
    {
        if ($this->userId && ($cartId = $this->id())) {
            $this->db->update('carts', ['points_to_redeem' => max(0, $points)], ['id' => $cartId]);
        }
    }

    /* --------------------------------------------------------------- totals */

    /**
     * Full priced cart. Coupons that stopped being valid (cart changed, expired)
     * are removed with a notice.
     */
    public function summary(?string $email = null): array
    {
        $lines = $this->lines();
        $coupons = [];
        foreach ($this->couponCodes() as $code) {
            $coupon = Coupons::find($this->db, $code);
            $error = $coupon ? Coupons::validate($this->db, $coupon, $lines, $email ?? $this->userEmail(), $this->userId) : 'Coupon “' . $code . '” is no longer available.';
            if ($error !== null) {
                $this->removeCoupon($code);
                $this->notices[] = $error . ' It was removed from your cart.';
                continue;
            }
            $coupons[] = $coupon;
        }
        $rates = [];
        foreach ($lines as $l) {
            if ($l['vendor_commission'] !== null) {
                $rates[$l['vendor_id']] = $l['vendor_commission'];
            }
        }
        $choices = [];
        foreach ($this->shippingChoices() as $vid => $m) {
            $choices[(int) $vid] = $m;
        }
        $result = (new Pricing((array) Settings::get('commerce')))->calculate($lines, $coupons, $choices, $rates);

        // Loyalty points: store credit taken off the final total (platform-funded; vendor earnings unchanged).
        $result['totals']['points_used'] = 0;
        $result['totals']['points_discount_cents'] = 0;
        $result['points_balance'] = 0;
        if ($this->userId && \App\Services\Points::enabled()) {
            $balance = (new \App\Services\Points($this->db))->balance($this->userId);
            $result['points_balance'] = $balance;
            $wanted = min($this->pointsToRedeem(), $balance);
            $rate = \App\Services\Points::rate();
            // Whole cents only, and never more than the order total.
            $maxPoints = intdiv($result['totals']['total_cents'] * $rate, 100);
            $use = min($wanted, $maxPoints);
            $value = \App\Services\Points::value($use);
            if ($use > 0 && $value > 0) {
                $result['totals']['points_used'] = $use;
                $result['totals']['points_discount_cents'] = $value;
                $result['totals']['total_cents'] -= $value;
            }
        }
        $result['notices'] = $this->notices;
        return $result;
    }

    /* -------------------------------------------------------------- helpers */

    private function variantRow(int $variantId): ?array
    {
        return $this->db->first(
            "SELECT pv.*, p.name AS product_name FROM product_variants pv JOIN products p ON p.id = pv.product_id JOIN vendors v ON v.id = p.vendor_id
             WHERE pv.id = ? AND p.status = 'published' AND v.status = 'approved' AND v.vacation_mode = 0",
            [$variantId],
        );
    }

    private function availableQty(array $variant): int
    {
        if (!(int) $variant['manage_stock'] || $variant['stock_status'] === 'onbackorder') {
            return self::MAX_QTY;
        }
        return max(0, min(self::MAX_QTY, (int) $variant['stock_qty']));
    }

    private function userEmail(): ?string
    {
        return $this->userId ? (string) $this->db->scalar('SELECT email FROM users WHERE id = ?', [$this->userId]) : null;
    }

    private function touch(): void
    {
        if ($this->id) {
            $this->db->query('UPDATE carts SET updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$this->id]);
        }
    }
}
