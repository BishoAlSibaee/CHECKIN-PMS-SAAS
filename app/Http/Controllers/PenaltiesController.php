<?php

namespace App\Http\Controllers;

use App\Http\Requests\Penaltie\AddPenaltieRequest;
use App\Http\Requests\Penaltie\AddReservationPenaltyRequest;
use App\Http\Requests\Penaltie\DeletePenaltieRequest;
use App\Http\Requests\Penaltie\GetPenaltiesByReservationRequest;
use App\Http\Requests\Penaltie\UpdatePenaltieRequest;
use App\Models\Penaltie;
use App\Models\ReservationPenalty;
use App\Services\Cache\CacheInvalidator;
use App\Services\Cache\ReferenceDataCache;

class PenaltiesController extends Controller
{
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $page = max(1, (int) request()->input('page', 1));
            $penalties = ReferenceDataCache::remember(
                'penalties',
                "page:{$page}:per:{$perPage}",
                fn () => Penaltie::query()->paginate($perPage)
            );

            return \Pagination($penalties);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddPenaltieRequest $request)
    {
        try {
            $penalty = Penaltie::create([
                'type' => $request->type,
                'value' => $request->value,
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'description' => $request->description,
            ]);
            CacheInvalidator::penaltiesChanged();

            return \SuccessData('Penalty added successfully', $penalty);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdatePenaltieRequest $request)
    {
        try {
            $penalty = Penaltie::find($request->id);

            $penalty->update([
                'type' => $request->type ?? $penalty->type,
                'value' => $request->value ?? $penalty->value,
                'name_ar' => $request->name_ar ?? $penalty->name_ar,
                'name_en' => $request->name_en ?? $penalty->name_en,
                'description' => $request->description ?? $penalty->description,
            ]);
            CacheInvalidator::penaltiesChanged();

            return \SuccessData('Penalty updated successfully', $penalty);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeletePenaltieRequest $request)
    {
        try {
            $penalty = Penaltie::find($request->id);

            if (ReservationPenalty::where('penalty_id', $request->id)->exists()) {
                return \Failed('Penalty linked to reservations, can\'t delete.');
            }

            $penalty->delete();
            CacheInvalidator::penaltiesChanged();

            return \Success('Penalty deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getByReservation(GetPenaltiesByReservationRequest $request)
    {
        try {
            $penalties = ReservationPenalty::where('reservation_id', $request->reservation_id)
                ->with('penalty')
                ->get();

            return \SuccessData('Penalties retrieved successfully', $penalties);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function addReservationPenalty(AddReservationPenaltyRequest $request)
    {
        try {
            $reservationPenalty = ReservationPenalty::create([
                'reservation_id' => $request->reservation_id,
                'penalty_id' => $request->penalty_id,
                'amount' => $request->amount,
            ]);

            return \SuccessData('Reservation penalty added successfully', $reservationPenalty);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
