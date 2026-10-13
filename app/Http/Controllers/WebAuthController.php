<?php

namespace App\Http\Controllers;

use App\Exceptions\SupabaseRequestException;
use App\Http\Middleware\EnsureAdminConfirmation;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Services\SupabaseAuthService;
use App\Services\TrafficProtection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebAuthController extends Controller
{
    public function __construct(private SupabaseAuthService $supabase) {}

    public function login(): View
    {
        return view('auth.login');
    }

    public function register(): View
    {
        return view('auth.register');
    }

    public function storeLogin(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:256']);
        $beforeLogin = User::whereRaw('lower(email) = ?', [strtolower($data['email'])])->first(['id', 'auth_version']);
        try {
            $session = $this->supabase->signIn(strtolower($data['email']), $data['password']);
        } catch (SupabaseRequestException $e) {
            if ($e->getMessage() === 'AUTH_FAILED') {
                app(TrafficProtection::class)->failedCredentials($request);
            }
            throw ValidationException::withMessages(['email' => $this->authError($e)]);
        }
        $identity = $session['user'];
        $user = User::find($identity['id']);
        if (! $user) {
            $role = $identity['user_metadata']['account_type'] ?? null;
            if (! in_array($role, ['candidate', 'employer'], true)) {
                throw ValidationException::withMessages(['email' => __('messages.missing_app_profile')]);
            }
            $name = $identity['user_metadata']['full_name'] ?? null;
            $name = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'Thành viên';
            $user = $this->provision($identity, $role, $name);
        }
        if ($user->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['email' => __('messages.account_blocked')]);
        }
        if ((int) $user->auth_version !== (int) ($beforeLogin?->auth_version ?? 0)
            || ($beforeLogin && $beforeLogin->id !== $user->id)) {
            throw ValidationException::withMessages(['email' => __('messages.password_changed')]);
        }
        $user->update(['email' => $identity['email'] ?? $data['email']]);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('supabase_session', $session);
        $request->session()->put('auth_version', (int) $user->auth_version);
        $request->session()->forget('demo_auth');
        $request->session()->forget(['admin_confirmation', 'admin_password_confirmation', 'admin_mfa_enrollment', 'admin_mfa_factor']);
        if ($user->role === 'admin') {
            $request->session()->put('admin_password_confirmation', ['user_id' => $user->id, 'confirmed_at' => now()->timestamp]);

            return redirect()->route(EnsureAdminConfirmation::mfaRequired() ? 'admin.mfa' : 'admin.dashboard');
        }
        $intended = $request->session()->get('url.intended');
        $intendedPath = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
        if (is_string($intendedPath) && preg_match('#^/(admin|candidate|employer)(?:/|$)#', $intendedPath, $matches)
            && $matches[1] !== $user->role) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function storeRegister(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'password' => 'required|string|min:8|max:256|confirmed', 'role' => ['required', Rule::in(['candidate', 'employer'])]]);
        try {
            $this->supabase->signup(strtolower($data['email']), $data['password'], ['full_name' => $data['name'], 'account_type' => $data['role']]);
        } catch (SupabaseRequestException $e) {
            throw ValidationException::withMessages(['email' => $this->authError($e)]);
        }

        return redirect()->route('login')->with('success', __('messages.signup_received'));
    }

    /** @param array<string,mixed> $identity */
    private function provision(array $identity, string $role, string $name): User
    {
        return DB::transaction(function () use ($identity, $role, $name): User {
            $user = User::firstOrCreate(['id' => $identity['id']], ['name' => $name, 'email' => $identity['email'] ?? null, 'role' => $role, 'status' => 'ACTIVE', 'is_demo' => false]);
            if ($user->role === 'candidate') {
                CandidateProfile::firstOrCreate(['user_id' => $user->id], ['full_name' => $name]);
            }

            return $user;
        });
    }

    public function logout(Request $request): RedirectResponse
    {
        $token = $request->session()->get('supabase_session.access_token');
        if (is_string($token)) {
            try {
                $this->supabase->signOut($token);
            } catch (SupabaseRequestException) {
            }
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', __('messages.logged_out'));
    }

    public function demo(Request $request, string $role): RedirectResponse
    {
        abort_unless(app()->environment('local') && config('app.demo_mode') && in_array($request->ip(), ['127.0.0.1', '::1'], true), 404);
        abort_unless(in_array($role, ['candidate', 'employer'], true), 404);
        $data = $request->validate(['user_id' => 'nullable|uuid']);
        $user = User::where('role', $role)->where('is_demo', true)->where('status', 'ACTIVE')
            ->when($data['user_id'] ?? null, fn ($query, $id) => $query->whereKey($id))->orderBy('id')->firstOrFail();
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('supabase_session');
        $request->session()->forget('admin_confirmation');
        $request->session()->put('demo_auth', true);
        $request->session()->put('auth_version', (int) $user->auth_version);

        return redirect()->route('dashboard')->with('success', __('messages.local_demo'));
    }

    public function dashboard(Request $request): RedirectResponse
    {
        return redirect()->route(match ($request->user()->role) {
            'candidate' => 'candidate.dashboard','employer' => 'employer.dashboard',default => 'admin.dashboard'
        });
    }

    private function authError(SupabaseRequestException $e): string
    {
        return match ($e->getMessage()) {
            'EMAIL_NOT_CONFIRMED' => __('messages.confirm_email'),
            'AUTH_RATE_LIMITED' => __('messages.auth_rate_limited'),
            'AUTH_UNAVAILABLE' => __('messages.auth_unavailable'),
            'SIGNUP_FAILED' => __('messages.signup_failed'),
            default => __('messages.invalid_credentials'),
        };
    }
}
