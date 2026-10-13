<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Billing\CostMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Админы Өртөг хуудасны өгөгдөл: үнийн жагсаалт, бодит өртөг, алдагдал.
class CostController extends Controller
{
    /**
     * Үнийн жагсаалт (нэгж өртгөөс гаргасан credit үнэ), ажлуудын бодит өртөг,
     * мөн хаана мөнгө алдсан. `days` нь бодит тоог сүүлийн ажлуудаар хязгаарлана.
     */
    public function __invoke(Request $request, CostMeter $meter): JsonResponse
    {
        $days = $request->integer('days') ?: null;

        return response()->json([
            'credit_mnt' => config('pricing.credit_mnt'),
            'usd_mnt' => config('pricing.usd_mnt'),
            'margin' => config('pricing.margin'),
            'costs' => config('pricing.costs'),
            'summary' => $meter->summary($days),
            'price_book' => $meter->priceBook(),
            'actuals' => $meter->actuals($days),
            'losses' => $meter->losses($days),
            'recent' => $meter->recent($days),
        ]);
    }
}
