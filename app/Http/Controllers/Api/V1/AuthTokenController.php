<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApiLoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Auth\TwoFactorService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthTokenController extends Controller
{
    public function store(ApiLoginRequest $request, TenantContext $context, AuditService $audit): JsonResponse
    {
        $key = 'api-login:'.Str::lower($request->string('email')->toString()).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many login attempts. Try again later.']);
        }

        $user = User::query()->where('email', $request->string('email')->toString())->first();
        $valid = $user !== null
            && Hash::check($request->string('password')->toString(), $user->password)
            && $user->status === UserStatus::Active
            && $user->hasActiveBranchAccess()
            && ($user->is_platform_admin || ($user->company !== null && $user->company->status === CompanyStatus::Active));

        if (! $valid) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'The provided credentials are invalid.']);
        }

        if ($user->two_factor_confirmed_at !== null && ! app(TwoFactorService::class)->verify($user, (string) $request->input('code'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['code' => 'Enter an unused authenticator code or recovery code.']);
        }
        RateLimiter::clear($key);

        if ($user->is_platform_admin) {
            $context->resolvePlatformBypass();
        } else {
            $context->resolveCompany((int) $user->company_id);
        }

        try {
            $abilities = $user->is_platform_admin ? ['*'] : $user->permissionSlugs();
            $token = $user->createToken(
                $request->string('device_name')->toString(),
                $abilities,
                now()->addDays((int) config('sanctum.expiration_days', 30)),
            );

            $audit->record('api.token.created', $user, $user, newValues: ['token_id' => $token->accessToken->getKey(), 'device_name' => $request->string('device_name')->toString()]);

            return response()->json([
                'data' => [
                    'token' => $token->plainTextToken,
                    'token_type' => 'Bearer',
                    'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                    'user' => (new UserResource($user))->resolve($request),
                    'abilities' => $abilities,
                ],
            ], 201);
        } finally {
            $context->forget();
        }
    }

    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function destroy(Request $request, AuditService $audit): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $audit->record('api.token.revoked', $user, $user, oldValues: ['token_id' => $token?->getKey()]);
        $token?->delete();

        return response()->json(null, 204);
    }
}
