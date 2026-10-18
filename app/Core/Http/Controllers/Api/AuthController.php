<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Models\User;
use App\Core\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    /**
     * Login user and create token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // Staff sign in with their mobile number, typed with or without spaces / +91
        $login = trim((string) $request->email);
        $digits = substr(preg_replace('/\D+/', '', $login), -10);
        $user = User::where('email', $login)
            ->orWhere('phone', $login)
            ->when(strlen($digits) === 10 && !str_contains($login, '@'), fn ($q) => $q->orWhereRaw(
                "RIGHT(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), 10) = ?",
                [$digits]
            ))
            ->get()
            ->first(fn (User $u) => Hash::check($request->password, $u->password));

        if (!$user) {
            return $this->error('Invalid credentials', 401);
        }

        if (!$user->is_active) {
            return $this->error('Your account has been deactivated. Please contact administrator.', 403);
        }

        // Update last login info
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Create token with abilities based on permissions
        $abilities = $user->getAllPermissions();
        $token = $user->createToken('auth-token', $abilities);

        // Load relationships
        $user->load(['roles.permissions', 'branches']);

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->pluck('slug'),
                'permissions' => $abilities,
                'branches' => $user->branches->map(fn($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'code' => $b->code,
                    'is_primary' => (bool) $b->pivot->is_primary,
                ]),
                'primary_branch_id' => $user->primaryBranch()?->id,
                'features' => self::features(),
            ],
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    /**
     * Logout user (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully');
    }

    /**
     * Logout from all devices (revoke all tokens).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return $this->success(null, 'Logged out from all devices');
    }

    /**
     * Get current authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['roles.permissions', 'branches']);

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roles->pluck('slug'),
            'permissions' => $user->getAllPermissions(),
            'branches' => $user->branches->map(fn($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'is_primary' => (bool) $b->pivot->is_primary,
            ]),
            'primary_branch_id' => $user->primaryBranch()?->id,
            'features' => self::features(),
        ]);
    }

    /**
     * Optional modules switched on for the organisation (menus and screens follow them).
     */
    private static function features(): array
    {
        return [
            'purchases' => \App\Modules\Workshop\Support\Purchases::enabled(),
            'accounts' => \App\Core\Support\Modules::accounts(),
            'crm' => \App\Core\Support\Modules::crm(),
            'hr' => \App\Core\Support\Modules::hr(),
            'customer_app' => \App\Core\Support\Modules::customerApp(),
        ];
    }

    /**
     * Refresh token (create new token and revoke old).
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        // Create new token
        $abilities = $user->getAllPermissions();
        $token = $user->createToken('auth-token', $abilities);

        return $this->success([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ], 'Token refreshed');
    }

    /**
     * Change password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->error('Current password is incorrect', 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Revoke all other tokens for security
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return $this->success(null, 'Password changed successfully');
    }
}
