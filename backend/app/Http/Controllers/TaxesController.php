<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use App\Http\Requests\Tax\AddTaxRequest;
use App\Http\Requests\Tax\UpdateTaxRequest;
use App\Http\Requests\Tax\DeleteTaxRequest;

class TaxesController extends Controller
{

    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $taxes = Tax::paginate($perPage);
            return \Pagination($taxes);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddTaxRequest $request)
    {
        try {
            $tax = Tax::create([
                'type' => $request->type,
                'value' => $request->value,
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'active' => $request->active ?? 1,
            ]);

            return \SuccessData('Tax added successfully', $tax);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdateTaxRequest $request)
    {
        try {
            $tax = Tax::find($request->id);

            $tax->update([
                'type' => $request->type ?? $tax->type,
                'value' => $request->value ?? $tax->value,
                'name_ar' => $request->name_ar ?? $tax->name_ar,
                'name_en' => $request->name_en ?? $tax->name_en,
                'active' => $request->active ?? $tax->active,
            ]);

            return \SuccessData('Tax updated successfully', $tax);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeleteTaxRequest $request)
    {
        try {
            $tax = Tax::find($request->id);

            if ($tax->reservationTaxes()->exists()) {
                return \Failed('Tax linked to reservations, can\'t delete.');
            }

            $tax->delete();

            return \Success('Tax deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
