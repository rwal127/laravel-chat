<?php

declare(strict_types=1);

namespace App\Http\Controllers\Broadcasting;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Contact;
use Pusher\Pusher;

final class UserAuthController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $socketId = (string) $request->input('socket_id', '');
        if ($socketId === '') {
            return response()->json(['message' => 'Missing socket_id'], 422);
        }

        $userId = (string) $user->getAuthIdentifier();

        // Build watchlist (limit to 100 per Pusher default)
        $watchlist = [];
        try {
            // Collect contacts and conversation participant user IDs
            $contactIds = method_exists($user, 'contacts')
                ? $user->contacts()->pluck('contact_user_id')->all()
                : [];
            // Include reciprocal contacts (users who added me)
            $reciprocalContactIds = Contact::query()
                ->where('contact_user_id', $user->getAuthIdentifier())
                ->pluck('user_id')
                ->all();

            $participantIds = method_exists($user, 'conversations')
                ? $user->conversations()
                    ->with('participants')
                    ->get()
                    ->flatMap(fn ($c) => $c->participants->pluck('id'))
                    ->unique()
                    ->values()
                    ->all()
                : [];

            $ids = collect($contactIds)
                ->merge($reciprocalContactIds)
                ->merge($participantIds)
                ->filter(fn ($id) => (string) $id !== $userId)
                ->unique()
                ->take(100)
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();

            $watchlist = $ids;
        } catch (\Throwable $e) {
            // Fallback to empty watchlist on error
            $watchlist = [];
        }

        // Pull credentials and options from config (env() may be null when config is cached)
        $key = (string) (config('broadcasting.connections.pusher.key') ?? '');
        $secret = (string) (config('broadcasting.connections.pusher.secret') ?? '');
        $appId = (string) (config('broadcasting.connections.pusher.app_id') ?? '');
        $options = (array) (config('broadcasting.connections.pusher.options') ?? []);
        if ($key === '' || $secret === '' || $appId === '') {
            Log::error('Pusher configuration missing', [
                'key_empty' => $key === '',
                'secret_empty' => $secret === '',
                'app_id_empty' => $appId === '',
            ]);
            return response()->json(['message' => 'Pusher credentials are not configured.'], 500);
        }

        $pusher = new Pusher($key, $secret, $appId, $options);

        $userInfo = [
            'name' => (string) ($user->name ?? ''),
            'avatar_url' => method_exists($user, 'getAvatarUrlAttribute') ? (string) $user->avatar_url : (string) ($user->avatar_url ?? ''),
        ];

        Log::info('Pusher UserAuth watchlist', [
            'user_id' => $userId,
            'socket_id' => $socketId,
            'watchlist_count' => is_array($watchlist) ? count($watchlist) : 0,
            'watchlist' => $watchlist,
        ]);

        $auth = $pusher->authenticateUser($socketId, [
            'id' => $userId,
            'user_info' => $userInfo,
            'watchlist' => $watchlist,
        ]);

        return response($auth, 200, ['Content-Type' => 'application/json']);
    }
}
