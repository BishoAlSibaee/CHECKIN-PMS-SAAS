<?php

namespace App\Http\Controllers;

use App\Services\Taqnyat\TaqnyatCredentialStore;
use App\Services\Taqnyat\TaqnyatService;
use Illuminate\Http\Request;

class ExternalSubscriptionController extends Controller
{
    public function __construct(
        private TaqnyatService $taqnyatService,
        private TaqnyatCredentialStore $credentialStore,
    ) {}

    public function taqnyatBalance(Request $request)
    {
        try {
            $hotelId = (int) $request->attributes->get('currentHotelId');
            $result = $this->taqnyatService->getBalance($hotelId);

            return \SuccessData('Taqnyat balance retrieved', $result);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function saveTaqnyatCredentials(Request $request)
    {
        $request->validate([
            'token' => 'required|string|max:500',
            'sender' => 'nullable|string|max:50',
        ]);

        try {
            $hotelId = (int) $request->attributes->get('currentHotelId');
            $this->credentialStore->save([
                'token' => $request->token,
                'sender' => $request->sender,
            ], $hotelId);

            return \SuccessData('Taqnyat credentials saved', null);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
