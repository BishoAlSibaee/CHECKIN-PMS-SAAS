<?php

namespace App\Http\Controllers;

use App\Models\Guest_classification_feature;
use App\Models\Guest_classification;
use App\Models\Guest_feature;
use Illuminate\Http\Request;
use App\Http\Requests\GuestClassificationFeature\AddGuestClassificationFeatureRequest;
use App\Http\Requests\GuestClassificationFeature\DeleteGuestClassificationFeatureRequest;
use App\Http\Requests\GuestClassificationFeature\GetGuestClassificationFeatureByClassificationRequest;

class GuestClassificationFeaturesController extends Controller
{
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $relations = Guest_classification_feature::with(['guest_classification', 'guest_feature'])->paginate($perPage);
            return \Pagination($relations);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getFeaturesByClassification(GetGuestClassificationFeatureByClassificationRequest $request)
    {
        try {
            $mappings = Guest_classification_feature::with('guest_feature')
                ->where('guest_classification_id', $request->guest_classification_id)
                ->get();

            return \SuccessData('Features for classification retrieved successfully', $mappings);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddGuestClassificationFeatureRequest $request)
    {
        try {
            // Check if the relationship already exists
            $exists = Guest_classification_feature::where('guest_classification_id', $request->guest_classification_id)
                ->where('guest_feature_id', $request->guest_feature_id)
                ->exists();

            if ($exists) {
                return \Failed('This feature is already linked to this classification.');
            }

            $relation = Guest_classification_feature::create([
                'guest_classification_id' => $request->guest_classification_id,
                'guest_feature_id' => $request->guest_feature_id,
            ]);

            return \SuccessData('Feature added to classification successfully', $relation);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeleteGuestClassificationFeatureRequest $request)
    {
        try {
            $relation = Guest_classification_feature::find($request->id);


            $relation->delete();

            return \Success('Feature removed from classification successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
