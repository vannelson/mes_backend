<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class MessageService
{
    public function __construct(
        protected FirebaseRealtimeService $firebaseRealtimeService
    ) {
    }

    public function listThreads(User $user, int $limit = 20, int $page = 1): LengthAwarePaginator
    {
        $userId = $user->id;

        $latestMessageSub = Message::query()
            ->selectRaw(
                'MAX(id) as last_id, CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END as counterpart_id',
                [$userId]
            )
            ->where(function ($query) use ($userId) {
                $query->where('sender_id', $userId)
                    ->orWhere('recipient_id', $userId);
            })
            ->groupBy('counterpart_id');

        $threads = Message::query()
            ->joinSub($latestMessageSub, 'latest', function ($join) {
                $join->on('messages.id', '=', 'latest.last_id');
            })
            ->with(['sender', 'recipient'])
            ->select('messages.*', 'latest.counterpart_id')
            ->orderByDesc('messages.created_at')
            ->paginate($limit, ['*'], 'page', $page);

        $unreadBySender = Message::query()
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->selectRaw('sender_id, COUNT(*) as unread_count')
            ->groupBy('sender_id')
            ->pluck('unread_count', 'sender_id');

        $threads->getCollection()->transform(function (Message $message) use ($userId, $unreadBySender) {
            $counterpartId = (int) $message->counterpart_id;
            $counterpart = $message->sender_id === $userId ? $message->recipient : $message->sender;

            return [
                'counterpart_id' => $counterpartId,
                'counterpart' => $this->serializeUser($counterpart),
                'last_message' => $this->serializeMessage($message, $userId),
                'unread_count' => (int) ($unreadBySender[$counterpartId] ?? 0),
            ];
        });

        return $threads;
    }

    public function listConversation(
        User $user,
        int $counterpartId,
        int $limit = 50,
        int $page = 1
    ): LengthAwarePaginator {
        $userId = $user->id;

        return Message::query()
            ->where(function ($query) use ($userId, $counterpartId) {
                $query->where('sender_id', $userId)
                    ->where('recipient_id', $counterpartId);
            })
            ->orWhere(function ($query) use ($userId, $counterpartId) {
                $query->where('sender_id', $counterpartId)
                    ->where('recipient_id', $userId);
            })
            ->with(['sender', 'recipient'])
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function unreadCount(User $user): int
    {
        return Message::query()
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function send(User $sender, User $recipient, string $body): Message
    {
        $message = Message::query()->create([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'body' => $body,
        ]);

        $this->firebaseRealtimeService->publishMessageUpdate([
            'message_id' => $message->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'preview' => Str::limit($body, 140),
            'created_at' => $message->created_at?->toIso8601String(),
        ]);

        return $message;
    }

    public function markRead(
        User $user,
        array $ids = [],
        ?int $senderId = null,
        bool $all = false
    ): int {
        $query = Message::query()
            ->where('recipient_id', $user->id)
            ->whereNull('read_at');

        if ($senderId) {
            return $query->where('sender_id', $senderId)
                ->update(['read_at' => now()]);
        }

        if ($all) {
            return $query->update(['read_at' => now()]);
        }

        $ids = array_values(array_filter($ids, static fn ($id) => is_numeric($id)));
        if (empty($ids)) {
            return 0;
        }

        return $query->whereIn('id', $ids)->update(['read_at' => now()]);
    }

    public function serializeMessage(Message $message, int $currentUserId): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $message->sender_id,
            'recipient_id' => $message->recipient_id,
            'created_at' => $message->created_at?->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
            'is_read' => $message->recipient_id === $currentUserId
                ? (bool) $message->read_at
                : true,
            'sender' => $this->serializeUser($message->sender),
            'recipient' => $this->serializeUser($message->recipient),
        ];
    }

    protected function serializeUser(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'firstname' => $user->firstname ?? null,
            'lastname' => $user->lastname ?? null,
            'email' => $user->email ?? null,
            'picture_url' => $user->picture_url ?? null,
            'role' => $user->role ?? null,
        ];
    }
}
