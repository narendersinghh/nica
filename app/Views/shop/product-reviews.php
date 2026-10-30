<?php
/** Reviews list + form on the product page. */
$user = app()->auth()->user();
?>
<h2 class="tabs__heading">Reviews</h2>
<?php if ($reviews): ?>
    <ol class="review-list">
        <?php foreach ($reviews as $r): ?>
            <li class="review-list__item">
                <span class="review-card__avatar" aria-hidden="true"><?= e(strtoupper(mb_substr_safe($r['author_name'], 1))) ?></span>
                <div>
                    <p class="review-list__meta"><strong><?= e($r['author_name']) ?></strong><?php if ((int) $r['is_verified_buyer']): ?> <span class="review-list__verified"><?= icon('check') ?> Verified buyer</span><?php endif ?> · <time datetime="<?= e(substr($r['created_at'], 0, 10)) ?>"><?= e(date('F j, Y', strtotime($r['created_at']))) ?></time></p>
                    <p class="stars" aria-label="Rated <?= (int) $r['rating'] ?> out of 5"><?= str_repeat('★', (int) $r['rating']) ?><span class="stars__off"><?= str_repeat('★', 5 - (int) $r['rating']) ?></span></p>
                    <p><?= nl2br(e($r['body'])) ?></p>
                    <?php if ($r['vendor_reply']): ?><p class="review-list__reply"><strong><?= e($product['vendor_name']) ?>:</strong> <?= e($r['vendor_reply']) ?></p><?php endif ?>
                </div>
            </li>
        <?php endforeach ?>
    </ol>
<?php else: ?>
    <p>There are no reviews yet. Be the first to review “<?= e($product['name']) ?>”</p>
<?php endif ?>

<?php if ($reviewSent): ?>
    <div class="notice notice--success" role="status"><?= icon('check') ?> Thank you! Your review has been submitted and will appear once it is approved.</div>
<?php else: ?>
    <form class="form review-form" method="post" action="<?= e(link_to('/product/' . $product['slug'] . '/review')) ?>#reviews" novalidate>
        <?= csrf_field() ?>
        <fieldset class="rating-input<?= isset($errors['rating']) ? ' field--error' : '' ?>">
            <legend>Your rating <span class="field__req">*</span></legend>
            <?php $oldRating = (int) old('rating', '0'); for ($n = 5; $n >= 1; $n--): ?>
                <input type="radio" id="rating-<?= $n ?>" name="rating" value="<?= $n ?>"<?= $oldRating === $n ? ' checked' : '' ?>>
                <label for="rating-<?= $n ?>" title="<?= $n ?> star<?= $n > 1 ? 's' : '' ?>">★<span class="visually-hidden"><?= $n ?> stars</span></label>
            <?php endfor ?>
            <?php if (isset($errors['rating'])): ?><p class="field__error">Please choose a rating.</p><?php endif ?>
        </fieldset>
        <?= $this->insert('partials/field', ['name' => 'body', 'label' => 'Your review', 'type' => 'textarea', 'required' => true, 'errors' => $errors]) ?>
        <?php if ($user === null): ?>
            <div class="form__grid form__grid--2">
                <?= $this->insert('partials/field', ['name' => 'author_name', 'label' => 'Name', 'required' => true, 'autocomplete' => 'name', 'errors' => $errors]) ?>
                <?= $this->insert('partials/field', ['name' => 'author_email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'errors' => $errors]) ?>
            </div>
        <?php endif ?>
        <div class="form__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <button class="btn btn--dark" type="submit">Submit review</button>
    </form>
<?php endif ?>
