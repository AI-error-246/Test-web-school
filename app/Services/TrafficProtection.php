<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class TrafficProtection
{
    public function key(string $scope, string $identity): string
    {
        return 'traffic:'.$scope.':'.hash_hmac('sha256', $identity, (string) config('app.key'));
    }

    public function ip(Request $request): string
    {
        return (string) $request->server('REMOTE_ADDR', 'unknown');
    }

    public function account(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? mb_strtolower(trim(mb_substr($email, 0, 254))) : '';
    }

    public function consume(string $key, int $limit, int $seconds): int
    {
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return max(1, RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, $seconds);

        return 0;
    }

    public function cooldown(string $identity): int
    {
        return max(0, RateLimiter::availableIn($this->key('cooldown', $identity)));
    }

    public function signal(string $type, string $identity, int $threshold): void
    {
        $key = $this->key($type, $identity);
        $attempts = RateLimiter::hit($key, (int) config('traffic_guard.behavior_window_seconds'));
        if ($attempts >= $threshold) {
            RateLimiter::hit($this->key('cooldown', $identity), (int) config('traffic_guard.cooldown_seconds'));
            RateLimiter::clear($key);
        }
    }

    public function failedCredentials(Request $request): void
    {
        if (config('traffic_guard.enabled')) {
            $this->signal('credentials', $this->ip($request).'|'.$this->account($request), (int) config('traffic_guard.failure_threshold'));
        }
    }
}
