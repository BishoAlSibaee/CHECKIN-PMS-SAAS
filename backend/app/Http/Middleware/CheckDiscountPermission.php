<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Discount;
use Symfony\Component\HttpFoundation\Response;

class CheckDiscountPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $discountValue = $request->discount ?? 0;
        if ($discountValue > 0) {
            $userDiscount = $user->discount;
            if (!$userDiscount || !$userDiscount->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have active discount permission'
                ], 403);
            }

            $hasPermission = false;
            if ($userDiscount->is_percentage) {
                $hasPermission = $userDiscount->percent >= $discountValue;
            } else {
                $hasPermission = $userDiscount->fixed_amount >= $discountValue;
            }

            if (!$hasPermission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Discount amount exceeds your permission (max ' . ($userDiscount->is_percentage ? $userDiscount->percent . '%' : $userDiscount->fixed_amount)
                ], 403);
            }
        }

        return $next($request);
    }
}

