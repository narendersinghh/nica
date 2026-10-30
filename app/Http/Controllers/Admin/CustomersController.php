<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controllers\AuthController;
use App\Services\Audit;
use App\Services\Points;

final class CustomersController extends AdminController
{
    public const ROLES = ['customer' => 'Customer', 'vendor' => 'Vendor', 'stylist' => 'Stylist', 'staff' => 'Staff', 'admin' => 'Administrator'];

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $role = $request->query('role');
        $role = is_string($role) && isset(self::ROLES[$role]) ? $role : null;
        $search = trim((string) $request->query('s', ''));
        $page = $this->page($request);
        $where = '1 = 1';
        $params = [];
        if ($role) {
            $where .= ' AND EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = ?)';
            $params[] = $role;
        }
        if ($search !== '') {
            $where .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)';
            array_push($params, self::like($search), self::like($search), self::like($search), self::like($search));
        }
        $total = (int) $db->scalar("SELECT COUNT(*) FROM users u WHERE {$where}", $params);
        $rows = $db->select(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.points_balance, u.created_at, u.last_login_at, u.two_factor_enabled_at,
                    (SELECT GROUP_CONCAT(role ORDER BY role) FROM user_roles r WHERE r.user_id = u.id) AS roles,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status IN ('processing','on-hold','completed')) AS orders,
                    (SELECT COALESCE(SUM(total_cents - refunded_cents), 0) FROM orders o WHERE o.user_id = u.id AND o.status IN ('processing','on-hold','completed')) AS spent
             FROM users u WHERE {$where} ORDER BY u.created_at DESC LIMIT 25 OFFSET " . (($page - 1) * 25),
            $params
        );
        return $this->panel($request, 'admin/customers', ['title' => 'Customers', 'tab' => 'customers', 'rows' => $rows, 'role' => $role, 'search' => $search, 'page' => $page, 'pages' => max(1, (int) ceil($total / 25)), 'total' => $total]);
    }

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $db = $this->app->db();
        return $this->panel($request, 'admin/customer', [
            'title' => trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['email'],
            'tab' => 'customers',
            'back' => ['Customers', '/admin/customers/'],
            'u' => $user,
            'roles' => array_column($db->select('SELECT role FROM user_roles WHERE user_id = ?', [$user['id']]), 'role'),
            'orders' => $db->select('SELECT id, number, status, total_cents, created_at FROM orders WHERE user_id = ? OR (user_id IS NULL AND email = ?) ORDER BY created_at DESC LIMIT 20', [$user['id'], $user['email']]),
            'points' => (new Points($db))->history((int) $user['id'], 15),
            'store' => $db->first('SELECT id, store_name FROM vendors WHERE user_id = ?', [$user['id']]),
            'canRoles' => \App\Http\Middleware\AdminAccess::can('settings'),
            'canPoints' => \App\Http\Middleware\AdminAccess::can('finance') && (int) $user['id'] !== $this->app->auth()->id(),
            'isSelf' => (int) $user['id'] === $this->app->auth()->id(),
        ]);
    }

    public function roles(Request $request): Response
    {
        $this->authorize('settings');
        $user = $this->user($request);
        $db = $this->app->db();
        $wanted = array_values(array_intersect(array_keys(self::ROLES), (array) $request->post('roles', [])));
        $current = array_column($db->select('SELECT role FROM user_roles WHERE user_id = ?', [$user['id']]), 'role');
        if ((int) $user['id'] === $this->app->auth()->id() && in_array('admin', $current, true) && !in_array('admin', $wanted, true)) {
            $this->error('You cannot remove your own administrator role.');
            return $this->back('/admin/customers/' . $user['id'] . '/');
        }
        $db->transaction(function () use ($db, $user, $wanted) {
            $db->delete('user_roles', ['user_id' => (int) $user['id']]);
            foreach ($wanted ?: ['customer'] as $role) {
                $db->insert('user_roles', ['user_id' => (int) $user['id'], 'role' => $role]);
            }
        });
        Audit::log('user.roles_changed', 'user', (int) $user['id'], ['from' => $current, 'to' => $wanted ?: ['customer']]);
        $this->notice('Roles updated.');
        return $this->back('/admin/customers/' . $user['id'] . '/');
    }

    public function status(Request $request): Response
    {
        $user = $this->user($request);
        $to = $request->param('action') === 'suspend' ? 'suspended' : 'active';
        if ((int) $user['id'] === $this->app->auth()->id()) {
            $this->error('You cannot suspend your own account.');
            return $this->back('/admin/customers/' . $user['id'] . '/');
        }
        if ($to === 'suspended' && $this->app->db()->scalar("SELECT 1 FROM user_roles WHERE user_id = ? AND role = 'admin'", [$user['id']]) && !$this->app->auth()->hasRole('admin')) {
            throw new HttpException(403);
        }
        $this->app->db()->update('users', ['status' => $to], ['id' => (int) $user['id']]);
        if ($to === 'suspended') {
            $this->app->auth()->forgetRememberTokens((int) $user['id']); // sessions fail the status check on the next request
        }
        Audit::log('user.' . ($to === 'suspended' ? 'suspended' : 'reactivated'), 'user', (int) $user['id']);
        $this->notice($to === 'suspended' ? 'Account suspended and signed out everywhere.' : 'Account reactivated.');
        return $this->back('/admin/customers/' . $user['id'] . '/');
    }

    public function sendReset(Request $request): Response
    {
        $user = $this->user($request);
        (new AuthController($this->app))->sendPasswordLink($user, 'reset');
        Audit::log('user.password_reset_sent', 'user', (int) $user['id']);
        $this->notice('Password reset link sent to ' . $user['email'] . '.');
        return $this->back('/admin/customers/' . $user['id'] . '/');
    }

    /** Points are money (100 = $1), so only finance admins may add them, and never to themselves. */
    public function points(Request $request): Response
    {
        $this->authorize('finance');
        $user = $this->user($request);
        if ((int) $user['id'] === $this->app->auth()->id()) {
            $this->error('You cannot change your own points.');
            return $this->back('/admin/customers/' . $user['id'] . '/');
        }
        $data = $this->validate($request, ['points' => 'required|integer|min:-100000|max:100000', 'note' => 'required|string|max:200']);
        if ((int) $data['points'] === 0 || !(new Points($this->app->db()))->add((int) $user['id'], (int) $data['points'], 'admin', null, trim($data['note']))) {
            $this->error('Points could not be changed (the balance cannot go below zero).');
        } else {
            Audit::log('user.points_adjusted', 'user', (int) $user['id'], ['points' => (int) $data['points'], 'note' => $data['note']]);
            $this->notice('Points updated.');
        }
        return $this->back('/admin/customers/' . $user['id'] . '/');
    }

    private function user(Request $request): array
    {
        return $this->app->db()->first('SELECT * FROM users WHERE id = ?', [(int) $request->param('id')]) ?? throw new HttpException(404);
    }
}
