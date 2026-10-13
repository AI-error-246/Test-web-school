<?php

namespace App\Http\Middleware;

use App\Services\SupabaseAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminConfirmation
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::confirmed($request)) {
            $request->session()->forget('admin_confirmation');

            return redirect()->guest(route(self::mfaRequired() && self::passwordConfirmed($request) ? 'admin.mfa' : 'admin.login'))
                ->with('status', __(self::mfaRequired() ? 'messages.admin_confirmation' : 'messages.admin_password_confirmation'));
        }

        if (! self::mfaRequired()) {
            $request->session()->forget(['admin_mfa_enrollment', 'admin_mfa_factor']);
        }

        return $next($request);
    }

    public static function mfaRequired(): bool
    {
        return config('auth.admin_mfa_required', true) !== false;
    }

    public static function confirmed(Request $request): bool
    {
        $passwordOnly = ! self::mfaRequired() && $request->session()->has('admin_password_confirmation');
        $confirmation = $request->session()->get('admin_confirmation');
        if ($passwordOnly) {
            $confirmation = $request->session()->get('admin_password_confirmation');
        }

        return ! $request->session()->get('demo_auth')
            && $request->user()?->role === 'admin'
            && is_array($confirmation)
            && ($passwordOnly || (($confirmation['mfa'] ?? false) === true
                && SupabaseAuthService::isAal2((string) $request->session()->get('supabase_session.access_token', ''), (string) $request->user()?->id)))
            && ($confirmation['user_id'] ?? null) === $request->user()?->id
            && is_int($confirmation['confirmed_at'] ?? null)
            && $confirmation['confirmed_at'] > now()->timestamp - 1800
            && $confirmation['confirmed_at'] <= now()->timestamp;
    }

    public static function passwordConfirmed(Request $request): bool
    {
        $confirmation = $request->session()->get('admin_password_confirmation');

        return ! $request->session()->get('demo_auth')
            && $request->user()?->role === 'admin'
            && is_array($confirmation)
            && ($confirmation['user_id'] ?? null) === $request->user()->id
            && is_int($confirmation['confirmed_at'] ?? null)
            && $confirmation['confirmed_at'] > now()->timestamp - 600
            && $confirmation['confirmed_at'] <= now()->timestamp;
    }
}
