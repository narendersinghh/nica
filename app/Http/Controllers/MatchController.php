<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Find Match results. Query: ?cat=0,3&sub=3.1&tex=2&color=0&length=1 where the
 * numbers are option positions in the quiz config (sub = "catIndex.childIndex").
 * Products must match every chosen filter; when nothing does, the closest
 * matches are returned instead ("soft"), ranked by how many filters they hit.
 */
final class MatchController extends Controller
{
    private const LIMIT = 12;

    public function match(Request $request): Response
    {
        $quiz = json_decode((string) $this->app->db()->scalar("SELECT value FROM settings WHERE name = 'quiz'"), true) ?: ['steps' => []];
        $steps = array_column($quiz['steps'], null, 'key');
        $pick = static function (string $raw, int $max): array {
            $out = [];
            foreach (explode(',', $raw) as $part) {
                if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $part)) {
                    $out[] = $part;
                }
            }
            return array_slice(array_unique($out), 0, $max);
        };

        // Category set: chosen sub-collections replace their parent; other chosen categories include descendants.
        $catalog = $this->app->catalog();
        $categoryIds = [];
        $catChoices = $pick($request->string('cat'), 20);
        $subChoices = $pick($request->string('sub'), 40);
        $parentsWithSub = [];
        foreach ($subChoices as $sub) {
            [$ci, $si] = array_map('intval', explode('.', $sub) + [1 => -1]);
            $child = $steps['cat']['options'][$ci]['children'][$si] ?? null;
            if ($child && in_array((string) $ci, $catChoices, true)) {
                $parentsWithSub[$ci] = true;
                foreach ($child['ids'] as $id) {
                    $categoryIds = array_merge($categoryIds, $catalog->categoryTree((int) $id));
                }
            }
        }
        foreach ($catChoices as $ci) {
            if (isset($parentsWithSub[(int) $ci])) {
                continue;
            }
            foreach ($steps['cat']['options'][(int) $ci]['ids'] ?? [] as $id) {
                $categoryIds = array_merge($categoryIds, $catalog->categoryTree((int) $id));
            }
        }

        // Filter groups: tags (texture) and attribute values (colour, length).
        $groups = [];
        foreach (['tex' => 'tag', 'color' => 'value', 'length' => 'value'] as $key => $kind) {
            $ids = [];
            foreach ($pick($request->string($key), 20) as $oi) {
                $ids = array_merge($ids, $steps[$key]['options'][(int) $oi]['ids'] ?? []);
            }
            if ($ids) {
                $groups[] = [$kind, array_map('intval', $ids)];
            }
        }

        $rows = $this->candidates(array_values(array_unique($categoryIds)));
        $selected = count($groups);
        $score = static function (array $p) use ($groups): int {
            $hits = 0;
            foreach ($groups as [$kind, $ids]) {
                if (array_intersect($ids, $kind === 'tag' ? $p['tags'] : $p['values'])) {
                    $hits++;
                }
            }
            return $hits;
        };
        $grade = static function (bool $strict) use ($rows, $score, $selected, $categoryIds): array {
            $out = [];
            foreach ($rows as $i => $p) {
                $h = $score($p);
                if ($strict ? $h < $selected : (!$categoryIds && $selected && !$h)) {
                    continue;
                }
                $out[] = ['p' => $p, 'h' => $h, 'i' => $i];
            }
            usort($out, static fn ($a, $b) => [$b['h'], $a['i']] <=> [$a['h'], $b['i']]);
            return $out;
        };

        $results = $grade(true);
        $soft = false;
        if (!$results) {
            $results = $grade(false);
            $soft = $results !== [];
        }
        $results = array_slice($results, 0, self::LIMIT);
        $this->app->images()->prime(array_map(static fn ($r) => $r['p']['featured_media_id'], $results));

        $view = $this->app->view();
        $items = array_map(fn ($r) => [
            'name' => $r['p']['name'],
            'url' => link_to('/product/' . $r['p']['slug'] . '/'),
            'vendor' => $r['p']['vendor_name'],
            'image' => $r['p']['featured_media_id'] ? image_url((int) $r['p']['featured_media_id'], 'woocommerce_thumbnail') ?: image_url((int) $r['p']['featured_media_id']) : null,
            'price' => trim($view->render('partials/price', ['product' => $r['p']])),
            'match' => min(99, $selected ? max(45, (int) round($r['h'] / $selected * 100)) : 95),
        ], $results);

        return Response::json(['items' => $items, 'soft' => $soft])
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** Visible products (optionally within categories) with their tag and value ids. */
    private function candidates(array $categoryIds): array
    {
        $where = "p.status = 'published' AND v.status = 'approved' AND v.vacation_mode = 0";
        if ($categoryIds) {
            $where .= ' AND EXISTS (SELECT 1 FROM product_category pc WHERE pc.product_id = p.id AND pc.category_id IN (' . implode(',', array_map('intval', $categoryIds)) . '))';
        }
        $rows = $this->app->db()->select(
            "SELECT p.id, p.slug, p.name, p.featured_media_id, p.price_min_cents, p.price_max_cents, p.regular_min_cents, p.on_sale, v.store_name AS vendor_name,
                    (SELECT GROUP_CONCAT(pt.tag_id) FROM product_tag pt WHERE pt.product_id = p.id) AS tag_ids,
                    (SELECT GROUP_CONCAT(pav.attribute_value_id) FROM product_attribute_values pav WHERE pav.product_id = p.id) AS value_ids
             FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE {$where} ORDER BY p.published_at DESC, p.id DESC",
        );
        return array_map(static function (array $r): array {
            $r['tags'] = array_map('intval', array_filter(explode(',', (string) $r['tag_ids'])));
            $r['values'] = array_map('intval', array_filter(explode(',', (string) $r['value_ids'])));
            return $r;
        }, $rows);
    }
}
