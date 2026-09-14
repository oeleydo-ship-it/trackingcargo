<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class TwoFactorService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(): string
    {
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    // RFC 4226 / RFC 6238: HMAC-SHA1, 30-second step, six digits.
    public function code(string $secret, int $step): string
    {
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $hash = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step % 4294967296), $key, true);
        $offset = ord($hash[19]) & 15;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function matchingStep(string $secret, string $code): ?int
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return null;
        }
        $step = intdiv(now()->timestamp, 30);
        foreach ([$step, $step - 1, $step + 1] as $candidate) {
            if (hash_equals($this->code($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    public function verify(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($locked->two_factor_confirmed_at === null || ! $locked->two_factor_secret) {
                return false;
            }
            $step = $this->matchingStep(Crypt::decryptString($locked->two_factor_secret), trim($code));
            if ($step !== null && ($locked->two_factor_last_step === null || $step > $locked->two_factor_last_step)) {
                $locked->forceFill(['two_factor_last_step' => $step])->save();

                return true;
            }
            $codes = json_decode($locked->two_factor_recovery_codes ?? '[]', true);
            foreach ($codes as $index => $hash) {
                if (hash_equals($hash, hash('sha256', trim($code)))) {
                    unset($codes[$index]);
                    $locked->forceFill(['two_factor_recovery_codes' => json_encode(array_values($codes))])->save();

                    return true;
                }
            }

            return false;
        });
    }

    public function recoveryCodes(User $user): array
    {
        $codes = array_map(fn () => bin2hex(random_bytes(10)), range(1, 8));
        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_map(fn ($code) => hash('sha256', $code), $codes))])->save();

        return $codes;
    }
}
