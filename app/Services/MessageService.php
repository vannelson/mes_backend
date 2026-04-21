<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
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
            ->whereNull('group_id')
            ->groupBy('counterpart_id');

        $threads = Message::query()
            ->joinSub($latestMessageSub, 'latest', function ($join) {
                $join->on('messages.id', '=', 'latest.last_id');
            })
            ->with(['sender', 'recipient'])
            ->select('messages.*', 'latest.counterpart_id')
            ->orderByDesc('messages.created_at')
            ->get();

        $unreadBySender = Message::query()
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->whereNull('group_id')
            ->selectRaw('sender_id, COUNT(*) as unread_count')
            ->groupBy('sender_id')
            ->pluck('unread_count', 'sender_id');

        $directThreads = $threads->map(function (Message $message) use ($userId, $unreadBySender) {
            $counterpartId = (int) $message->counterpart_id;
            $counterpart = $message->sender_id === $userId ? $message->recipient : $message->sender;

            return [
                'type' => 'direct',
                'id' => 'direct-' . $counterpartId,
                'counterpart_id' => $counterpartId,
                'counterpart' => $this->serializeUser($counterpart),
                'last_message' => $this->serializeMessage($message, $userId),
                'unread_count' => (int) ($unreadBySender[$counterpartId] ?? 0),
            ];
        });

        $groupThreads = MessageGroup::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $userId))
            ->with([
                'participants',
                'messages' => fn ($query) => $query->with(['sender', 'recipient', 'group'])
                    ->latest()
                    ->limit(1),
            ])
            ->get()
            ->map(fn (MessageGroup $group) => $this->serializeGroupThread($group, $userId));

        $allThreads = $directThreads
            ->concat($groupThreads)
            ->sortByDesc(fn (array $thread) => $thread['last_message']['created_at'] ?? $thread['created_at'] ?? '')
            ->values();

        $items = $allThreads->forPage($page, $limit)->values();

        return new Paginator(
            $items,
            $allThreads->count(),
            $limit,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function listConversation(
        User $user,
        int $counterpartId,
        int $limit = 50,
        int $page = 1
    ): LengthAwarePaginator {
        $userId = $user->id;

        return Message::query()
            ->whereNull('group_id')
            ->where(function ($query) use ($userId, $counterpartId) {
                $query->where(function ($nested) use ($userId, $counterpartId) {
                    $nested->where('sender_id', $userId)
                        ->where('recipient_id', $counterpartId);
                })->orWhere(function ($nested) use ($userId, $counterpartId) {
                    $nested->where('sender_id', $counterpartId)
                        ->where('recipient_id', $userId);
                });
            })
            ->with(['sender', 'recipient'])
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function listGroupConversation(
        User $user,
        int $groupId,
        int $limit = 50,
        int $page = 1
    ): LengthAwarePaginator {
        $this->groupForUser($user, $groupId);

        return Message::query()
            ->where('group_id', $groupId)
            ->with(['sender', 'recipient', 'group'])
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function unreadCount(User $user): int
    {
        $directUnread = Message::query()
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('group_id')
            ->count();

        $groupUnread = MessageGroup::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->with('participants')
            ->get()
            ->sum(function (MessageGroup $group) use ($user) {
                $participant = $group->participants->firstWhere('id', $user->id);
                $lastReadAt = $participant?->pivot?->last_read_at;
                $query = Message::query()
                    ->where('group_id', $group->id)
                    ->where('sender_id', '!=', $user->id);

                if ($lastReadAt) {
                    $query->where('created_at', '>', $lastReadAt);
                }

                return $query->count();
            });

        return $directUnread + $groupUnread;
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

    public function createGroup(User $creator, string $name, array $memberIds): MessageGroup
    {
        $memberIds = collect($memberIds)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->push($creator->id)
            ->unique()
            ->values();

        return DB::transaction(function () use ($creator, $name, $memberIds) {
            $group = MessageGroup::query()->create([
                'name' => trim($name),
                'created_by' => $creator->id,
            ]);

            $group->participants()->sync($memberIds->all());

            return $group->load(['participants', 'creator']);
        });
    }

    public function sendGroup(User $sender, MessageGroup $group, string $body): Message
    {
        $message = Message::query()->create([
            'sender_id' => $sender->id,
            'recipient_id' => $sender->id,
            'group_id' => $group->id,
            'body' => $body,
        ]);

        DB::table('message_group_participants')
            ->where('message_group_id', $group->id)
            ->where('user_id', $sender->id)
            ->update(['last_read_at' => now(), 'updated_at' => now()]);

        $this->firebaseRealtimeService->publishMessageUpdate([
            'message_id' => $message->id,
            'sender_id' => $sender->id,
            'group_id' => $group->id,
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

    public function markGroupRead(User $user, int $groupId): int
    {
        $this->groupForUser($user, $groupId);

        return DB::table('message_group_participants')
            ->where('message_group_id', $groupId)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => now(), 'updated_at' => now()]);
    }

    public function serializeMessage(Message $message, int $currentUserId): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $message->sender_id,
            'recipient_id' => $message->recipient_id,
            'group_id' => $message->group_id,
            'created_at' => $message->created_at?->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
            'is_read' => $message->group_id
                ? true
                : ($message->recipient_id === $currentUserId
                ? (bool) $message->read_at
                : true),
            'sender' => $this->serializeUser($message->sender),
            'recipient' => $this->serializeUser($message->recipient),
            'group' => $this->serializeGroup($message->group),
        ];
    }

    public function serializeGroup(?MessageGroup $group): ?array
    {
        if (!$group) {
            return null;
        }

        return [
            'id' => $group->id,
            'name' => $group->name,
            'created_by' => $group->created_by,
            'created_at' => $group->created_at?->toIso8601String(),
            'participants' => $group->relationLoaded('participants')
                ? $group->participants->map(fn (User $user) => $this->serializeUser($user))->values()->all()
                : [],
        ];
    }

    protected function serializeGroupThread(MessageGroup $group, int $userId): array
    {
        $lastMessage = $group->messages->first();
        $participant = $group->participants->firstWhere('id', $userId);
        $lastReadAt = $participant?->pivot?->last_read_at;
        $unreadQuery = Message::query()
            ->where('group_id', $group->id)
            ->where('sender_id', '!=', $userId);

        if ($lastReadAt) {
            $unreadQuery->where('created_at', '>', $lastReadAt);
        }

        return [
            'type' => 'group',
            'id' => 'group-' . $group->id,
            'group_id' => $group->id,
            'group' => $this->serializeGroup($group),
            'created_at' => $group->created_at?->toIso8601String(),
            'last_message' => $lastMessage
                ? $this->serializeMessage($lastMessage, $userId)
                : null,
            'unread_count' => $unreadQuery->count(),
        ];
    }

    public function groupForUser(User $user, int $groupId): MessageGroup
    {
        return MessageGroup::query()
            ->whereKey($groupId)
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->with('participants')
            ->firstOrFail();
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
