<?php

// An isolated browser test harness, never loaded by public/index.php or application routes.
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CvDocument;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

$delivery = PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'deliver';
if (getenv('INTERNBRIDGE_SECURITY_DEMO') !== '1'
    || (! $delivery && (PHP_SAPI !== 'cli-server' || ($_SERVER['SERVER_PORT'] ?? '') !== '3002'
        || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)))) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($path, ['/app.css', '/app.js', '/toolbar.css', '/toolbar.js', '/theme.css', '/theme.js', '/sylva-home.css', '/company-map.css', '/company-map.js', '/support-bot.js', '/hero-particles.js', '/favicon.ico'], true)
    || preg_match('~\A/build/sylva/assets/[A-Za-z0-9_-]+\.(?:js|css|woff2)\z~', (string) $path)) {
    return false;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app->instance('env', 'local');
$directory = $root.'/storage/app/private/security-demo';
foreach ([$directory, $directory.'/sessions', $directory.'/cache'] as $folder) {
    if (! is_dir($folder)) {
        mkdir($folder, 0700, true);
    }
}
$database = $directory.'/database.sqlite';
if (! is_file($database)) {
    touch($database);
}
config([
    'app.name' => 'InternBridge · MÔ PHỎNG', 'app.url' => 'http://127.0.0.1:3002', 'app.debug' => false,
    'database.default' => 'sqlite', 'database.connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]],
    'supabase.url' => 'https://simulation.invalid', 'supabase.publishable_key' => 'simulation-only',
    'session.driver' => 'file', 'session.files' => $directory.'/sessions', 'session.cookie' => 'internbridge_security_demo', 'session.encrypt' => true,
    'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache',
    'mail.default' => 'array', 'application_mail.mode' => 'preview',
    'filesystems.disks.local.root' => $directory.'/files',
    'assistant.enabled' => true,
]);
if (! Schema::hasTable('users') || ! Schema::hasTable('application_status_emails') || ! Schema::hasColumn('candidate_profiles', 'avatar_key')) {
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
}
$admin = User::firstOrCreate(['id' => '00000000-0000-4000-8000-000000000001'], ['name' => 'Admin mô phỏng', 'email' => 'admin@simulation.test', 'role' => 'admin', 'status' => 'ACTIVE']);
$candidate = User::firstOrCreate(['id' => '00000000-0000-4000-8000-000000000004'], ['name' => 'Nguyễn Minh Anh · Mô phỏng', 'email' => 'candidate@simulation.test', 'role' => 'candidate', 'status' => 'ACTIVE']);
$employer = User::firstOrCreate(['id' => '00000000-0000-4000-8000-000000000005'], ['name' => 'Nhà tuyển dụng mô phỏng', 'email' => 'employer@simulation.test', 'role' => 'employer', 'status' => 'ACTIVE']);
CandidateProfile::firstOrCreate(['user_id' => $candidate->id], ['full_name' => $candidate->name]);
if (! JobApplication::exists()) {
    $company = Company::factory()->create(['owner_user_id' => $employer->id, 'name' => 'Studio Khởi Đầu · Mô phỏng']);
    $job = JobPosting::factory()->accepting()->create(['company_id' => $company->id, 'title' => 'Thực tập sinh thiết kế sản phẩm']);
    $cv = CvDocument::factory()->create(['candidate_id' => $candidate->id]);
    JobApplication::factory()->create(['job_id' => $job->id, 'cv_id' => $cv->id, 'candidate_id' => $candidate->id, 'name_snapshot' => $candidate->name]);
}
$factorId = '00000000-0000-4000-8000-000000000002';
$challengeId = '00000000-0000-4000-8000-000000000003';
$token = static fn (string $aal, string $userId): string => 'eyJhbGciOiJIUzI1NiJ9.'.rtrim(strtr(base64_encode(json_encode(['sub' => $userId, 'aal' => $aal, 'exp' => time() + 3600])), '+/', '-_'), '=').'.simulation';
$identity = ['id' => $admin->id, 'email' => $admin->email];
$session = static fn (string $aal, ?array $user = null): array => ['user' => $user ?? $identity, 'access_token' => $token($aal, ($user ?? $identity)['id']), 'refresh_token' => 'simulation-refresh', 'expires_in' => 3600];
Http::preventStrayRequests();
Http::fake(function ($request) use ($directory, $identity, $session, $factorId, $challengeId) {
    $url = $request->url();
    if ($url === 'https://simulation.invalid/auth/v1/token?grant_type=password') {
        $user = User::where('email', $request['email'])->first();

        return ($user && $request['password'] === 'Simulation-only-2026')
            ? Http::response($session('aal1', ['id' => $user->id, 'email' => $user->email])) : Http::response(['error_code' => 'invalid_credentials'], 400);
    }
    if ($url === 'https://simulation.invalid/auth/v1/user') {
        return Http::response($identity + ['factors' => is_file($directory.'/verified') ? [['id' => $factorId, 'factor_type' => 'totp', 'status' => 'verified']] : []]);
    }
    if ($url === 'https://simulation.invalid/auth/v1/factors') {
        return Http::response(['id' => $factorId, 'type' => 'totp', 'totp' => ['secret' => 'JBSWY3DPEHPK3PXP', 'qr_code' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="white"/><text x="20" y="100" fill="black">MO PHONG - NO QR</text></svg>']]);
    }
    if ($url === 'https://simulation.invalid/auth/v1/factors/'.$factorId.'/challenge') {
        return Http::response(['id' => $challengeId, 'type' => 'totp', 'expires_at' => time() + 300]);
    }
    if ($url === 'https://simulation.invalid/auth/v1/factors/'.$factorId.'/verify') {
        if ($request['code'] !== '123456') {
            return Http::response(['error_code' => 'mfa_verification_failed'], 422);
        }
        touch($directory.'/verified');

        return Http::response($session('aal2'));
    }
    if ($url === 'https://simulation.invalid/auth/v1/logout?scope=local') {
        return Http::response(null, 204);
    }
    throw new RuntimeException('Unexpected simulated auth endpoint.');
});
if ($delivery) {
    $app->instance('request', Request::create('http://127.0.0.1:3002'));
    $exitCode = Artisan::call('app:deliver-application-emails', ['--limit' => 50]);
    echo Artisan::output();
    exit($exitCode);
}
$request = Request::capture();
$response = $kernel->handle($request);
if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
    $response->setContent(str_replace('<div class="navigation-progress"', '<div role="status" style="padding:12px;background:#fff2bd;color:#594715;text-align:center">MÔ PHỎNG CỤC BỘ · Không kết nối Supabase hoặc gửi email thật · Mã thử: 123456</div><div class="navigation-progress"', $response->getContent()));
}
$response->send();
$kernel->terminate($request, $response);
