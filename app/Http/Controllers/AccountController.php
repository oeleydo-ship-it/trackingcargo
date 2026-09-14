<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Audit\AuditService;
use App\Services\Auth\TwoFactorService;
use App\Services\Shipments\BarcodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class AccountController extends Controller
{
    public function show(Request $request)
    {
        Inertia::encryptHistory();
        $user = $request->user();
        $pending = $request->session()->get('two_factor_setup');
        if ($pending && ($pending['expires'] < now()->timestamp || $pending['user_id'] !== $user->id)) {
            $request->session()->forget('two_factor_setup');
            $pending = null;
        }
        $setupSecret = $pending ? Crypt::decryptString($pending['secret']) : null;
        $uri = $pending ? 'otpauth://totp/'.rawurlencode('CargoFlow:'.$user->email).'?secret='.$setupSecret.'&issuer=CargoFlow&algorithm=SHA1&digits=6&period=30' : null;

        return Inertia::render('Account/Index', [
            'profile' => $user->only(['name', 'email', 'phone']),
            'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
            'setupSecret' => $setupSecret,
            'setupQr' => $uri ? 'data:image/svg+xml;base64,'.base64_encode(app(BarcodeService::class)->qrSvg($uri)) : null,
            'recoveryCodes' => $request->session()->pull('account_recovery_codes', []),
            'newToken' => $request->session()->pull('account_new_token'),
            'tokens' => $user->tokens()->latest()->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at']),
            'sessions' => config('session.driver') === 'database' ? DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity'])->map(fn ($session) => ['id' => $session->id, 'ip' => $session->ip_address, 'agent' => $session->user_agent, 'lastActivity' => $session->last_activity, 'current' => $session->id === $request->session()->getId()]) : [],
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function update(Request $request, TwoFactorService $twoFactor, AuditService $audit)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'new_password' => ['nullable', 'confirmed', Password::min(12)->letters()->numbers()]]);
        $this->reauthenticate($request, $twoFactor);
        $user = $request->user();
        $user->fill(['name' => $data['name'], 'phone' => $data['phone'] ?? null]);
        if (! empty($data['new_password'])) {
            $user->password = $data['new_password'];
            $user->remember_token = Str::random(60);
            $user->tokens()->delete();
            $this->removeOtherSessions($request);
        }
        $user->save();
        $audit->record('account.updated', $user, $user, newValues: ['password_changed' => ! empty($data['new_password'])]);

        return back()->with('success', 'Account updated.');
    }

    public function security(Request $request, string $action, TwoFactorService $twoFactor, AuditService $audit)
    {
        abort_unless(in_array($action, ['enable', 'confirm', 'cancel', 'disable', 'recovery', 'sessions']), 404);
        $this->reauthenticate($request, $twoFactor);
        $user = $request->user();
        if ($action === 'enable') {
            abort_if($user->two_factor_confirmed_at !== null, 409);
            $request->session()->put('two_factor_setup', ['secret' => Crypt::encryptString($twoFactor->secret()), 'expires' => now()->addMinutes(10)->timestamp, 'user_id' => $user->id]);
        } elseif ($action === 'confirm') {
            $data = $request->validate(['setup_code' => ['required', 'string', 'size:6']]);
            $pending = $request->session()->get('two_factor_setup');
            $step = $pending && $pending['user_id'] === $user->id && $pending['expires'] >= now()->timestamp ? $twoFactor->matchingStep(Crypt::decryptString($pending['secret']), $data['setup_code']) : null;
            if ($step === null || $user->two_factor_confirmed_at !== null) {
                throw ValidationException::withMessages(['setup_code' => 'Invalid or expired setup code. Start setup again if necessary.']);
            }
            DB::transaction(function () use ($user, $pending, $step, $twoFactor, $request): void {
                $user->forceFill(['two_factor_secret' => $pending['secret'], 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();
                $request->session()->flash('account_recovery_codes', $twoFactor->recoveryCodes($user));
                $user->tokens()->delete();
                $user->forceFill(['remember_token' => Str::random(60)])->save();
                $this->removeOtherSessions($request);
            });
            $request->session()->forget('two_factor_setup');
        } elseif ($action === 'cancel') {
            $request->session()->forget('two_factor_setup');
        } elseif ($action === 'disable') {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        } elseif ($action === 'recovery') {
            abort_unless($user->two_factor_confirmed_at !== null, 409);
            $request->session()->flash('account_recovery_codes', $twoFactor->recoveryCodes($user));
        } elseif ($action === 'sessions') {
            $this->removeOtherSessions($request);
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }
        $audit->record('account.security.'.$action, $user, $user);

        return back()->with('success', 'Security settings updated.');
    }

    public function token(Request $request, TwoFactorService $twoFactor, AuditService $audit)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'days' => ['required', 'integer', 'min:1', 'max:365']]);
        $this->reauthenticate($request, $twoFactor);
        $user = $request->user();
        $token = $user->createToken($data['name'], $user->permissionSlugs(), now()->addDays($data['days']));
        $audit->record('api.token.created', $user, $user, newValues: ['token_id' => $token->accessToken->id]);

        return back()->with('account_new_token', $token->plainTextToken)->with('success', 'Token created. Copy it now; it is shown once.');
    }

    public function revoke(Request $request, string $token, TwoFactorService $twoFactor, AuditService $audit)
    {
        $owned = $request->user()->tokens()->findOrFail($token);
        $this->reauthenticate($request, $twoFactor);
        $owned->delete();
        $audit->record('api.token.revoked', $request->user(), $request->user(), oldValues: ['token_id' => $owned->id]);

        return back()->with('success', 'Token revoked.');
    }

    private function reauthenticate(Request $request, TwoFactorService $twoFactor): void
    {
        $request->validate(['password' => ['required', 'current_password'], 'code' => ['nullable', 'string', 'max:64']]);
        if ($request->user()->two_factor_confirmed_at !== null && ! $twoFactor->verify($request->user(), (string) $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'Enter an unused authenticator code or recovery code.']);
        }
    }

    private function removeOtherSessions(Request $request): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        }
    }
}
