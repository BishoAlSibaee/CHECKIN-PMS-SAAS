<?php

namespace App\Http\Controllers;

use App\Models\ReservationSource;
use App\Http\Requests\ReservationSource\AddReservationSourceRequest;
use App\Http\Requests\ReservationSource\UpdateReservationSourceRequest;
use App\Http\Requests\ReservationSource\DeleteReservationSourceRequest;

class ReservationSourcesController extends Controller
{
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $sources = ReservationSource::paginate($perPage);
            return \Pagination($sources);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddReservationSourceRequest $request)
    {
        try {
            $source = ReservationSource::create([
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'description' => $request->description ?? null,
                'is_ota_channel' => (bool) ($request->is_ota_channel ?? false),
                'default_commission_percent' => $request->default_commission_percent ?? null,
            ]);

            return \SuccessData('Reservation source added successfully', $source);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdateReservationSourceRequest $request)
    {
        try {
            $source = ReservationSource::find($request->id);

            $source->update([
                'name_ar' => $request->name_ar ?? $source->name_ar,
                'name_en' => $request->name_en ?? $source->name_en,
                'description' => $request->description ?? $source->description,
                'is_ota_channel' => $request->has('is_ota_channel') ? (bool) $request->is_ota_channel : $source->is_ota_channel,
                'default_commission_percent' => $request->default_commission_percent ?? $source->default_commission_percent,
            ]);

            return \SuccessData('Reservation source updated successfully', $source);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeleteReservationSourceRequest $request)
    {
        try {
            $source = ReservationSource::find($request->id);
            $source->delete();

            return \Success('Reservation source deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
