<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;

final class AuditController extends AdminController
{
    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $action = trim((string) $request->query('action', ''));
        $where = '1 = 1';
        $params = [];
        if ($action !== '' && preg_match('/^[a-z_.]+$/', $action)) {
            $where .= ' AND a.action LIKE ?';
            $params[] = $action . '%';
        }
        if (($subject = (string) $request->query('subject', '')) !== '' && preg_match('/^([a-z_]+):(\d+)$/', $subject, $m)) {
            $where .= ' AND a.subject_type = ? AND a.subject_id = ?';
            array_push($params, $m[1], (int) $m[2]);
        }
        $page = $this->page($request);
        $total = (int) $db->scalar("SELECT COUNT(*) FROM audit_log a WHERE {$where}", $params);
        $rows = $db->select("SELECT a.*, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE {$where} ORDER BY a.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $params);
        $groups = array_column($db->select("SELECT DISTINCT SUBSTRING_INDEX(action, '.', 1) g FROM audit_log ORDER BY g"), 'g');
        return $this->panel($request, 'admin/audit', ['title' => 'Audit log', 'tab' => 'audit', 'rows' => $rows, 'action' => $action, 'groups' => $groups, 'page' => $page, 'pages' => max(1, (int) ceil($total / 50))]);
    }
}
