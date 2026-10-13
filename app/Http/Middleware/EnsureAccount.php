<?php

namespace App\Http\Middleware;

use App\Exceptions\SupabaseRequestException;
use App\Models\User;
use App\Services\SupabaseAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->guest(route($request->routeIs('admin.*') ? 'admin.login' : 'login'));
        }
        $user = User::find(Auth::id());
        if (! $user || $user->status !== 'ACTIVE') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route($request->routeIs('admin.*') ? 'admin.login' : 'login')->withErrors(['email' => __('messages.account_unavailable')]);
        }
        Auth::setUser($user);
        if ((int) $request->session()->get('auth_version', 0) !== (int) $user->auth_version) {
            return $this->expired($request);
        }
        if ($request->session()->get('demo_auth')) {
            abort_unless(app()->environment('local') && config('app.demo_mode') && $user->is_demo && in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
        } elseif (! app()->runningUnitTests()) {
            $session = $request->session()->get('supabase_session');
            if (! is_array($session) || empty($session['refresh_token'])) {
                return $this->expired($request);
            }
            if (($session['expires_at'] ?? 0) <= time() + 30) {
                try {
                    $session = app(SupabaseAuthService::class)->refresh($session['refresh_token']);
                } catch (SupabaseRequestException) {
                    return $this->expired($request);
                }
                if (($session['user']['id'] ?? null) !== $user->id) {
                    return $this->expired($request);
                }
                $request->session()->put('supabase_session', $session);
            }
        }

        if ($user->role === 'admin' && ! $request->routeIs('admin.mfa', 'admin.mfa.*')) {
            return app(EnsureAdminConfirmation::class)->handle($request, $next);
        }

        return $next($request);
    }

    private function expired(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->guest(route($request->routeIs('admin.*') ? 'admin.login' : 'login'))->withErrors(['email' => __('messages.session_expired')]);
    }
}
