<?php

namespace App\Services\Appointments\PublicBooking;

use App\Enums\CardholderStatus;
use App\Enums\LoginEventType;
use App\Models\Cardholder;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Services\Audit\AuditService;
use App\Services\Notifications\NotificationService;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Who a person is on the public booking link (5.6): their employee number, then the organization's method — a
 * one-time code sent to the e-mail/phone on their record (whatever the notification settings), their date of birth
 * or their national ID number — within the organization's minutes and tries. The answer to an employee number is
 * the same whether or not it is known, active or has an address on record, so the link tells nobody who works
 * there. Proven, they get a session for some minutes (renewed by each use). Codes and tokens are kept only hashed,
 * in the cache, and expire by themselves. An employee number may be asked for a few times an hour and may give the
 * organization's number of wrong answers in a day (over all its codes) — known or not, alike. The audit's sign-ins
 * keep each one proven, locked out (no tries left) and blocked (too many tries for a number) — not each wrong
 * answer.
 */
class PublicIdentityService
{
    private const PREFIX = 'public-booking:';

    public function __construct(
        private TenantContext $tenant,
        private CardholderRepositoryInterface $cardholders,
        private NotificationService $notifications,
        private AuditService $audit,
        private Request $request,
    ) {}

    /**
     * Start proving who someone is: a challenge for the organization's method (a code goes out by its channels).
     *
     * @return array{challenge: string, method: string, channels: array<int, string>, expires_in: int}
     */
    public function identify(string $employeeNo): array
    {
        $tenant = $this->tenant->get();
        $verification = $tenant->appointmentSettings()['verification'];
        $limiter = self::PREFIX."identify:{$tenant->id}:".mb_strtolower(trim($employeeNo));
        $this->refuseAfterFailures($employeeNo, (int) $verification['daily_failures'], 'employee_no');
        if (RateLimiter::tooManyAttempts($limiter, (int) config('appointments.public.identify_per_hour'))) {
            $this->audit->recordPublicSignIn(LoginEventType::Blocked, $this->request, $tenant, trim($employeeNo), $this->cardholders->findByEmployeeNo(trim($employeeNo)));
            throw ValidationException::withMessages(['employee_no' => __('appointments.public.too_many', ['minutes' => app(ReportValueText::class)->number((int) ceil(RateLimiter::availableIn($limiter) / 60))])]);
        }
        RateLimiter::hit($limiter, 3600);

        $cardholder = $this->cardholders->findByEmployeeNo(trim($employeeNo));
        $person = $cardholder !== null && $cardholder->status === CardholderStatus::Active ? $cardholder : null;
        $minutes = (int) $verification['code_minutes'];
        $code = null;
        if ($verification['method'] === 'code' && $person !== null) {
            $code = $this->newCode();
            foreach ($verification['code_channels'] as $channel) {
                $this->notifications->sendBookingCode($person, $channel, $code, $minutes);
            }
        }

        $challenge = Str::random(40);
        $expires = now()->addMinutes($minutes);
        Cache::put($this->key('challenge', $challenge), [
            'tenant' => $tenant->id, 'cardholder' => $person?->id, 'employee_no' => trim($employeeNo), 'method' => $verification['method'],
            'code' => $code === null ? null : hash('sha256', $code), 'tries' => 0, 'expires' => $expires->getTimestamp(),
        ], $expires);

        return ['challenge' => $challenge, 'method' => $verification['method'], 'channels' => $verification['code_channels'], 'expires_in' => $minutes * 60];
    }

    /**
     * Check the answer (`code`, `date_of_birth` Y-m-d or `national_id`) to a challenge: a session token for the
     * person when right; else why not, with the tries left — none left, or expired, starts again.
     *
     * @param  array{code?: string|null, date_of_birth?: string|null, national_id?: string|null}  $answer
     * @return array{token: string, expires_in: int, person: Cardholder}
     */
    public function verify(string $challenge, array $answer): array
    {
        $tenant = $this->tenant->get();
        $key = $this->key('challenge', $challenge);
        $held = Cache::get($key);
        if (! is_array($held) || $held['tenant'] !== $tenant->id || $held['expires'] <= now()->getTimestamp()) {
            throw ValidationException::withMessages(['challenge' => __('appointments.public.expired')]);
        }
        $person = $held['cardholder'] === null ? null : $this->cardholders->findById($held['cardholder']);
        $employeeNo = (string) ($held['employee_no'] ?? $person?->employee_no);
        if ($person === null || $person->status !== CardholderStatus::Active || ! $this->answers($held, $person, $answer)) {
            RateLimiter::hit($this->failuresKey($employeeNo), 86400);
            try {
                $this->refuseAfterFailures($employeeNo, (int) $tenant->appointmentSettings()['verification']['daily_failures'], 'challenge');
            } catch (ValidationException $refused) {
                Cache::forget($key);

                throw $refused;
            }
            $tries = $held['tries'] + 1;
            $left = (int) $tenant->appointmentSettings()['verification']['code_attempts'] - $tries;
            if ($left <= 0) {
                Cache::forget($key);
                $this->audit->recordPublicSignIn(LoginEventType::Locked, $this->request, $tenant, $employeeNo, $person);
                throw ValidationException::withMessages(['challenge' => __('appointments.public.no_tries_left')]);
            }
            // The same expiry: only the tries change
            Cache::put($key, [...$held, 'tries' => $tries], CarbonImmutable::createFromTimestamp($held['expires']));
            throw ValidationException::withMessages([$this->field($held['method']) => trans_choice('appointments.public.wrong', $left, ['count' => app(ReportValueText::class)->number($left)])]);
        }

        Cache::forget($key);
        // The person proved who they are: earlier slips of theirs no longer count
        RateLimiter::clear($this->failuresKey($employeeNo));
        $this->audit->recordPublicSignIn(LoginEventType::Succeeded, $this->request, $tenant, $employeeNo, $person);
        $token = Str::random(48);
        $minutes = (int) config('appointments.public.session_minutes');
        Cache::put($this->key('session', $token), ['tenant' => $tenant->id, 'cardholder' => $person->id], now()->addMinutes($minutes));

        return ['token' => $token, 'expires_in' => $minutes * 60, 'person' => $person];
    }

    /**
     * Refuse a number that has given the organization's number of wrong answers in the last day (kept in the
     * sign-ins as blocked), whether or not it is on record.
     */
    private function refuseAfterFailures(string $employeeNo, int $limit, string $field): void
    {
        $key = $this->failuresKey($employeeNo);
        if (! RateLimiter::tooManyAttempts($key, $limit)) {
            return;
        }
        $tenant = $this->tenant->get();
        $this->audit->recordPublicSignIn(LoginEventType::Blocked, $this->request, $tenant, trim($employeeNo), $this->cardholders->findByEmployeeNo(trim($employeeNo)));

        throw ValidationException::withMessages([$field => __('appointments.public.too_many_hours', ['hours' => app(ReportValueText::class)->number(max(1, (int) ceil(RateLimiter::availableIn($key) / 3600)))])]);
    }

    /**
     * Wrong answers of one employee number in this organization (any case, spaces around ignored).
     */
    private function failuresKey(string $employeeNo): string
    {
        return self::PREFIX."failures:{$this->tenant->id()}:".mb_strtolower(trim($employeeNo));
    }

    /**
     * The person a session token stands for in this organization, renewing its minutes; null when unknown or over.
     */
    public function person(?string $token): ?Cardholder
    {
        if ($token === null || $token === '') {
            return null;
        }
        $key = $this->key('session', $token);
        $held = Cache::get($key);
        if (! is_array($held) || $held['tenant'] !== $this->tenant->id()) {
            return null;
        }
        $person = $this->cardholders->findById($held['cardholder']);
        if ($person === null || $person->status !== CardholderStatus::Active) {
            Cache::forget($key);

            return null;
        }
        Cache::put($key, $held, now()->addMinutes((int) config('appointments.public.session_minutes')));

        return $person;
    }

    public function signOut(string $token): void
    {
        Cache::forget($this->key('session', $token));
    }

    /**
     * @param  array{method: string, code: string|null}  $held
     * @param  array{code?: string|null, date_of_birth?: string|null, national_id?: string|null}  $answer
     */
    private function answers(array $held, Cardholder $person, array $answer): bool
    {
        return match ($held['method']) {
            'code' => $held['code'] !== null && is_string($answer['code'] ?? null) && hash_equals($held['code'], hash('sha256', self::bare($answer['code']))),
            'date_of_birth' => $person->date_of_birth !== null && ($answer['date_of_birth'] ?? null) === $person->date_of_birth->toDateString(),
            'national_id' => filled($person->national_id) && self::bare((string) ($answer['national_id'] ?? '')) === self::bare((string) $person->national_id),
            default => false,
        };
    }

    /**
     * The field a wrong answer is named under.
     */
    private function field(string $method): string
    {
        return match ($method) {
            'date_of_birth' => 'date_of_birth',
            'national_id' => 'national_id',
            default => 'code',
        };
    }

    /**
     * A code or national ID number as typed or kept, without spaces and dashes, in Latin digits.
     */
    private static function bare(string $value): string
    {
        return strtr(preg_replace('/[\s\-]+/u', '', $value) ?? '', ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }

    private function newCode(): string
    {
        $length = (int) config('appointments.public.code_length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Cache keys hold a hash of the secret, never the secret itself.
     */
    private function key(string $kind, string $secret): string
    {
        return self::PREFIX."{$kind}:".hash('sha256', $secret);
    }
}
