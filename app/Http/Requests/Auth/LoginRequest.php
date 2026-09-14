<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Services\Auth\TwoFactorService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            'code' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $user = Auth::user();

        $companyIsInactive = ! $user?->is_platform_admin
            && ($user?->company === null || $user->company->status !== CompanyStatus::Active);

        if ($user === null || $user->status !== UserStatus::Active || $companyIsInactive) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'This account is not active or has no company access.',
            ]);
        }

        if (! $user->hasActiveBranchAccess()) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages(['email' => 'Your assigned branch is inactive or has been removed. Ask your administrator to restore it or assign you to an active branch.']);
        }

        if ($user->two_factor_confirmed_at !== null && ! app(TwoFactorService::class)->verify($user, (string) $this->input('code'))) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages(['code' => 'Enter an unused authenticator code or recovery code.']);
        }
        RateLimiter::clear($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}
