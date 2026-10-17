<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use Illuminate\Http\Request;
use App\Http\Requests\Discount\AddDiscountRequest;
use App\Http\Requests\Discount\UpdateDiscountRequest;
use App\Http\Requests\Discount\DeleteDiscountRequest;

class DiscountsController extends Controller
{
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $discounts = Discount::paginate($perPage);
            return \Pagination($discounts);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddDiscountRequest $request)
    {
        try {
            if ($request->is_percentage && $request->is_fixed && $request->percent > $request->fixed_amount) {
                return \Failed('Percentage cannot exceed fixed amount');
            }

            $discount = Discount::create([
                'name' => $request->name,
                'is_percentage' => $request->is_percentage,
                'percent' => $request->percent ?? 0,
                'is_fixed' => $request->is_fixed,
                'fixed_amount' => $request->fixed_amount ?? 0,
                'is_active' => $request->is_active ?? 1,
            ]);

            return \SuccessData('Discount added successfully', $discount);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdateDiscountRequest $request)
    {
        try {
            $discount = Discount::find($request->id);

            $name = $request->name ?? $discount->name;
            $is_percentage = $request->is_percentage ?? $discount->is_percentage;
            $percent = $request->percent ?? $discount->percent;
            $is_fixed = $request->is_fixed ?? $discount->is_fixed;
            $fixed_amount = $request->fixed_amount ?? $discount->fixed_amount;
            $is_active = $request->is_active ?? $discount->is_active;

            if ($is_percentage && $is_fixed && $percent > $fixed_amount) {
                return \Failed('Percentage cannot exceed fixed amount');
            }

            $discount->update([
                'name' => $name,
                'is_percentage' => $is_percentage,
                'percent' => $percent,
                'is_fixed' => $is_fixed,
                'fixed_amount' => $fixed_amount,
                'is_active' => $is_active,
            ]);

            return \SuccessData('Discount updated successfully', $discount);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeleteDiscountRequest $request)
    {
        try {
            $discount = Discount::find($request->id);

            if ($discount->users()->exists()) {
                return \Failed('Discount linked to users, can\'t delete.');
            }

            if ($discount->guest_classification()->exists()) {
                return \Failed('Discount linked to guest classification, can\'t delete.');
            }

            $discount->delete();

            return \Success('Discount deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
