<?php

namespace App\Http\Middleware;

use App\Services\TrafficProtection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrafficGuard
{
    public function __construct(private TrafficProtection $protection) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('traffic_guard.enabled')) {
            return $next($request);
        }
        $ip = $this->protection->ip($request);
        $wait = $this->protection->cooldown($ip);
        if ($wait > 0) {
            return $this->limited($wait);
        }
        $path = strtolower(rawurldecode($request->path()));
        if (preg_match('#(?:^|/)(?:\.env(?:\.[^/]*)?|\.git|\.svn)(?:/|$)|^storage/(?:app/)?private(?:/|$)|^storage/admin-login\.txt$#', $path)) {
            $this->protection->signal('probe', $ip, (int) config('traffic_guard.probe_threshold'));

            return response()->json(['error' => 'NOT_FOUND'], 404);
        }
        $wait = $this->protection->consume($this->protection->key('requests', $ip), (int) config('traffic_guard.requests_per_minute'), 60);
        if ($wait > 0) {
            return $this->limited($wait);
        }
        if ($request->isMethod('POST') && in_array($request->path(), ['login', 'admin/login', 'register'], true)) {
            $account = $this->protection->account($request);
            $wait = $this->protection->cooldown($ip.'|'.$account);
            if ($wait === 0) {
                $wait = $this->protection->consume($this->protection->key('auth-ip', $ip), (int) config('traffic_guard.auth_per_minute'), 60);
            }
            if ($wait === 0 && $account !== '') {
                $wait = $this->protection->consume($this->protection->key('auth-account', $account), (int) config('traffic_guard.account_attempts'), (int) config('traffic_guard.account_window_seconds'));
            }
            if ($wait > 0) {
                return $this->limited($wait);
            }
            if ($request->path() === 'register' && ! in_array($request->input('contact_website'), [null, ''], true)) {
                $this->protection->signal('probe', $ip, (int) config('traffic_guard.probe_threshold'));

                return response()->json(['error' => 'REGISTRATION_REJECTED', 'message' => __('messages.form_rejected')], 422);
            }
        }

        return $next($request);
    }

    private function limited(int $seconds): Response
    {
        return response()->json(['error' => 'TOO_MANY_REQUESTS', 'message' => __('messages.too_many_requests'), 'retry_after' => $seconds], 429, ['Retry-After' => (string) $seconds]);
    }
}
