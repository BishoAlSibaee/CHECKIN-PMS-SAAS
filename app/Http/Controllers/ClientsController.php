<?php

namespace App\Http\Controllers;

use App\Http\Requests\Client\AddClientRequest;
use App\Http\Requests\Client\GetClientByRequest;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientsController extends Controller
{
    private function currentTenantId(Request $request): int
    {
        return (int) $request->attributes->get('currentTenantId');
    }

    public function index(Request $request)
    {
        try {
            $perPage = \returnPerPage();
            $search = $request->search;
            $excludeAssigned = $request->exclude_assigned;

            $query = Client::on('mysql2')->where('tenant_id', $this->currentTenantId($request))->latest();

            if ($excludeAssigned) {
                $query->whereDoesntHave('classifications');
            }

            if ($search) {
                $term = trim($search);
                $query->where(function ($q) use ($term) {
                    $q->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$term}%"])
                        ->orWhere('mobile', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('IdNumber', 'like', "%{$term}%")
                        ->orWhere('international_code', 'like', "%{$term}%");

                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            }

            $clients = $query->paginate($perPage);

            return \Pagination($clients);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddClientRequest $request)
    {
        try {
            $client = Client::create([
                'tenant_id' => $this->currentTenantId($request),
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email ?? null,
                'international_code' => $request->international_code ?? null,
                'mobile' => $request->mobile,
                'IdType' => $request->IdType ?? null,
                'IdNumber' => $request->IdNumber ?? null,
                'birth_date' => $request->birth_date ?? null,
                'gender' => $request->gender ?? null,
                'guest_type' => $request->guest_type ?? null,
                'nationality' => $request->nationality ?? null,
            ]);

            return \SuccessData('Client added successfully', $client);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(AddClientRequest $request)
    {
        try {
            $client = Client::where('tenant_id', $this->currentTenantId($request))->find($request->id);

            if (! $client) {
                return \Failed('Client not found');
            }

            $client->update([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email ?? null,
                'international_code' => $request->international_code ?? $client->international_code,
                'mobile' => $request->mobile,
                'IdType' => $request->IdType ?? null,
                'IdNumber' => $request->IdNumber ?? null,
                'birth_date' => $request->birth_date ?? null,
                'gender' => $request->gender ?? null,
                'guest_type' => $request->guest_type ?? null,
                'nationality' => $request->nationality ?? null,
            ]);

            return \SuccessData('Client updated successfully', $client);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $client = Client::where('tenant_id', $this->currentTenantId($request))->find($id);

            if (! $client) {
                return \Failed('Client not found');
            }

            return \SuccessData('Client retrieved successfully', $client);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Running statement of account across every reservation for this client —
     * for corporate/travel-agency clients who need one consolidated view instead
     * of checking each reservation's folio individually.
     */
    public function statement(Request $request, $id)
    {
        try {
            $client = Client::where('tenant_id', $this->currentTenantId($request))->find($id);
            if (! $client) {
                return \Failed('Client not found');
            }

            $reservations = Reservation::where('client_id', $id)
                ->with('payments')
                ->orderByDesc('start_date')
                ->get();

            $reservationIds = $reservations->pluck('id');
            $invoicesByReservation = \App\Models\Invoice::query()
                ->whereIn('reservation_id', $reservationIds)
                ->get()
                ->groupBy('reservation_id');

            $rows = $reservations->map(function (Reservation $reservation) use ($invoicesByReservation) {
                $invoices = $invoicesByReservation->get($reservation->id, collect());
                $taxInvoice = $invoices->firstWhere('type', \App\Models\Invoice::TYPE_TAX_INVOICE);
                $creditNotesTotal = $invoices->where('type', \App\Models\Invoice::TYPE_CREDIT_NOTE)->sum('total');

                return [
                    'reservation_id' => $reservation->id,
                    'confirmation_code' => $reservation->confirmation_code,
                    'start_date' => $reservation->start_date,
                    'expire_date' => $reservation->expire_date,
                    'total' => (float) $reservation->total,
                    'paid' => $reservation->paidNetAmount(),
                    'balance_due' => $reservation->balanceDue(),
                    'invoice_number' => $taxInvoice?->invoice_number,
                    'credit_notes_total' => (float) $creditNotesTotal,
                ];
            });

            return \SuccessData('Client statement of account', [
                'client' => [
                    'id' => $client->id,
                    'name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
                    'mobile' => $client->mobile,
                    'email' => $client->email,
                ],
                'reservations' => $rows,
                'total_billed' => (float) $rows->sum('total'),
                'total_paid' => (float) $rows->sum('paid'),
                'total_outstanding' => (float) $rows->sum('balance_due'),
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getBy(GetClientByRequest $request)
    {
        try {
            // Laravel's where()/orWhere() silently turns a null value into an "IS NULL"
            // check (not a no-op) — passing $request->email straight through when email
            // wasn't provided matched ANY client with a null email column, regardless of
            // the mobile/IdNumber actually searched for. Only add a clause for filters
            // that were actually supplied, and match nothing if none were.
            $client = Client::where('tenant_id', $this->currentTenantId($request))
                ->where(function ($query) use ($request) {
                $hasFilter = false;

                if ($request->filled('mobile')) {
                    $query->orWhere('mobile', $request->mobile);
                    $hasFilter = true;
                }
                if ($request->filled('email')) {
                    $query->orWhere('email', $request->email);
                    $hasFilter = true;
                }
                if ($request->filled('IdNumber')) {
                    $query->orWhere('IdNumber', $request->IdNumber);
                    $hasFilter = true;
                }

                if (! $hasFilter) {
                    $query->whereRaw('1 = 0');
                }
            })->first();

            if (! $client) {
                return \Failed('Client not found');
            }

            return \SuccessData('Client retrieved successfully', $client);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getClientById(Request $request, $id)
    {
        try {
            $client = Client::where('tenant_id', $this->currentTenantId($request))->find($id);

            if (! $client) {
                return \Failed('Client not found');
            }

            $reservationsCount = Reservation::where('client_id', $id)->count();
            $lastVisit = Reservation::where('client_id', $id)->max('start_date');
            $totalSpent = $this->clientNetPaidTotal((int) $id);

            $payload = $client->toArray();
            $payload['reservations_count'] = $reservationsCount;
            $payload['last_visit'] = $lastVisit;
            $payload['total_spent'] = $totalSpent;

            return \SuccessData('Client retrieved successfully', $payload);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    private function clientNetPaidTotal(int $clientId): float
    {
        $paymentType = ReservationPay::TYPE_PAYMENT;
        $refundType = ReservationPay::TYPE_REFUND;

        $net = DB::table('reservation_pay')
            ->join('reservations', 'reservations.id', '=', 'reservation_pay.reservation_id')
            ->where('reservations.client_id', $clientId)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN reservation_pay.type = ? THEN reservation_pay.pay WHEN reservation_pay.type = ? THEN -reservation_pay.pay ELSE 0 END), 0) as net',
                [$paymentType, $refundType]
            )
            ->value('net');

        return round((float) $net, 2);
    }
}
