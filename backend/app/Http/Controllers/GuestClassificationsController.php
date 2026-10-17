<?php

namespace App\Http\Controllers;

use App\Models\Guest_classification;
use Illuminate\Http\Request;
use App\Http\Requests\GuestClassification\AddGuestClassificationRequest;
use App\Http\Requests\GuestClassification\UpdateGuestClassificationRequest;
use App\Http\Requests\GuestClassification\DeleteGuestClassificationRequest;

class GuestClassificationsController extends Controller
{
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $classifications = Guest_classification::paginate($perPage);
            return \Pagination($classifications);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddGuestClassificationRequest $request)
    {
        try {
            $classification = Guest_classification::create([
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'description' => $request->description ?? null,
                'discount_id' => $request->discount_id ?? null,
                'active' => $request->active ?? 1,
            ]);

            return \SuccessData('Guest classification added successfully', $classification);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdateGuestClassificationRequest $request)
    {
        try {
            $classification = Guest_classification::find($request->id);

            $classification->update([
                'name_ar' => $request->name_ar ?? $classification->name_ar,
                'name_en' => $request->name_en ?? $classification->name_en,
                'description' => $request->description ?? $classification->description,
                'discount_id' => $request->discount_id ?? $classification->discount_id,
                'active' => $request->active ?? $classification->active,
            ]);

            return \SuccessData('Guest classification updated successfully', $classification);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeleteGuestClassificationRequest $request)
    {
        try {
            $classification = Guest_classification::find($request->id);

            if ($classification->guest_classification_features()->exists()) {
                return \Failed('Guest classification linked to features, can\'t delete.');
            }

            $classification->delete();

            return \Success('Guest classification deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
