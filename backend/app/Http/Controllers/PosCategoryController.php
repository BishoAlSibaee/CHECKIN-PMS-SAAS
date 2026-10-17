<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\PosCategory;
use Illuminate\Http\Request;

class PosCategoryController extends Controller
{
    /**
     * Revenue accounts for the category create/edit form's account picker —
     * a lightweight list, deliberately not the full accounting/chart-of-accounts
     * report endpoint (that one requires "view accounting reports", which
     * managers who hold "manage pos categories" don't necessarily have).
     */
    public function accounts(Request $request)
    {
        try {
            $accounts = ChartOfAccount::where('type', 'revenue')
                ->where('active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name_en', 'name_ar']);

            return \SuccessData('Revenue accounts retrieved', $accounts);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function index(Request $request)
    {
        try {
            $currentHotelId = $request->attributes->get('currentHotelId');

            $categories = PosCategory::query()
                ->when($currentHotelId, fn ($q) => $q->where(function ($q) use ($currentHotelId) {
                    $q->whereNull('hotel_id')->orWhere('hotel_id', $currentHotelId);
                }))
                ->with('account')
                ->orderBy('name_en')
                ->get();

            return \SuccessData('Categories retrieved', $categories);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'account_id' => 'required|numeric|exists:chart_of_accounts,id',
            'hotel_only' => 'nullable|boolean',
        ]);

        try {
            $currentHotelId = (int) $request->attributes->get('currentHotelId');

            $category = PosCategory::create([
                'hotel_id' => $request->boolean('hotel_only') ? $currentHotelId : null,
                'account_id' => $request->account_id,
                'name_en' => $request->name_en,
                'name_ar' => $request->name_ar,
                'is_active' => true,
            ]);

            return \SuccessData('Category created successfully', $category);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(Request $request, int $id)
    {
        $request->validate([
            'name_en' => 'sometimes|required|string|max:255',
            'name_ar' => 'sometimes|required|string|max:255',
            'account_id' => 'sometimes|required|numeric|exists:chart_of_accounts,id',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $category = PosCategory::findOrFail($id);
            $category->update($request->only(['name_en', 'name_ar', 'account_id', 'is_active']));

            return \SuccessData('Category updated successfully', $category);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
