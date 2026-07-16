<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($validated);

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'profile_updated',
        ]);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $validated['password']]);

        // Changing the password should actually sign out other
        // sessions/devices - keep only the token used for this request.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'password_changed',
        ]);

        return response()->json(['data' => ['message' => 'Password updated.']]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'avatar' => ['required', 'image', 'max:4096'],
        ]);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'avatar_updated',
        ]);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function toggleTwoFactor(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $user->update(['two_factor_enabled' => $validated['enabled']]);

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'two_factor_toggled',
            'enabled' => $validated['enabled'],
        ]);

        return response()->json(['data' => new UserResource($user)]);
    }
}
