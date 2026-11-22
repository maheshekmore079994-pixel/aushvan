<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SetUpShop;
use App\Domain\Shared\Audit;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetupRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Public: tells the screen whether to show first-run setup or login. */
    public function status(): JsonResponse
    {
        return response()->json([
            'needs_setup' => ! User::withTrashed()->exists(),
            'languages' => config('shop.languages'),
        ]);
    }

    public function setup(SetupRequest $request, SetUpShop $setUpShop): JsonResponse
    {
        if (User::withTrashed()->exists()) {
            return response()->json(['code' => 'already_set_up', 'message' => 'The shop is already set up.'], 409);
        }

        $owner = $setUpShop->handle($request->validated());

        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return response()->json($this->profile($owner), 201);
    }

    public function login(Request $request): JsonResponse
    {
        // Only the app's own front end (the shop PC, a device on the shop's network, a listed host) gets a
        // session; an unknown origin gets a plain refusal instead of a server error (D-058).
        if (! $request->hasSession()) {
            return response()->json([
                'code' => 'not_shop_network',
                'message' => 'Sign in from the shop PC or a device on the shop Wi-Fi.',
            ], 403);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:100'],
        ]);

        $username = strtolower(trim($data['username']));
        $throttleKey = 'login:'.$username.'|'.$request->ip();
        $maxAttempts = (int) config('shop.login_attempts_per_minute');

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return response()->json([
                'code' => 'too_many_attempts',
                'message' => 'Too many wrong attempts. Please wait and try again.',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        $user = User::where('username', $username)->first();

        // Same answer for unknown user, wrong password and disabled user.
        if ($user === null || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            Audit::log('auth.login_failed', $user, new: ['username' => $username], userId: $user?->id, storeId: $user?->store_id);

            throw ValidationException::withMessages(['username' => 'Wrong username or password.']);
        }

        RateLimiter::clear($throttleKey);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user);

        return response()->json($this->profile($user));
    }

    public function logout(Request $request): JsonResponse
    {
        Audit::log('auth.logout', $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function updateLocale(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(config('shop.languages'))]]);
        $request->user()->update($data);

        return response()->json($this->profile($request->user()));
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'locale' => $user->locale,
            'is_pharmacist' => $user->is_pharmacist,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->effectivePermissions(),
            'store' => [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'village' => $user->store->village,
            ],
        ];
    }
}
