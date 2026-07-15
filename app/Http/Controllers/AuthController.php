<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\UserRole;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::once($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $user->createToken('dashboard')->plainTextToken;

        ActivityLog::record(ActivityType::UserLoggedIn, subject: $user, userId: $user->id);

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * Passwordless sign-in as the first user of a role, for development.
     *
     * Gated on `skillleo.dev_quick_login`; 404s when off so the endpoint isn't
     * discoverable in a normal deployment. Resolving the user server-side is
     * what keeps real passwords out of the JS bundle.
     */
    public function devLogin(Request $request): JsonResponse
    {
        abort_unless(config('skillleo.dev_quick_login'), 404);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::enum(UserRole::class)],
        ]);

        $role = UserRole::from($validated['role']);

        $user = User::where('role', $role)->oldest('id')->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'role' => ["No {$role->value} user exists to sign in as."],
            ]);
        }

        $token = $user->createToken('dashboard')->plainTextToken;

        ActivityLog::record(ActivityType::UserLoggedIn, subject: $user, userId: $user->id, meta: [
            'via' => 'dev_quick_login',
        ]);

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }
}
