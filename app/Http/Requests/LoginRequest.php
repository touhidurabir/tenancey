<?php

namespace App\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared by the central and tenant login forms; the controller says which guard to use.
 */
class LoginRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to sign in on the given guard, allowing 5 failures per minute.
     *
     * @throws ValidationException
     */
    public function authenticate(string $guard): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard($guard)->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($this->throttleKey())]),
        ]);
    }

    /**
     * Keyed by tenant as well as email + IP, so failed logins on one tenant can never lock the
     * same email out of another tenant (or out of the central admin).
     */
    protected function throttleKey(): string
    {
        $scope = tenant('id') ?? 'central';

        return $scope.'|'.Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
