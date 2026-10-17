<?php

namespace App\Http\Controllers;

use App\Models\Guest_feature;
use App\Http\Requests\GuestFeature\AddGuestFeatureRequest;
use App\Http\Requests\GuestFeature\UpdateGuestFeatureRequest;
use App\Http\Requests\GuestFeature\DeleteGuestFeatureRequest;

class GuestFeaturesController extends Controller
{
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $features = Guest_feature::paginate($perPage);
            return \Pagination($features);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddGuestFeatureRequest $request)
    {
        try {
            $feature = Guest_feature::create([
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'feature_description' => $request->feature_description ?? null,
            ]);

            return \SuccessData('Guest feature added successfully', $feature);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdateGuestFeatureRequest $request)
    {
        try {
            $feature = Guest_feature::find($request->id);

            $feature->update([
                'name_ar' => $request->name_ar ?? $feature->name_ar,
                'name_en' => $request->name_en ?? $feature->name_en,
                'feature_description' => $request->feature_description ?? $feature->feature_description,
            ]);

            return \SuccessData('Guest feature updated successfully', $feature);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeleteGuestFeatureRequest $request)
    {
        try {
            $feature = Guest_feature::find($request->id);

            if ($feature->guest_classification_features()->exists()) {
                return \Failed('Guest feature linked to classifications, can\'t delete.');
            }

            $feature->delete();

            return \Success('Guest feature deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
