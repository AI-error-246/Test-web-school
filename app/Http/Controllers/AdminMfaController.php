<?php

namespace App\Http\Controllers;

use App\Exceptions\SupabaseRequestException;
use App\Http\Middleware\EnsureAdminConfirmation;
use App\Services\SupabaseAuthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminMfaController extends Controller
{
    public function show(Request $request, SupabaseAuthService $supabase): View|RedirectResponse
    {
        if (EnsureAdminConfirmation::confirmed($request)) {
            return redirect()->route('admin.dashboard');
        }
        if (! EnsureAdminConfirmation::passwordConfirmed($request)) {
            return $this->expired($request);
        }
        try {
            $factors = $supabase->mfaFactors($this->token($request));
        } catch (SupabaseRequestException) {
            abort(503, __('messages.auth_service_unavailable'));
        }
        $enrollment = $request->session()->get('admin_mfa_enrollment');
        if ($factors !== []) {
            $request->session()->put('admin_mfa_factor', $factors[0]['id']);
            $request->session()->forget('admin_mfa_enrollment');
            $enrollment = null;
        } elseif (is_array($enrollment)) {
            $request->session()->put('admin_mfa_factor', $enrollment['id']);
        } else {
            $request->session()->forget('admin_mfa_factor');
        }

        return view('auth.admin-mfa', ['enrollment' => $enrollment, 'hasFactor' => $request->session()->has('admin_mfa_factor')]);
    }

    public function enroll(Request $request, SupabaseAuthService $supabase): RedirectResponse
    {
        if (! EnsureAdminConfirmation::passwordConfirmed($request)) {
            return $this->expired($request);
        }
        try {
            $factors = $supabase->mfaFactors($this->token($request));
            if ($factors === [] && ! $request->session()->has('admin_mfa_enrollment')) {
                $enrollment = $supabase->enrollTotp($this->token($request));
                $request->session()->put('admin_mfa_enrollment', $enrollment);
                $request->session()->put('admin_mfa_factor', $enrollment['id']);
            }
        } catch (SupabaseRequestException) {
            throw ValidationException::withMessages(['mfa' => __('messages.mfa_setup_unavailable')]);
        }

        return redirect()->route('admin.mfa');
    }

    public function verify(Request $request, SupabaseAuthService $supabase): RedirectResponse
    {
        if (! EnsureAdminConfirmation::passwordConfirmed($request)) {
            return $this->expired($request);
        }
        $data = $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        $factor = $request->session()->get('admin_mfa_factor');
        if (! is_string($factor)) {
            return redirect()->route('admin.mfa');
        }
        try {
            $session = $supabase->verifyTotp($this->token($request), $factor, $data['code']);
            if ($session['user']['id'] !== $request->user()->id) {
                throw new SupabaseRequestException('AUTH_FAILED', 401);
            }
        } catch (SupabaseRequestException) {
            throw ValidationException::withMessages(['code' => __('messages.mfa_code_failed')]);
        }
        $request->session()->regenerate();
        $request->session()->put('supabase_session', $session);
        $request->session()->put('admin_confirmation', ['user_id' => $request->user()->id, 'confirmed_at' => now()->timestamp, 'mfa' => true]);
        $request->session()->forget(['admin_password_confirmation', 'admin_mfa_enrollment', 'admin_mfa_factor']);
        $intended = $request->session()->pull('url.intended');
        $base = rtrim(route('admin.dashboard'), '/');
        if (! is_string($intended) || ! ($intended === $base || str_starts_with($intended, $base.'/'))
            || str_contains($intended, '/mfa') || str_contains($intended, '/login')) {
            $intended = $base;
        }

        return redirect()->to($intended)->with('success', __('messages.mfa_verified'));
    }

    private function token(Request $request): string
    {
        return (string) $request->session()->get('supabase_session.access_token', '');
    }

    private function expired(Request $request): RedirectResponse
    {
        $request->session()->forget(['admin_password_confirmation', 'admin_mfa_enrollment', 'admin_mfa_factor', 'admin_confirmation']);

        return redirect()->route('admin.login')->with('status', __('messages.mfa_login_again'));
    }
}
