<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\ConversationStoreRequest;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * List dialogs for the authenticated user with unread counts and sorting by last message.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = (int) $request->integer('per_page', 15);
        $search = trim((string) $request->get('search', ''));

        // Base: conversations where the user participates
        $query = Conversation::query()
            ->select('conversations.*')
            ->join('conversation_participants as cp', 'cp.conversation_id', '=', 'conversations.id')
            ->where('cp.user_id', $user->id)
            // Compute unread_count via subquery
            ->addSelect([
                'unread_count' => Message::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.user_id', '!=', $user->id)
                    ->when(true, function ($q) use ($user) {
                        // join participant for last_read_at
                        $q->leftJoin('conversation_participants as cp2', function ($j) use ($user) {
                            $j->on('cp2.conversation_id', '=', 'messages.conversation_id')
                              ->where('cp2.user_id', '=', $user->id);
                        });
                        $q->where(function ($w) {
                            $w->whereNull('cp2.last_read_at')
                              ->orWhereColumn('messages.created_at', '>', 'cp2.last_read_at');
                        });
                    })
                    ->toBase()
            ])
            ->with([
                'participants:id,name,avatar',
                'lastMessage.user:id,name,avatar',
            ])
            ->orderByDesc(DB::raw('COALESCE(conversations.updated_at, conversations.created_at)'));

        if ($search !== '') {
            // Search within participants' names for human-friendly dialog search
            $query->whereExists(function ($sub) use ($search) {
                $sub->select(DB::raw(1))
                    ->from('conversation_participants as cps')
                    ->join('users as u', 'u.id', '=', 'cps.user_id')
                    ->whereColumn('cps.conversation_id', 'conversations.id')
                    ->where('u.name', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        $data = $paginator->getCollection()->map(function (Conversation $conv) use ($user) {
            $otherUser = null;
            if ($conv->type === 'direct') {
                $otherUser = $conv->participants->firstWhere('id', '!=', $user->id);
            }

            return [
                'id' => $conv->id,
                'type' => $conv->type,
                // keep both keys for frontend compatibility
                'user' => $otherUser ? [
                    'id' => $otherUser->id,
                    'name' => $otherUser->name,
                    'avatar_url' => $otherUser->avatar_url ?? null,
                ] : null,
                'other_user' => $otherUser ? [
                    'id' => $otherUser->id,
                    'name' => $otherUser->name,
                    'avatar_url' => $otherUser->avatar_url ?? null,
                ] : null,
                'name' => $conv->name, // For group chats
                'last_message' => $conv->lastMessage,
                'unread_count' => (int) ($conv->unread_count ?? 0),
                'updated_at' => $conv->updated_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Create a conversation (direct or group) and add participants.
     */
    public function store(ConversationStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($data['type'] === 'direct') {
            $otherId = (int) $data['user_id'];
            if ($otherId === (int) $user->id) {
                return response()->json(['message' => __('Cannot create a direct conversation with yourself.')], 422);
            }

            // Find existing direct conversation between both users
            $existing = Conversation::query()
                ->where('type', 'direct')
                ->whereExists(function ($q) use ($user) {
                    $q->select(DB::raw(1))
                      ->from('conversation_participants as cp1')
                      ->whereColumn('cp1.conversation_id', 'conversations.id')
                      ->where('cp1.user_id', $user->id);
                })
                ->whereExists(function ($q) use ($otherId) {
                    $q->select(DB::raw(1))
                      ->from('conversation_participants as cp2')
                      ->whereColumn('cp2.conversation_id', 'conversations.id')
                      ->where('cp2.user_id', $otherId);
                })
                ->first();

            if ($existing) {
                return response()->json(['id' => $existing->id], 200);
            }

            $conv = DB::transaction(function () use ($user, $otherId) {
                $conv = Conversation::create(['type' => 'direct']);
                ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $user->id, 'role' => 'member']);
                ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $otherId, 'role' => 'member']);
                return $conv;
            });

            return response()->json(['id' => $conv->id], 201);
        }

        // Group conversation
        $participants = collect($data['participants'] ?? [])->unique()->filter(fn ($id) => (int) $id !== (int) $user->id)->values();
        $conv = DB::transaction(function () use ($user, $data, $participants) {
            $conv = Conversation::create([
                'type' => 'group',
                'name' => $data['name'] ?? null,
            ]);
            // Add creator
            ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $user->id, 'role' => 'admin']);
            // Add others
            foreach ($participants as $pid) {
                ConversationParticipant::firstOrCreate(['conversation_id' => $conv->id, 'user_id' => (int) $pid], ['role' => 'member']);
            }
            return $conv;
        });

        return response()->json(['id' => $conv->id], 201);
    }

    /**
     * Show a conversation (basic details) if the user participates.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            return response()->json(['message' => __('Forbidden')], 403);
        }

        $conversation->load([
            'participants:id,name,avatar',
        ]);

        return response()->json([
            'id' => $conversation->id,
            'type' => $conversation->type,
            'name' => $conversation->name,
            'participants' => $conversation->participants->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'avatar_url' => $u->avatar_url ?? null,
                ];
            }),
        ]);
    }
}
