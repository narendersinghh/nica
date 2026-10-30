<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\CustomersController;
$this->layout('layouts/panel');
?>
<div class="toolbar">
    <nav class="chips" aria-label="Filter by role">
        <a href="<?= e(link_to('/admin/customers/')) ?>"<?= $role === null ? ' aria-current="page"' : '' ?>>Everyone</a>
        <?php foreach (CustomersController::ROLES as $k => $l): ?><a href="<?= e(link_to('/admin/customers/') . '?role=' . $k) ?>"<?= $role === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach ?>
    </nav>
    <form class="search" method="get" action="<?= e(link_to('/admin/customers/')) ?>" role="search"><?php if ($role): ?><input type="hidden" name="role" value="<?= e($role) ?>"><?php endif ?><?= picon('search') ?><label class="sr-only" for="cs">Search people</label><input id="cs" type="search" name="s" value="<?= e($search) ?>" placeholder="Name, email or username"></form>
</div>
<section class="card card--flush">
    <?php if (!$rows): ?><div class="empty"><?= picon('users') ?>Nobody matches.</div><?php else: ?>
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>Name</th><th>Roles</th><th class="right">Orders</th><th class="right">Spent</th><th class="right">Points</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $u): ?>
            <tr>
                <td class="prod-col"><a href="<?= e(link_to('/admin/customers/' . $u['id'] . '/')) ?>"><strong><?= e(trim($u['first_name'] . ' ' . $u['last_name']) ?: '—') ?></strong></a><div class="muted small"><?= e($u['email']) ?></div></td>
                <td data-label="Roles"><?php foreach (explode(',', (string) $u['roles']) as $r): if ($r === '') continue; ?><span class="pill<?= $r === 'admin' || $r === 'staff' ? ' pill--processing' : ($r === 'vendor' ? ' pill--shipped' : '') ?>"><?= e(CustomersController::ROLES[$r] ?? $r) ?></span> <?php endforeach ?><?= $u['status'] !== 'active' ? '<span class="pill pill--cancelled">' . e(ucfirst($u['status'])) . '</span>' : '' ?></td>
                <td data-label="Orders" class="right num"><?= (int) $u['orders'] ?></td>
                <td data-label="Spent" class="right num"><?= e(money((int) $u['spent'])) ?></td>
                <td data-label="Points" class="right num"><?= (int) $u['points_balance'] ?></td>
                <td data-label="Joined" class="nowrap small"><?= e(gmdate('M j, Y', strtotime($u['created_at']))) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?= $this->insert('vendor/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/customers/', 'query' => array_filter(['role' => $role, 's' => $search])]) ?>
    <?php endif ?>
</section>
