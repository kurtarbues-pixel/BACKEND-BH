<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $role = Role::where('name', $request->validated('role'))->firstOrFail();

        $user = User::create([
            'name' => $request->validated('name'),
            'username' => Str::lower($request->validated('username')),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => $request->validated('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $user->load('role');
        $newToken = $user->createToken('api');
        $newToken->accessToken->forceFill(['last_activity_at' => now()])->save();
        $token = $newToken->plainTextToken;

        ActivityLogger::log('auth.register', $user, [], $user);

        return response()->json([
            'user' => $user->toAuthArray(),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $identifier = $request->validated('identifier') ?: $request->validated('email');
        $user = User::with('role')->where(function ($query) use ($identifier) {
            $query->where('email', $identifier)->orWhere('username', Str::lower($identifier));
        })->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        $user->tokens()->delete();
        $newToken = $user->createToken('api');
        $newToken->accessToken->forceFill(['last_activity_at' => now()])->save();
        $token = $newToken->plainTextToken;

        ActivityLogger::log('auth.login', $user, [], $user);

        return response()->json([
            'user' => $user->toAuthArray(),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        ActivityLogger::log('auth.logout');

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        $request->user()->load('role');

        return response()->json(['user' => $request->user()->toAuthArray()]);
    }

    public function updateProfile(Request $request)
    {
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^\pL+(?:[ .\x27-]\pL+\.?)*$/u'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{2,49}$/', Rule::unique('users', 'username')->ignore($request->user()->id)],
            'email' => ['required', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($request->user()->id)],
            'phone' => ['nullable', 'string', 'max:25'],
        ]);
        $request->user()->update($data);
        ActivityLogger::log('profile.updated', $request->user());

        return response()->json(['user' => $request->user()->fresh('role')->toAuthArray()]);
    }

    public function deleteAccount(Request $request)
    {
        $data = $request->validate(['current_password' => ['required', 'current_password']]);
        $user = $request->user();

        if ($user->rooms()->exists() || $user->tenantLeases()->exists() || $user->landlordLeases()->exists() || $user->inquiries()->exists()) {
            throw ValidationException::withMessages([
                'account' => 'This account has room, application, or lease records that must be kept. Contact an administrator to close this account safely.',
            ]);
        }

        if ($user->isAdmin() && User::where('role_id', $user->role_id)->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages([
                'account' => 'The last active administrator account cannot be deleted.',
            ]);
        }

        ActivityLogger::log('account.deleted', null, [], $user);
        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);
        $request->user()->update(['password' => $data['password']]);
        $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();
        ActivityLogger::log('profile.password_changed', $request->user());

        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function sendPasswordResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);
        $status = Password::sendResetLink($request->only('email'));
        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'If an account exists for that email, a password reset link has been sent.']);
        }
        if ($status === Password::RESET_THROTTLED) {
            return response()->json(['message' => 'Please wait before requesting another reset link.'], 429);
        }

        // Avoid revealing whether the email belongs to an account.
        return response()->json(['message' => 'If an account exists for that email, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Password reset successfully. You can now sign in.']);
    }
}
