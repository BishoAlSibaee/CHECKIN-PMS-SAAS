<?php

namespace App\Http\Controllers;

use App\Http\Requests\Revenue\GetRevenueRequest;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Exception;

class RevenueController extends Controller
{
    public function __construct(private RevenueAccrualService $revenueAccrualService)
    {
    }

    public function getTotalRevenue(GetRevenueRequest $request)
    {
        return $this->respondRevenue($request, 'total', null);
    }

    public function getRoomRevenue(GetRevenueRequest $request, int $entity_id)
    {
        return $this->respondRevenue($request, 'room', $entity_id);
    }

    public function getSuiteRevenue(GetRevenueRequest $request, int $entity_id)
    {
        return $this->respondRevenue($request, 'suite', $entity_id);
    }

    public function getFloorRevenue(GetRevenueRequest $request, int $entity_id)
    {
        return $this->respondRevenue($request, 'floor', $entity_id);
    }

    public function getBuildingRevenue(GetRevenueRequest $request, int $entity_id)
    {
        return $this->respondRevenue($request, 'building', $entity_id);
    }

    public function getRoomTypeRevenue(GetRevenueRequest $request, int $entity_id)
    {
        return $this->respondRevenue($request, 'roomtype', $entity_id);
    }

    private function respondRevenue(GetRevenueRequest $request, string $scope, ?int $entityId)
    {
        try {
            $validated = $request->validated();
            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);
            $compareStart = isset($validated['compare_start_date'])
                ? Carbon::parse($validated['compare_start_date']) : null;
            $compareEnd = isset($validated['compare_end_date'])
                ? Carbon::parse($validated['compare_end_date']) : null;
            $includeDetails = (bool) ($validated['include_details'] ?? false);

            $current = $this->revenueAccrualService->calculate(
                $scope,
                $entityId,
                $startDate,
                $endDate,
                $includeDetails
            );

            $comparison = null;
            if ($compareStart && $compareEnd) {
                $comp = $this->revenueAccrualService->calculate(
                    $scope,
                    $entityId,
                    $compareStart,
                    $compareEnd,
                    false
                );
                $comparison = $comp['current'];
            }

            $responseData = [
                'scope' => $scope,
                'entity_id' => $entityId,
                'period' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                    'days' => $endDate->diffInDays($startDate) + 1,
                ],
                'revenue' => [
                    'current' => $current['current'],
                    'comparison' => $comparison,
                    'details' => $current['details'],
                ],
            ];

            return SuccessData("Revenue by {$scope} retrieved successfully.", $responseData);
        } catch (Exception $e) {
            return Failed($e->getMessage());
        }
    }
}
