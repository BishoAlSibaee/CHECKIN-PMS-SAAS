<?php

namespace App\Http\Controllers\Channex;

use App\Http\Controllers\Controller;
use App\Models\ChannexProperty;
use App\Services\Channex\ChannexClient;
use Illuminate\Http\Request;

/**
 * Guest reviews from every connected OTA, in one place — thin proxy over
 * Channex's own Reviews/Scores API (no local copy kept; Channex is the
 * single source of truth for review content and the reply itself).
 */
class ChannexReviewController extends Controller
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
            $response = $this->client->listReviews([
                'property_id' => $propertyId,
                'page' => (int) $request->input('page', 1),
                'limit' => (int) $request->input('limit', 20),
            ]);

            return \SuccessData('Reviews retrieved', [
                'reviews' => $response['data'] ?? [],
                'meta' => $response['meta'] ?? null,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function reply(Request $request, string $reviewId)
    {
        $request->validate([
            'reply' => 'required|string|max:2000',
        ]);

        try {
            $response = $this->client->replyToReview($reviewId, (string) $request->reply);

            return \SuccessData('Reply posted', $response['data'] ?? null);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function scores(Request $request)
    {
        $propertyId = $this->channexPropertyId($request);
        if ($propertyId === null) {
            return \Failed('This hotel is not connected to Channex yet.');
        }

        try {
            $response = $this->client->getPropertyScoreDetailed($propertyId);

            return \SuccessData('Scores retrieved', $response['data'] ?? null);
        } catch (\Exception $e) {
            // Channex returns 404 when a property simply has no reviews
            // yet — that's an empty state for the UI, not a real error.
            if (str_contains($e->getMessage(), '(404)')) {
                return \SuccessData('No scores yet', null);
            }

            return \Failed($e->getMessage());
        }
    }
}
