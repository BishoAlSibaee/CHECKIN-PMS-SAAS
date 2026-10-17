<?php

namespace App\Services\Channex;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP wrapper around the Channex.io channel-manager API. Confirmed
 * directly against the sandbox (https://staging.channex.io/api/v1):
 *   - Auth: "user-api-key" request header (not Bearer).
 *   - Success envelope: {"data": {...}|[...], "meta": {...}}.
 *   - Error envelope: {"errors": {"code", "title", "details"}}, HTTP 400/401/404/422.
 *   - Write bodies are wrapped under the entity name, e.g. {"room_type": {...}}.
 *   - Rates are integers in minor units (cents/halalas) on writes, decimal
 *     strings on reads — callers convert, this client is unit-agnostic.
 *   - The booking-revisions feed is a 30-minute window, not a durable queue —
 *     unacked items expire permanently. Ack promptly.
 */
class ChannexClient
{
    public function isEnabled(): bool
    {
        return (bool) config('channex.enabled') && filled(config('channex.api_key'));
    }

    /** @return array<string, mixed> */
    public function getProperties(): array
    {
        return $this->get('/properties');
    }

    /** @param  array<string, mixed>  $attributes */
    public function createProperty(array $attributes): array
    {
        return $this->post('/properties', ['property' => $attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateProperty(string $channexPropertyId, array $attributes): array
    {
        return $this->put("/properties/{$channexPropertyId}", ['property' => $attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    public function createRoomType(array $attributes): array
    {
        return $this->post('/room_types', ['room_type' => $attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateRoomType(string $channexRoomTypeId, array $attributes): array
    {
        return $this->put("/room_types/{$channexRoomTypeId}", ['room_type' => $attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    public function createRatePlan(array $attributes): array
    {
        return $this->post('/rate_plans', ['rate_plan' => $attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateRatePlan(string $channexRatePlanId, array $attributes): array
    {
        return $this->put("/rate_plans/{$channexRatePlanId}", ['rate_plan' => $attributes]);
    }

    /**
     * @param  list<array{property_id: string, room_type_id: string, date?: string, date_from?: string, date_to?: string, availability: int}>  $values
     */
    public function pushAvailability(array $values): array
    {
        return $this->post('/availability', ['values' => $values]);
    }

    /**
     * @param  list<array<string, mixed>>  $values  see Channex docs for the full restriction field set (rate, min_stay_arrival, stop_sell, ...)
     */
    public function pushRestrictions(array $values): array
    {
        return $this->post('/restrictions', ['values' => $values]);
    }

    /** @param  array{callback_url: string, event_mask: string, property_id?: string|null, is_active?: bool, send_data?: bool, headers?: array<string, string>}  $webhook */
    public function registerWebhook(array $webhook): array
    {
        return $this->post('/webhooks', ['webhook' => $webhook]);
    }

    public function getBookingRevision(string $revisionId): array
    {
        return $this->get("/booking_revisions/{$revisionId}");
    }

    public function ackBookingRevision(string $revisionId): array
    {
        return $this->post("/booking_revisions/{$revisionId}/ack", []);
    }

    /** The 30-minute non-durable feed of unacknowledged booking revisions. */
    public function getBookingRevisionsFeed(): array
    {
        return $this->get('/booking_revisions/feed');
    }

    /**
     * Time-scoped recovery for post-outage backfill — NOT meant for periodic
     * polling (use getBookingRevisionsFeed()/ack for that).
     */
    public function getBookingsSince(string $isoTimestamp): array
    {
        return $this->get('/bookings', ['filter[inserted_at][gte]' => $isoTimestamp]);
    }

    /**
     * Answers an Airbnb inquiry/reservation_request/alteration_request — the
     * only three live_feed event kinds Channex allows resolving; anything
     * else returns a 422 "not_supported" from Channex itself. The decision
     * is final: resolving an already-resolved event returns it unchanged.
     *
     * @param  array<string, mixed>  $resolution  e.g. {"accept": true} for a
     *                                            reservation_request, or
     *                                            {"accept": "accept"|"decline"|"cancel"}
     *                                            for an alteration_request —
     *                                            shape differs per Channex's docs.
     */
    public function resolveLiveFeedEvent(string $liveFeedEventId, array $resolution): array
    {
        return $this->post("/live_feed/{$liveFeedEventId}/resolve", ['resolution' => $resolution]);
    }

    public function getLiveFeedEvent(string $liveFeedEventId): array
    {
        return $this->get("/live_feed/{$liveFeedEventId}");
    }

    /** @param  array<string, mixed>  $filters  e.g. ['property_id' => '...', 'page' => 1, 'limit' => 20] */
    public function listReviews(array $filters = []): array
    {
        return $this->get('/reviews', $filters);
    }

    public function getReview(string $reviewId): array
    {
        return $this->get("/reviews/{$reviewId}");
    }

    public function replyToReview(string $reviewId, string $reply): array
    {
        return $this->post("/reviews/{$reviewId}/reply", ['reply' => ['reply' => $reply]]);
    }

    /** Aggregated score across all OTAs for one property. */
    public function getPropertyScore(string $channexPropertyId): array
    {
        return $this->get("/scores/{$channexPropertyId}");
    }

    /** Same as getPropertyScore() plus a per-OTA breakdown. */
    public function getPropertyScoreDetailed(string $channexPropertyId): array
    {
        return $this->get("/scores/{$channexPropertyId}/detailed");
    }

    /**
     * A unified inbox across Booking.com/Expedia/Airbnb — includes threads
     * with no booking at all (Airbnb "Inquiry" messages, sent before a
     * booking exists). Expedia bookings from the Expedia affiliate network
     * don't support messaging at all per Channex's docs.
     *
     * @param  array<string, mixed>  $filters  e.g. ['property_id' => '...', 'page' => 1, 'limit' => 20]
     */
    public function listMessageThreads(array $filters = []): array
    {
        return $this->get('/message_threads', $filters);
    }

    public function getMessageThread(string $threadId): array
    {
        return $this->get("/message_threads/{$threadId}");
    }

    public function getThreadMessages(string $threadId): array
    {
        return $this->get("/message_threads/{$threadId}/messages");
    }

    public function sendThreadMessage(string $threadId, string $message): array
    {
        return $this->post("/message_threads/{$threadId}/messages", ['message' => ['message' => $message]]);
    }

    /** Flags a Booking.com thread as not requiring a reply — no other effect. */
    public function markThreadNoReplyNeeded(string $threadId): array
    {
        return $this->post("/message_threads/{$threadId}/no_reply_needed", []);
    }

    public function closeMessageThread(string $threadId): array
    {
        return $this->post("/message_threads/{$threadId}/close", []);
    }

    private function client(): PendingRequest
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Channex integration is disabled or missing an API key.');
        }

        return Http::baseUrl((string) config('channex.base_url'))
            ->timeout((int) config('channex.timeout'))
            ->withHeaders(['user-api-key' => (string) config('channex.api_key')])
            ->acceptJson();
    }

    /** @param  array<string, mixed>  $query */
    private function get(string $path, array $query = []): array
    {
        return $this->unwrap($this->client()->get($path, $query), 'GET '.$path);
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $path, array $body): array
    {
        return $this->unwrap($this->client()->post($path, $body), 'POST '.$path);
    }

    /** @param  array<string, mixed>  $body */
    private function put(string $path, array $body): array
    {
        return $this->unwrap($this->client()->put($path, $body), 'PUT '.$path);
    }

    private function unwrap(\Illuminate\Http\Client\Response $response, string $label): array
    {
        if ($response->failed()) {
            $errors = $response->json('errors');
            $message = is_array($errors) ? ($errors['title'] ?? json_encode($errors)) : $response->body();
            throw new RuntimeException("Channex {$label} failed ({$response->status()}): {$message}");
        }

        return $response->json() ?? [];
    }
}
