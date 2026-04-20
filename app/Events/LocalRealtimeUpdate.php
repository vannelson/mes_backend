<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LocalRealtimeUpdate implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $topic,
        public array $payload = []
    ) {
    }

    public static function topicFromPath(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'mes/notifications') => 'notifications',
            str_starts_with($path, 'mes/messages') => 'messages',
            str_starts_with($path, 'mes/workorders/virtualization') => 'workorders.virtualization',
            str_starts_with($path, 'mes/workorders/events') => 'workorders.events',
            str_starts_with($path, 'mes/triggers/executions') => 'triggers.executions',
            str_starts_with($path, 'mes/triggers') => 'triggers',
            default => 'updates',
        };
    }

    public function broadcastOn(): Channel
    {
        return new Channel("mes.{$this->topic}");
    }

    public function broadcastAs(): string
    {
        return 'RealtimeUpdate';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
