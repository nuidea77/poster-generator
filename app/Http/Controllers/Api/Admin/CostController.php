<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Billing\CostMeter;
use Illuminate\Http\JsonResponse;

class CostController extends Controller
{
    /**
     * Price book (credit prices derived from unit costs) and what finished jobs really cost.
     */
    public function __invoke(CostMeter $meter): JsonResponse
    {
        return response()->json([
            'credit_mnt' => config('pricing.credit_mnt'),
            'usd_mnt' => config('pricing.usd_mnt'),
            'margin' => config('pricing.margin'),
            'costs' => config('pricing.costs'),
            'price_book' => $meter->priceBook(),
            'actuals' => $meter->actuals(),
        ]);
    }
}
