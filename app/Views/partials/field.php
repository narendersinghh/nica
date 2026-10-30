<?php
/** Labelled form field with error message and old input. */
$type ??= 'text';
$required ??= false;
$error = $errors[$name] ?? null;
$id = 'f-' . $name;
$value = old($name, $value ?? '');
$attrs = ' id="' . e($id) . '" name="' . e($name) . '"'
    . (!empty($placeholder) ? ' placeholder="' . e($placeholder) . '"' : '')
    . ($required ? ' required' : '')
    . (!empty($autocomplete) ? ' autocomplete="' . e($autocomplete) . '"' : '')
    . ($error ? ' aria-invalid="true" aria-describedby="' . e($id) . '-error"' : '');
?>
<div class="field<?= $error ? ' field--error' : '' ?>">
    <label for="<?= e($id) ?>"><?= e($label) ?><?= $required ? ' <span class="field__req" aria-hidden="true">*</span>' : '' ?></label>
    <?php if ($type === 'textarea'): ?>
        <textarea<?= $attrs ?> rows="<?= (int) ($rows ?? 5) ?>"><?= e($value) ?></textarea>
    <?php else: ?>
        <input type="<?= e($type) ?>"<?= $attrs ?> value="<?= e($value) ?>">
    <?php endif ?>
    <?php if ($error): ?><p class="field__error" id="<?= e($id) ?>-error"><?= e($error) ?></p><?php endif ?>
</div>
