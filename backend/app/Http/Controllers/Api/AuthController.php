<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        // Normalise first so "+233 24 123 4567" and "0241234567" count as the same number.
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => Phone::normalize($request->input('phone'))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\s\-]{9,16}$/', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create($data)->fresh(); // role defaults to "customer"; it is not mass assignable

        return response()->json(['data' => ['user' => $this->userPayload($user), 'token' => $user->createToken('web')->plainTextToken]], 201);
    }

    public function login(Request $request): JsonResponse
    {
        // "login" is an email address or a phone number. "email" is still accepted for older clients.
        $request->merge(['login' => $request->input('login', $request->input('email'))]);
        $data = $request->validate([
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        $login = trim($data['login']);
        $user = str_contains($login, '@')
            ? User::where('email', $login)->first()
            : User::where('phone', Phone::normalize($login))->first();

        // Same message for unknown user / wrong password / disabled account.
        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => ['These credentials do not match our records.']]);
        }

        return response()->json(['data' => ['user' => $this->userPayload($user), 'token' => $user->createToken('web')->plainTextToken]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    private function userPayload(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'phone', 'role']) + ['permissions' => $user->permissions()];
    }
}
