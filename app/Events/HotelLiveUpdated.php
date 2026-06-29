<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HotelLiveUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  list<string>  $scopes
     * @param  array{type: string, id: int|string}|null  $entity
     */
    public function __construct(
        public int $boardVersion,
        public array $scopes,
        public string $action,
        public ?array $entity = null,
        public ?string $occurredAt = null,
    ) {
        $this->occurredAt ??= now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('hotel.operations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hotel.live.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'board_version' => $this->boardVersion,
            'scopes'        => $this->scopes,
            'action'        => $this->action,
            'entity'        => $this->entity,
            'occurred_at'   => $this->occurredAt,
        ];
    }
}
