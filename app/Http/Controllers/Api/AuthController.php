<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController extends Controller
{
    /**
     * Login with email/password and return a bearer token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        if (! Auth::validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }
        /** @var \App\Models\User $user */
        $user = \App\Models\User::where('email', $credentials['email'])->firstOrFail();
        $tokenName = $credentials['device_name'] ?? ($request->userAgent() ?: 'api');
        $token = $user->createToken($tokenName);

        return response()->json([
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
            ],
        ], 201);
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        // Require a valid Bearer token to avoid session-authenticated access
        $plain = (string) $request->bearerToken();
        if ($plain === '' || ! PersonalAccessToken::findToken($plain)) {
            return response()->json(['message' => __('Unauthorized')], 401);
        }
        /** @var \App\Models\User $user */
        $user = $request->user();
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        $revoked = false;
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
            $revoked = true;
        }

        if (! $revoked) {
            // Try to find and delete by bearer token string
            $plain = (string) $request->bearerToken();
            if ($plain !== '') {
                $pat = PersonalAccessToken::findToken($plain);
                if ($pat) {
                    $pat->delete();
                    $revoked = true;
                }
            }
        }

        if (! $revoked && $request->user()) {
            // Fallback: revoke all tokens for the user
            $request->user()->tokens()->delete();
        }
        return response()->json(['message' => __('Logged out')]);
    }
}
