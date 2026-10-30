<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vendor;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Vendor\VendorOrders;

final class OrderController extends VendorController
{
    public function index(Request $request): Response
    {
        $id = $this->vendorId($request);
        $orders = new VendorOrders($this->app->db());
        $status = $request->query('status');
        $status = is_string($status) && isset(VendorOrders::STATUS_LABELS[$status]) ? $status : null;
        $search = trim((string) $request->query('s', ''));
        return $this->panel($request, 'vendor/orders', [
            'title' => 'Orders',
            'tab' => 'orders',
            'list' => $orders->list($id, $status, mb_substr_safe($search, 100), $this->page($request)),
            'counts' => $orders->counts($id),
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function show(Request $request): Response
    {
        $vo = $this->order($request);
        return $this->panel($request, 'vendor/order', [
            'title' => 'Order #' . $vo['order']['number'],
            'tab' => 'orders',
            'back' => ['Orders', '/dashboard/orders/'],
            'vo' => $vo,
        ]);
    }

    public function slip(Request $request): Response
    {
        $vo = $this->order($request);
        $response = $this->view('vendor/packing-slip', ['vo' => $vo, 'vendor' => $this->vendor($request), 'invoice' => $request->query('type') === 'invoice']);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    public function ship(Request $request): Response
    {
        $data = $this->validate($request, ['carrier' => 'required|in:' . implode(',', array_keys(VendorOrders::CARRIERS)), 'tracking_number' => 'required|string|max:100|regex:/^[A-Za-z0-9 -]{4,100}$/']);
        try {
            (new VendorOrders($this->app->db()))->ship($this->vendorId($request), (int) $request->param('id'), $data['carrier'], $data['tracking_number'], $this->app->auth()->id());
            $this->notice('Marked as shipped. The customer has been emailed the tracking number.');
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        return $this->back('/dashboard/orders/' . (int) $request->param('id') . '/');
    }

    public function complete(Request $request): Response
    {
        try {
            (new VendorOrders($this->app->db()))->complete($this->vendorId($request), (int) $request->param('id'), $this->app->auth()->id());
            $this->notice('Order completed. Your earning is now available for payout.');
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        return $this->back('/dashboard/orders/' . (int) $request->param('id') . '/');
    }

    public function note(Request $request): Response
    {
        $data = $this->validate($request, ['note' => 'required|string|max:2000', 'to_customer' => 'nullable|boolean']);
        try {
            (new VendorOrders($this->app->db()))->addNote($this->vendorId($request), (int) $request->param('id'), $data['note'], (bool) ($data['to_customer'] ?? false), $this->app->auth()->id());
            $this->notice(!empty($data['to_customer']) ? 'Note added. The customer can see it on their order.' : 'Private note added.');
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        return $this->back('/dashboard/orders/' . (int) $request->param('id') . '/#notes');
    }

    private function order(Request $request): array
    {
        $vo = (new VendorOrders($this->app->db()))->find($this->vendorId($request), (int) $request->param('id'));
        if ($vo === null) {
            throw new HttpException(404);
        }
        return $vo;
    }
}
