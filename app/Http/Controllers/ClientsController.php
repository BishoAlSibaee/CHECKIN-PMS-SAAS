<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Client\AddClientRequest;
use App\Http\Requests\Client\GetClientByRequest;

class ClientsController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = \returnPerPage();
            $search = $request->search;
            $excludeAssigned = $request->exclude_assigned;

            $query = Client::latest();

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
            $client = Client::find($request->id);

            if (!$client) {
                return \Failed('Client not found');
            }

            $client->update([
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

            return \SuccessData('Client updated successfully', $client);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function show($id)
    {
        try {
            $client = Client::find($id);

            if (!$client) {
                return \Failed('Client not found');
            }

            return \SuccessData('Client retrieved successfully', $client);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getBy(GetClientByRequest $request)
    {
        try {
            $client = Client::where('mobile', $request->mobile)
                ->orWhere('email', $request->email)
                ->orWhere('IdNumber', $request->IdNumber)
                ->first();

            if (!$client) {
                return \Failed('Client not found');
            }

            return \SuccessData('Client retrieved successfully', $client);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getClientById($id)
    {
        try {
            $client = Client::find($id);

            if (!$client) {
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
