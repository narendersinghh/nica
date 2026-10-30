<div class="accordion" data-accordion>
    <?php foreach ($items as $i => $item): ?>
        <details class="accordion__item"<?= $openFirst && $i === 0 ? ' open' : '' ?>>
            <summary class="accordion__title"><span><?= e($item['question']) ?></span><?= icon('chevron-down', 'accordion__icon') ?></summary>
            <div class="accordion__body prose"><?= $item['answer'] /* sanitized at import */ ?></div>
        </details>
    <?php endforeach ?>
</div>
