<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\AuthEventType;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\AuthEvent;
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
            'current_password' => ['sometimes', 'nullable', 'string'],
        ]);

        // A leaked/stolen token shouldn't be enough to silently redirect
        // future logins to an attacker's own email - changing it requires
        // re-proving the password, same as changing the password itself
        // does. Name-only edits don't need this.
        $changingEmail = $validated['email'] !== $user->email;

        if ($changingEmail && ! Hash::check((string) ($validated['current_password'] ?? ''), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Enter your current password to change your email.'],
            ]);
        }

        $user->update(['name' => $validated['name'], 'email' => $validated['email']]);

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'profile_updated',
            'email_changed' => $changingEmail,
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

        AuthEvent::record(AuthEventType::PasswordChanged, user: $user, request: $request);

        return response()->json(['data' => ['message' => 'Password updated.']]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            // Not Laravel's generic `image` rule - that permits SVG, which
            // can carry an inline <script> and execute if its URL is ever
            // opened directly. An explicit raster-only mimes whitelist
            // closes that off; nothing here needs vector graphics.
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
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

        AuthEvent::record(
            $validated['enabled'] ? AuthEventType::TwoFactorEnabled : AuthEventType::TwoFactorDisabled,
            user: $user,
            request: $request,
        );

        return response()->json(['data' => new UserResource($user)]);
    }
}
