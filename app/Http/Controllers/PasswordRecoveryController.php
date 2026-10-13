<?php

namespace App\Http\Controllers;

use App\Exceptions\SupabaseRequestException;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Services\SupabaseAuthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryController extends Controller
{
    public function requestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request, SupabaseAuthService $supabase): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|string|email|max:254', 'contact_website' => 'nullable|string|max:0']);
        $email = strtolower(trim($data['email']));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        try {
            $supabase->requestPasswordRecovery($email, $challenge);
        } catch (SupabaseRequestException $exception) {
            Log::warning('Password recovery provider request was not confirmed.', ['code' => $exception->getMessage()]);

            return $this->requestReceived();
        }
        $request->session()->forget('password_recovery');
        $request->session()->put('password_recovery_request', ['email' => $email, 'verifier' => $verifier, 'expires_at' => now()->addHour()->timestamp]);

        return $this->requestReceived();
    }

    public function callback(Request $request, SupabaseAuthService $supabase): RedirectResponse
    {
        $pending = $request->session()->get('password_recovery_request');
        $code = $request->query('code');
        if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->timestamp || ! is_string($code) || $code === '' || strlen($code) > 2048) {
            return $this->invalidLink();
        }
        try {
            $session = $supabase->exchangeRecoveryCode($code, $pending['verifier']);
        } catch (SupabaseRequestException) {
            return $this->invalidLink();
        }
        if (strtolower((string) ($session['user']['email'] ?? '')) !== $pending['email']) {
            return $this->invalidLink();
        }
        $identity = $session['user'];
        if (! User::whereKey($identity['id'])->exists()) {
            $role = $identity['user_metadata']['account_type'] ?? null;
            if (! in_array($role, ['candidate', 'employer'], true)) {
                return $this->invalidLink();
            }
            DB::transaction(function () use ($identity, $role): void {
                $name = $identity['user_metadata']['full_name'] ?? null;
                $name = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'Thành viên';
                $user = User::firstOrCreate(['id' => $identity['id']], ['email' => $identity['email'], 'name' => $name, 'role' => $role, 'status' => 'ACTIVE', 'is_demo' => false]);
                if ($user->role === 'candidate') {
                    CandidateProfile::firstOrCreate(['user_id' => $user->id], ['full_name' => $name]);
                }
            });
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('password_recovery', [
            'user_id' => $session['user']['id'], 'access_token' => $session['access_token'],
            'expires_at' => min($session['expires_at'], now()->addMinutes(10)->timestamp),
            'mfa_verified' => false,
        ]);

        return redirect()->route('password.reset');
    }

    public function resetForm(Request $request, SupabaseAuthService $supabase): View|RedirectResponse
    {
        $grant = $this->grant($request);
        if (! $grant) {
            return $this->invalidLink();
        }
        try {
            $requiresMfa = $this->requiresMfa($request, $grant, $supabase);
        } catch (SupabaseRequestException) {
            abort(503, __('messages.recovery_check_failed'));
        }

        return view('auth.reset-password', ['requiresMfa' => $requiresMfa]);
    }

    public function verifyMfa(Request $request, SupabaseAuthService $supabase): RedirectResponse
    {
        $grant = $this->grant($request);
        if (! $grant) {
            return $this->invalidLink();
        }
        $data = $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        $factor = $grant['factor_id'] ?? null;
        if (! is_string($factor)) {
            return redirect()->route('password.reset');
        }
        try {
            $factors = $supabase->mfaFactors($grant['access_token']);
            if (! in_array($factor, array_column($factors, 'id'), true)) {
                $request->session()->forget('password_recovery.factor_id');

                return redirect()->route('password.reset');
            }
            $session = $supabase->verifyTotp($grant['access_token'], $factor, $data['code']);
            if ($session['user']['id'] !== $grant['user_id']
                || ! SupabaseAuthService::isAal2($session['access_token'], $grant['user_id'])) {
                throw new SupabaseRequestException('AUTH_FAILED', 401);
            }
        } catch (SupabaseRequestException) {
            throw ValidationException::withMessages(['code' => __('messages.recovery_code_failed')]);
        }
        $request->session()->regenerate();
        $request->session()->put('password_recovery', [
            'user_id' => $grant['user_id'], 'access_token' => $session['access_token'],
            'expires_at' => min($grant['expires_at'], $session['expires_at']), 'mfa_verified' => true,
        ]);

        return redirect()->route('password.reset')->with('success', __('messages.recovery_code_verified'));
    }

    public function reset(Request $request, SupabaseAuthService $supabase): RedirectResponse
    {
        $grant = $this->grant($request);
        if (! $grant) {
            return $this->invalidLink();
        }
        $data = $request->validate(['password' => 'required|string|min:8|max:256|confirmed']);
        try {
            $requiresMfa = $this->requiresMfa($request, $grant, $supabase);
        } catch (SupabaseRequestException) {
            throw ValidationException::withMessages(['password' => __('messages.recovery_check_failed')]);
        }
        if ($requiresMfa) {
            throw ValidationException::withMessages(['password' => __('messages.verify_before_password')]);
        }
        // Revoke application sessions before the remote write; a timeout must not leave an old session active.
        User::whereKey($grant['user_id'])->increment('auth_version');
        try {
            $identity = $supabase->updateRecoveredPassword($grant['access_token'], $data['password']);
        } catch (SupabaseRequestException) {
            throw ValidationException::withMessages(['password' => __('messages.new_password_unconfirmed')]);
        } finally {
            User::whereKey($grant['user_id'])->increment('auth_version');
        }
        abort_unless($identity['id'] === $grant['user_id'], 503);
        try {
            $supabase->signOutAll($grant['access_token']);
        } catch (SupabaseRequestException) {
            // App sessions are already revoked even if the provider is temporarily unavailable.
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', __('messages.password_reset'));
    }

    /** @param array{user_id:string, access_token:string, expires_at:int, mfa_verified?:bool, factor_id?:string} $grant */
    private function requiresMfa(Request $request, array $grant, SupabaseAuthService $supabase): bool
    {
        $factors = $supabase->mfaFactors($grant['access_token']);
        if ($factors === [] || (($grant['mfa_verified'] ?? false) === true && SupabaseAuthService::isAal2($grant['access_token'], $grant['user_id']))) {
            $request->session()->forget('password_recovery.factor_id');

            return false;
        }
        $request->session()->put('password_recovery.factor_id', $factors[0]['id']);

        return true;
    }

    /** @return array{user_id:string, access_token:string, expires_at:int, mfa_verified?:bool, factor_id?:string}|null */
    private function grant(Request $request): ?array
    {
        $grant = $request->session()->get('password_recovery');
        if (! is_array($grant) || ($grant['expires_at'] ?? 0) <= now()->timestamp || empty($grant['access_token']) || empty($grant['user_id'])) {
            $request->session()->forget('password_recovery');

            return null;
        }

        return $grant;
    }

    private function invalidLink(): RedirectResponse
    {
        return redirect()->route('password.request')->withErrors(['email' => __('messages.recovery_link_invalid')]);
    }

    private function requestReceived(): RedirectResponse
    {
        return back()->with('success', __('messages.recovery_requested'));
    }
}
