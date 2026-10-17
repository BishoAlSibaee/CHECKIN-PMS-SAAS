<?php

namespace App\Http\Controllers\Channex;

use App\Http\Controllers\Controller;
use App\Models\ChannexProperty;
use App\Services\Channex\ChannexClient;
use Illuminate\Http\Request;

/**
 * Guest messages from every connected OTA's own chat system (Booking.com,
 * Expedia, Airbnb — the only three Channex supports), in one inbox. Thin
 * proxy over Channex's Message Threads API; no local copy kept, Channex is
 * the source of truth for message content and the reply itself. Threads can
 * exist with no booking at all (Airbnb "Inquiry" messages sent before a
 * booking is made).
 */
class ChannexMessageController extends Controller
{
    public function __construct(private ChannexClient $client) {}

    private function currentHotelId(Request $request): int
    {
        return (int) $request->attributes->get('currentHotelId');
    }

    private function channexPropertyId(Request $request): ?string
    {
        return ChannexProperty::where('hotel_id', $this->currentHotelId($request))->value('channex_property_id');
    }

    public function index(Request $request)
    {
        $propertyId = $this->channexPropertyId($request);
        if ($propertyId === null) {
            return \Failed('This hotel is not connected to Channex yet.');
        }

        try {
            $response = $this->client->listMessageThreads([
                'property_id' => $propertyId,
                'page' => (int) $request->input('page', 1),
                'limit' => (int) $request->input('limit', 20),
            ]);

            return \SuccessData('Message threads retrieved', [
                'threads' => $response['data'] ?? [],
                'meta' => $response['meta'] ?? null,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function messages(Request $request, string $threadId)
    {
        try {
            $response = $this->client->getThreadMessages($threadId);

            return \SuccessData('Messages retrieved', $response['data'] ?? []);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function reply(Request $request, string $threadId)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        try {
            $response = $this->client->sendThreadMessage($threadId, (string) $request->message);

            return \SuccessData('Message sent', $response['data'] ?? null);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function noReplyNeeded(Request $request, string $threadId)
    {
        try {
            $response = $this->client->markThreadNoReplyNeeded($threadId);

            return \SuccessData('Marked as no reply needed', $response['data'] ?? null);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function close(Request $request, string $threadId)
    {
        try {
            $response = $this->client->closeMessageThread($threadId);

            return \SuccessData('Thread closed', $response['data'] ?? null);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
