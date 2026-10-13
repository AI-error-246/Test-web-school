<?php

namespace App\Http\Controllers;

use App\Exceptions\SupabaseRequestException;
use App\Http\Middleware\EnsureAdminConfirmation;
use App\Models\User;
use App\Services\SupabaseAuthService;
use App\Services\TrafficProtection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        abort_if($request->user() && $request->user()->role !== 'admin', 403);
        if ($request->user()?->status === 'ACTIVE' && EnsureAdminConfirmation::confirmed($request)) {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.admin-login');
    }

    public function store(Request $request, SupabaseAuthService $supabase): RedirectResponse
    {
        abort_if($request->user() && $request->user()->role !== 'admin', 403);
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:256']);
        $beforeLogin = User::whereRaw('lower(email) = ?', [strtolower($data['email'])])->first(['id', 'auth_version']);
        $request->session()->forget(['admin_confirmation', 'admin_password_confirmation', 'admin_mfa_enrollment', 'admin_mfa_factor']);
        try {
            $session = $supabase->signIn(strtolower($data['email']), $data['password']);
        } catch (SupabaseRequestException $exception) {
            if ($exception->getMessage() === 'AUTH_FAILED') {
                app(TrafficProtection::class)->failedCredentials($request);
            }
            $message = match ($exception->getMessage()) {
                'AUTH_UNAVAILABLE' => __('messages.auth_unavailable'),
                'AUTH_RATE_LIMITED' => __('messages.auth_rate_limited'),
                default => __('messages.admin_invalid_credentials'),
            };
            throw ValidationException::withMessages(['email' => $message]);
        }
        $user = User::find($session['user']['id']);
        if (! $user || $user->role !== 'admin' || $user->status !== 'ACTIVE'
            || ($request->user() && $request->user()->id !== $user->id)) {
            throw ValidationException::withMessages(['email' => __('messages.admin_invalid_credentials')]);
        }
        if ((int) $user->auth_version !== (int) ($beforeLogin?->auth_version ?? 0)
            || ($beforeLogin && $beforeLogin->id !== $user->id)) {
            throw ValidationException::withMessages(['email' => __('messages.password_changed')]);
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('supabase_session', $session);
        $request->session()->put('auth_version', (int) $user->auth_version);
        $request->session()->put('admin_password_confirmation', ['user_id' => $user->id, 'confirmed_at' => now()->timestamp]);
        $request->session()->forget('demo_auth');

        return redirect()->route('admin.mfa');
    }
}
