<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MessageService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MessageController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected MessageService $messageService
    ) {
    }

    public function threads(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        $page = (int) $request->query('page', 1);

        try {
            $threads = $this->messageService->listThreads($request->user(), $limit, $page);

            return $this->successPagination('Message threads retrieved.', $threads);
        } catch (Throwable $e) {
            return $this->error('Failed to load message threads.', 500);
        }
    }

    public function conversation(Request $request, int $userId): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);
        $page = (int) $request->query('page', 1);
        $currentUser = $request->user();

        try {
            $messages = $this->messageService->listConversation($currentUser, $userId, $limit, $page);
            $messages->getCollection()->transform(function ($message) use ($currentUser) {
                return $this->messageService->serializeMessage($message, $currentUser->id);
            });

            return $this->successPagination('Conversation retrieved.', $messages);
        } catch (Throwable $e) {
            return $this->error('Failed to load conversation.', 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $sender = $request->user();
        $recipientId = (int) $data['recipient_id'];
        if ($sender->id === $recipientId) {
            return $this->error('Cannot message yourself.', 422);
        }
        $body = trim($data['body']);
        if ($body === '') {
            return $this->error('Message body is required.', 422);
        }

        try {
            $recipient = User::query()->findOrFail($recipientId);
            $message = $this->messageService->send($sender, $recipient, $body);
            $message->load(['sender', 'recipient']);

            return $this->success('Message sent.', $this->messageService->serializeMessage($message, $sender->id));
        } catch (Throwable $e) {
            return $this->error('Failed to send message.', 500);
        }
    }

    public function unreadCount(Request $request): JsonResponse
    {
        try {
            $count = $this->messageService->unreadCount($request->user());

            return $this->success('Unread message count retrieved.', [
                'unread_count' => $count,
            ]);
        } catch (Throwable $e) {
            return $this->error('Failed to load unread count.', 500);
        }
    }

    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sender_id' => ['nullable', 'integer', 'exists:users,id'],
            'ids' => ['array'],
            'ids.*' => ['integer'],
            'all' => ['boolean'],
        ]);

        try {
            $updated = $this->messageService->markRead(
                $request->user(),
                $data['ids'] ?? [],
                $data['sender_id'] ?? null,
                (bool) ($data['all'] ?? false)
            );

            return $this->success('Messages marked as read.', [
                'updated' => $updated,
            ]);
        } catch (Throwable $e) {
            return $this->error('Failed to mark messages read.', 500);
        }
    }
}
