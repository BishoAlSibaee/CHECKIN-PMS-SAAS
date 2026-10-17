<?php

namespace App\Http\Controllers;

use App\Models\Stay_reason;
use App\Models\Reservation;
use App\Http\Requests\StayReason\AddStayReasonRequest;
use App\Http\Requests\StayReason\UpdateStayReasonRequest;
use App\Http\Requests\StayReason\DeleteStayReasonRequest;

class StayReasonsController extends Controller
{
    /**
     * Get all stay reasons with pagination
     */
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $stayReasons = Stay_reason::paginate($perPage);
            return \Pagination($stayReasons);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Add a new stay reason
     */
    public function store(AddStayReasonRequest $request)
    {
        try {
            $reason = Stay_reason::create([
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'description' => $request->description,
            ]);

            return \SuccessData('Stay reason added successfully', $reason);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Update a stay reason
     */
    public function update(UpdateStayReasonRequest $request)
    {
        try {
            $reason = Stay_reason::find($request->id);

            $reason->update([
                'name_ar' => $request->name_ar ?? $reason->name_ar,
                'name_en' => $request->name_en ?? $reason->name_en,
                'description' => $request->description ?? $reason->description,
            ]);

            return \SuccessData('Stay reason updated successfully', $reason);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Delete a stay reason
     */
    public function destroy(DeleteStayReasonRequest $request)
    {
        try {
            $stayReason = Stay_reason::find($request->id);

            $hasReservations = Reservation::where('stay_reason_id', $stayReason->id)->exists();

            if ($hasReservations) {
                return \Failed('Cannot delete. This stay reason is linked to existing reservations.');
            }

            $stayReason->delete();

            return \Success('Stay reason deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
