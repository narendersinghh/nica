<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vendor;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Payments\StripeConnect;
use App\Services\Vendor\Earnings;
use App\Services\Vendor\VendorProducts;

final class EarningsController extends VendorController
{
    public function index(Request $request): Response
    {
        $vendor = $this->vendor($request);
        $earnings = new Earnings($this->app->db());
        return $this->panel($request, 'vendor/withdraw', [
            'title' => 'Earnings',
            'tab' => 'withdraw',
            'balance' => $earnings->summary((int) $vendor['id']),
            'threshold' => $earnings->threshold($vendor),
            'ledger' => $earnings->ledger((int) $vendor['id'], $this->page($request)),
            'requests' => $earnings->requests((int) $vendor['id']),
            'method' => Earnings::mask($vendor['payout_method'], $earnings->details($vendor)),
            'hasMethod' => $vendor['payout_method'] !== null && $earnings->details($vendor) !== null,
            'connected' => StripeConnect::isConnected($vendor),
        ]);
    }

    public function request(Request $request): Response
    {
        $data = $this->validate($request, ['amount' => 'required|string|max:12', 'note' => 'nullable|string|max:500']);
        $cents = VendorProducts::parseMoney($data['amount']);
        if ($cents === null) {
            $this->error('Enter an amount like 120.00.');
            return $this->back('/dashboard/withdraw/');
        }
        try {
            (new Earnings($this->app->db()))->request($this->vendorId($request), $cents, trim((string) ($data['note'] ?? '')));
            $this->notice('Payout request for ' . money($cents) . ' sent. We usually process requests within 3 business days.');
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        return $this->back('/dashboard/withdraw/');
    }

    public function cancel(Request $request): Response
    {
        if ((new Earnings($this->app->db()))->cancel($this->vendorId($request), (int) $request->param('id'))) {
            $this->notice('Payout request cancelled.');
        }
        return $this->back('/dashboard/withdraw/');
    }
}
