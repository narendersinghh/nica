<ul class="social">
    <?php foreach ($social as $s): ?>
        <li><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($s['name']) ?>"><?= icon($s['icon']) ?></a></li>
    <?php endforeach ?>
</ul>
