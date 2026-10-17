<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupBooking\CancelGroupBookingRequest;
use App\Http\Requests\GroupBooking\StoreGroupBookingRequest;
use App\Models\GroupBooking;
use App\Services\GroupBookingCancellationService;
use App\Services\GroupBookingCreationService;
use App\Services\GroupBookingFinancialService;
use App\Services\HotelLivePublisher;
use Illuminate\Http\Request;

class GroupBookingController extends Controller
{
    public function __construct(
        private GroupBookingCreationService $creationService,
        private GroupBookingCancellationService $cancellationService,
        private GroupBookingFinancialService $financialService,
        private HotelLivePublisher $livePublisher,
    ) {}

    public function index(Request $request)
    {
        try {
            $currentHotelId = $request->attributes->get('currentHotelId');
            $perPage = \returnPerPage();

            $groups = GroupBooking::query()
                ->when($currentHotelId, fn ($q) => $q->where('hotel_id', $currentHotelId))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('created_by'), fn ($q) => $q->where('created_by', $request->input('created_by')))
                ->withCount('reservations')
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return \Pagination($groups);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function show(int $id)
    {
        try {
            $groupBooking = GroupBooking::with([
                'reservations.client',
                'reservations.reservationRooms.room.roomType',
                'reservations.payments',
                'creator',
            ])->findOrFail($id);

            return \SuccessData('Group booking retrieved', [
                'group' => $groupBooking,
                'financials' => $this->financialService->aggregateFor($groupBooking),
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function financials(int $id)
    {
        try {
            $groupBooking = GroupBooking::findOrFail($id);

            return \SuccessData('Group booking financials retrieved', $this->financialService->aggregateFor($groupBooking));
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(StoreGroupBookingRequest $request)
    {
        try {
            $currentHotelId = (int) $request->attributes->get('currentHotelId');
            $userId = (int) auth()->id();

            $groupBooking = $this->creationService->createGroup(
                $request->only(['name', 'company_name', 'contact_name', 'contact_mobile', 'contact_email', 'notes', 'group_discount_percent']),
                $request->input('members', []),
                $currentHotelId,
                $userId
            );

            $this->livePublisher->publishGroupChanged($groupBooking->id, 'group_created');

            return \SuccessData('Group booking created successfully', [
                'group' => $groupBooking,
                'financials' => $this->financialService->aggregateFor($groupBooking),
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function cancel(CancelGroupBookingRequest $request, int $id)
    {
        try {
            $groupBooking = GroupBooking::findOrFail($id);

            if (GroupBooking::isCancelled((int) $groupBooking->status)) {
                return \Failed('Group booking is already cancelled', 422);
            }

            $result = $this->cancellationService->cancelGroup($groupBooking, $request->input('reason'));

            $this->livePublisher->publishGroupChanged($groupBooking->id, 'group_cancelled');

            return \SuccessData(
                $result['fully_cancelled'] ? 'Group booking fully cancelled' : 'Some members could not be cancelled',
                $result
            );
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
