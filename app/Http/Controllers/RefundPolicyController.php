<?php

namespace App\Http\Controllers;

use App\Exceptions\RefundNotAllowedException;
use App\Http\Requests\RefundPolicy\DestroyRefundPolicyRequest;
use App\Http\Requests\RefundPolicy\PreviewRefundPolicyRequest;
use App\Http\Requests\RefundPolicy\StoreRefundPolicyRequest;
use App\Http\Requests\RefundPolicy\UpdateRefundPolicyRequest;
use App\Http\Resources\RefundPolicy\RefundPolicyResource;
use App\Models\RefundPolicy;
use App\Models\Reservation;
use App\Services\Cache\CacheInvalidator;
use App\Services\Cache\ReferenceDataCache;
use App\Services\RefundPolicyService;

class RefundPolicyController extends Controller
{
    public function __construct(private RefundPolicyService $refundPolicyService) {}

    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $page = max(1, (int) request()->input('page', 1));
            $paginator = ReferenceDataCache::remember(
                'refund_policies',
                "page:{$page}:per:{$perPage}",
                fn () => RefundPolicy::query()->orderBy('rent_type')->orderBy('days_threshold')->paginate($perPage)
            );

            $paginator->getCollection()->transform(
                fn (RefundPolicy $policy) => (new RefundPolicyResource($policy))->resolve()
            );

            return \Pagination($paginator);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(StoreRefundPolicyRequest $request)
    {
        try {
            $policy = RefundPolicy::create($request->validated());
            CacheInvalidator::refundPoliciesChanged();

            return \SuccessData('Refund policy created', (new RefundPolicyResource($policy))->resolve());
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function show(RefundPolicy $refund_policy)
    {
        return \SuccessData('Refund policy found', (new RefundPolicyResource($refund_policy))->resolve());
    }

    public function update(UpdateRefundPolicyRequest $request, RefundPolicy $refund_policy)
    {
        try {
            $refund_policy->update($request->validated());
            $refund_policy->refresh();
            CacheInvalidator::refundPoliciesChanged();

            return \SuccessData(
                'Refund policy updated',
                (new RefundPolicyResource($refund_policy))->resolve()
            );
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DestroyRefundPolicyRequest $request)
    {
        $policy = RefundPolicy::findOrFail($request->id);
        $policy->delete();
        CacheInvalidator::refundPoliciesChanged();

        return \Success('Refund policy deleted');
    }

    /**
     * Preview refund amount for a reservation before processing.
     */
    public function preview(PreviewRefundPolicyRequest $request)
    {
        try {
            $reservation = Reservation::with(['payments', 'client'])->findOrFail($request->reservation_id);
            $result = $this->refundPolicyService->preview(
                $reservation,
                $request->input('new_expire_date')
            );

            return \SuccessData('Refund preview', [
                'policy' => $result['policy'],
                'refund_amount' => $result['refund_amount'],
                'breakdown' => $result['breakdown'],
            ]);
        } catch (RefundNotAllowedException $e) {
            $payload = ['message' => $e->getMessage()];
            if (str_contains($e->getMessage(), 'No refund policy')) {
                $reservation = Reservation::with('payments')->find($request->reservation_id);
                if ($reservation) {
                    $payload['context'] = $this->refundPolicyService->buildContext($reservation);
                    $payload['hint'] = $this->refundPolicyHint($reservation, $payload['context']);
                }
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 422,
                'data' => $payload,
            ], 422);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function refundPolicyHint(Reservation $reservation, array $context): string
    {
        if ($context['during_stay'] ?? false) {
            $days = (int) ($context['days_since_start'] ?? 0);
            $pay = (int) ($context['payment_status'] ?? 0);
            $payLabel = $pay === 2 ? 'full payment' : ($pay === 1 ? 'partial payment' : 'no payment');

            return "Guest is in-house (day {$days} of stay), {$payLabel}. "
                .'Add a policy with timing "During stay", days threshold ≤ '.$days
                .', and payment status "Full payment" or "Any".';
        }

        $days = (int) ($context['days_until_start'] ?? 0);

        return "Before check-in ({$days} days until arrival). "
            .'Add a policy with timing "Before check-in" and days threshold ≤ '.$days.'.';
    }
}
