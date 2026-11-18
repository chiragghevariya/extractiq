<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Two ways in, one endpoint:
 *  - the web app (a SANCTUM_STATEFUL_DOMAINS origin) gets a session cookie — Sanctum SPA auth,
 *    after fetching /sanctum/csrf-cookie;
 *  - any other client (curl, Swagger, integrations) gets a personal access token in meta.token.
 */
class AuthController extends Controller
{
    /**
     * Public demo (DEMO_MODE=true): sign in as the read-only demo visitor without a password.
     * The visitor sees everything an admin sees; DemoReadOnly rejects every change.
     */
    public function demo(Request $request)
    {
        abort_unless(config('extractiq.demo.enabled'), 404);
        $user = User::where('email', config('extractiq.demo.email'))->where('is_demo', true)->first();
        abort_unless($user !== null, 503, 'The demo is being reset. Try again in a minute.');

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return (new UserResource($user->load('company')))->additional(['meta' => ['auth' => 'session', 'demo' => true]]);
        }

        return (new UserResource($user->load('company')))->additional(['meta' => [
            'auth' => 'token', 'demo' => true,
            'token' => $user->createToken('demo', ['*'], now()->addHours(2))->plainTextToken,
        ]]);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'device' => 'nullable|string|max:60']);

        if ($request->hasSession()) {
            if (! Auth::guard('web')->attempt(['email' => $data['email'], 'password' => $data['password']])) {
                throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
            }
            $request->session()->regenerate();

            /** @var User $user */
            $user = Auth::guard('web')->user();

            return (new UserResource($user->load('company')))->additional(['meta' => ['auth' => 'session']]);
        }

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        return (new UserResource($user->load('company')))->additional(['meta' => [
            'auth' => 'token',
            'token' => $user->createToken($data['device'] ?? 'api')->plainTextToken,
        ]]);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user()->load('company'));
    }

    public function logout(Request $request)
    {
        // Session (SPA) requests carry a TransientToken; only real API tokens are deleted.
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
