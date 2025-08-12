<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Search users by name or email (excluding current user).
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->get('q', ''));
        $limit = min((int) $request->integer('limit', 10), 50);

        if ($search === '') {
            return response()->json(['data' => []]);
        }

        $users = User::query()
            ->select('id', 'name', 'email', 'avatar')
            ->where('id', '!=', $user->id) // Exclude current user
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })
            ->limit($limit)
            ->get()
            ->map(function (User $u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'avatar_url' => $u->avatar_url ?? null,
                ];
            });

        return response()->json(['data' => $users]);
    }
}
