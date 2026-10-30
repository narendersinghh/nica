<?php $this->layout('account/layout') ?>
<p>Are you sure you want to log out?</p>
<form method="post" action="<?= e(link_to('/my-account/logout')) ?>"><?= csrf_field() ?><button class="btn btn--dark" type="submit">Confirm and log out</button></form>
