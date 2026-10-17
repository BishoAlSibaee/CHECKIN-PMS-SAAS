<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        try {
            $currentHotelId = $request->attributes->get('currentHotelId');
            $perPage = \returnPerPage();

            $companies = Company::query()
                ->when($currentHotelId, fn ($q) => $q->where('hotel_id', $currentHotelId))
                ->with('discount')
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return \Pagination($companies);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function show(int $id)
    {
        try {
            $company = Company::with('discount')->findOrFail($id);

            return \SuccessData('Company retrieved', $company);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_mobile' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'discount_id' => 'nullable|numeric|exists:discounts,id',
            'credit_limit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $currentHotelId = (int) $request->attributes->get('currentHotelId');

            $company = Company::create([
                'hotel_id' => $currentHotelId,
                'name' => $request->name,
                'contact_name' => $request->contact_name,
                'contact_mobile' => $request->contact_mobile,
                'contact_email' => $request->contact_email,
                'discount_id' => $request->discount_id,
                'credit_limit' => $request->credit_limit,
                'notes' => $request->notes,
                'status' => Company::STATUS_ACTIVE,
                'created_by' => auth()->id(),
            ]);

            return \SuccessData('Company created successfully', $company);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(Request $request, int $id)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_mobile' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'discount_id' => 'nullable|numeric|exists:discounts,id',
            'credit_limit' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:0,1',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $company = Company::findOrFail($id);

            $company->update($request->only([
                'name', 'contact_name', 'contact_mobile', 'contact_email',
                'discount_id', 'credit_limit', 'status', 'notes',
            ]));

            return \SuccessData('Company updated successfully', $company);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
