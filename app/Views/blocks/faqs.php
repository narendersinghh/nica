<?php
/**
 * FAQ groups from the faqs table. The first group sits beside an image with a
 * phone card; following groups are centered below.
 * @var App\Core\Template $this
 */
$groups = [];
foreach (app()->db()->select('SELECT group_name, question, answer FROM faqs WHERE is_published = 1 ORDER BY sort_order, id') as $row) {
    $groups[$row['group_name']][] = $row;
}
$first = array_key_first($groups);
$faqSchema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
?>
<?php foreach ($groups as $name => $items): ?>
    <?php foreach ($items as $item) {
        $faqSchema['mainEntity'][] = ['@type' => 'Question', 'name' => $item['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($item['answer'])]];
    } ?>
    <?php if ($name === $first): ?>
    <section class="block-faqs section-pad section--soft">
        <div class="container block-faqs__top">
            <div class="block-faqs__list">
                <h2><?= e($name) ?></h2>
                <?php if (!empty($b['intro'])): ?><p><?= e($b['intro']) ?></p><?php endif ?>
                <?= $this->insert('partials/accordion', ['items' => $items, 'openFirst' => true]) ?>
            </div>
            <div class="block-faqs__aside">
                <?= image($b['image'] ?? null, ['alt' => '', 'max' => 600, 'sizes' => '(max-width: 767px) 90vw, 580px', 'class' => 'block-faqs__image']) ?>
                <?php if (!empty($b['phone'])): ?>
                    <a class="block-faqs__phone" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $b['phone']['number'])) ?>">
                        <?= image($b['phone']['icon'] ?? null, ['alt' => '', 'max' => 52, 'sizes' => '52px']) ?>
                        <span><strong><?= e($b['phone']['title']) ?></strong><span><?= e($b['phone']['number']) ?></span></span>
                    </a>
                <?php endif ?>
            </div>
        </div>
    </section>
    <?php else: ?>
    <section class="block-faqs block-faqs--more section-pad">
        <div class="container container--faq">
            <h2><?= e($name) ?></h2>
            <?= $this->insert('partials/accordion', ['items' => $items, 'openFirst' => true]) ?>
        </div>
    </section>
    <?php endif ?>
<?php endforeach ?>
<script type="application/ld+json" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
