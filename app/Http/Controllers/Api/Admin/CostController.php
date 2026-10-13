<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Billing\CostMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostController extends Controller
{
    /**
     * Price book (credit prices derived from unit costs), what jobs really cost,
     * and where money was lost. `days` limits real figures to recent jobs.
     */
    public function __invoke(Request $request, CostMeter $meter): JsonResponse
    {
        $days = $request->integer('days') ?: null;

        return response()->json([
            'credit_mnt' => config('pricing.credit_mnt'),
            'usd_mnt' => config('pricing.usd_mnt'),
            'margin' => config('pricing.margin'),
            'costs' => config('pricing.costs'),
            'price_book' => $meter->priceBook(),
            'actuals' => $meter->actuals($days),
            'losses' => $meter->losses($days),
            'recent' => $meter->recent($days),
        ]);
    }
}
