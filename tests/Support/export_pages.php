<?php

/** Export the existing Blade UI using disposable fixtures only. Never boot the real .env. */
use App\Models\Company;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$isolated = $root.'/storage/app/private/pages-build-'.bin2hex(random_bytes(8));
foreach (['', '/framework/views', '/framework/cache', '/logs'] as $directory) {
    mkdir($isolated.$directory, 0700, true);
}
foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
    putenv($key.'='.$isolated.'/'.$key.'.php');
    $_ENV[$key] = $_SERVER[$key] = $isolated.'/'.$key.'.php';
}
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($isolated);
$app->useStoragePath($isolated);
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'Static export failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
});
$app->instance('env', 'testing');
config([
    'app.url' => 'https://preview.invalid', 'app.asset_url' => null,
    'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.debug' => false,
    'database.default' => 'sqlite',
    'database.connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]],
    'cache.default' => 'array', 'session.driver' => 'array', 'session.encrypt' => false,
    'filesystems.default' => 'local',
    'filesystems.disks' => ['local' => ['driver' => 'local', 'root' => $isolated.'/files', 'throw' => true]],
    'supabase' => ['url' => null, 'publishable_key' => null, 'secret_key' => null, 'cv_disk' => 'local'],
    'queue.default' => 'sync', 'mail.default' => 'array', 'auth.admin_mfa_required' => false,
    'view.compiled' => $isolated.'/framework/views',
]);
Http::preventStrayRequests();
DB::purge();
if (DB::connection()->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Static export requires an in-memory fixture database.');
}
Artisan::call('migrate', ['--force' => true]);
(new DemoSeeder)->run();

// Middleware bypass is confined to this CLI process, which has no real users or credentials.
$app->instance('middleware.disable', true);
Event::listen(RouteMatched::class, function (RouteMatched $event) use ($app): void {
    $app['router']->substituteBindings($event->route);
    $app['router']->substituteImplicitBindings($event->route);
});
$users = [null => null];
foreach (['candidate', 'employer', 'admin'] as $role) {
    $users[$role] = User::where('role', $role)->orderBy('id')->firstOrFail();
}
$pages = [];
$add = function (string $path, string $role = '') use (&$pages): void {
    $pages[$role.'|'.$path] = ['path' => $path, 'role' => $role];
};
foreach (['/', '/jobs', '/login', '/register', '/forgot-password', '/admin/login'] as $path) {
    $add($path);
}
foreach (['/candidate', '/candidate/profile', '/candidate/profile/view', '/candidate/cvs', '/candidate/applications', '/candidate/notifications', '/candidate/saved-searches', '/candidate/saved-jobs', '/candidate/job-history'] as $path) {
    $add($path, 'candidate');
}
foreach (['/employer', '/employer/company', '/employer/jobs', '/employer/jobs/create'] as $path) {
    $add($path, 'employer');
}
foreach (['/admin', '/admin/users', '/admin/jobs', '/admin/reports', '/admin/statistics'] as $path) {
    $add($path, 'admin');
}
foreach (Company::all() as $company) {
    $add('/companies/'.$company->id);
}
foreach (JobPosting::all() as $job) {
    if ($job->moderation_state === 'APPROVED') {
        $add('/jobs/'.$job->id);
    }
    if ($job->publication_state === 'OPEN') {
        $add('/jobs/'.$job->id.'/apply', 'candidate');
    }
    $add('/admin/jobs/'.$job->id, 'admin');
    if ($job->company->owner_user_id === $users['employer']->id) {
        $add('/employer/jobs/'.$job->id.'/edit', 'employer');
        $add('/employer/jobs/'.$job->id.'/applications', 'employer');
    }
}
foreach (User::where('role', '!=', 'admin')->get() as $user) {
    $add('/admin/users/'.$user->id, 'admin');
}
foreach (Report::all() as $report) {
    $add('/admin/reports/'.$report->id, 'admin');
}
foreach (JobApplication::all() as $application) {
    if ($application->candidate_id === $users['candidate']->id) {
        $add('/applications/'.$application->id, 'candidate');
    }
    $add('/applications/'.$application->id, 'employer');
}

$filename = static fn (string $locale, string $path, string $role): string => $locale.'-'.($role ? $role.'-' : '').($path === '/' ? 'index' : str_replace('/', '-', trim($path, '/'))).'.html';
$resolve = function (string $value, string $locale, string $role) use (&$pages, $filename): ?string {
    $parts = parse_url(html_entity_decode($value));
    if ($parts === false || isset($parts['host']) && $parts['host'] !== 'preview.invalid') {
        return null;
    }
    $path = $parts['path'] ?? '/';
    if ($path === '/dashboard') {
        $path = '/'.($role ?: 'candidate');
    }
    foreach (array_unique([$role, '', 'candidate', 'employer', 'admin']) as $choice) {
        if (isset($pages[$choice.'|'.$path])) {
            return $filename($locale, $path, $choice).(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
        }
    }

    return null;
};
$output = $root.'/storage/app/pages';
if (is_dir($output)) {
    // Only this fixed generated artifact directory may be replaced.
    (new Filesystem)->deleteDirectory($output);
}
mkdir($output.'/assets', 0755, true);
$assets = ['app.css', 'app.js', 'toolbar.css', 'toolbar.js', 'theme.css', 'theme.js', 'sylva-home.css', 'company-map.css', 'favicon.ico'];
foreach ($assets as $asset) {
    copy($root.'/public/'.$asset, $output.'/assets/'.$asset);
}
foreach (['preview.js', 'preview.css'] as $asset) {
    copy(__DIR__.'/'.$asset, $output.'/assets/'.$asset);
}
$sceneManifest = $root.'/public/build/pages-scene/manifest.json';
if (! is_file($sceneManifest)) {
    throw new RuntimeException('Build the Community scene with vite.pages.config.js before exporting.');
}
(new Filesystem)->copyDirectory($root.'/public/build/pages-scene', $output.'/build/pages-scene');
mkdir($output.'/inner-green-assets', 0755, true);
copy($root.'/resources/vendor/threeui-community/src/shaders/sylva-living-world/sources/inner-green-assets/lexend-latin.woff2', $output.'/inner-green-assets/lexend-latin.woff2');
$manifest = json_decode(file_get_contents($sceneManifest), true, flags: JSON_THROW_ON_ERROR);
$sceneEntry = collect($manifest)->first(fn (array $entry): bool => ($entry['isEntry'] ?? false));
$labels = [
    'vi' => ['BẢN DEMO GIAO DIỆN', 'Dữ liệu mẫu · Không đăng nhập, gửi CV hay lưu thay đổi. Không nhập thông tin thật.', 'Trang chủ', 'Ứng viên', 'Nhà tuyển dụng', 'Admin', 'Đây là bản xem giao diện. Thao tác này không lưu hoặc gửi dữ liệu.'],
    'en' => ['INTERFACE DEMO', 'Sample data · No sign-in, CV submission or saved changes. Do not enter real information.', 'Home', 'Candidate', 'Employer', 'Admin', 'Interface preview only. This action does not save or send data.'],
    'ja' => ['画面デモ', 'サンプルデータ · ログイン・応募・保存はできません。実際の個人情報を入力しないでください。', 'ホーム', '応募者', '採用担当者', '管理者', '画面のプレビューです。データは保存・送信されません。'],
];
$count = 0;
foreach ($labels as $locale => $text) {
    foreach ($pages as ['path' => $path, 'role' => $role]) {
        Auth::forgetGuards();
        if ($role !== '') {
            Auth::setUser($users[$role]);
        }
        $session = $app['session']->driver();
        $session->flush();
        $session->start();
        $request = Request::create('https://preview.invalid'.$path);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $users[$role] ?? null);
        $app->instance('request', $request);
        $app['url']->setRequest($request);
        $app->setLocale($locale);
        $app['view']->share('errors', new ViewErrorBag);
        $response = $app['router']->dispatch($request);
        $name = $filename($locale, $path, $role);
        if ($response->isRedirect()) {
            $destination = $resolve($response->headers->get('Location'), $locale, $role);
            if (! $destination) {
                throw new RuntimeException('Unmapped redirect: '.$path);
            }
            file_put_contents($output.'/'.$name, '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url='.$destination.'"><a href="'.$destination.'">Demo</a>');
            $count++;

            continue;
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Cannot export '.$path.': '.$response->getStatusCode());
        }
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//script | //meta[@name="csrf-token"] | //input[@name="_token" or @name="_method" or @name="return_to"] | //link[@rel="modulepreload"]')) as $node) {
            if ($node->nodeName === 'script' && in_array(basename(parse_url($node->getAttribute('src'), PHP_URL_PATH) ?? ''), $assets, true)) {
                continue;
            }
            $node->parentNode->removeChild($node);
        }
        foreach ($xpath->query('//*[@href or @src]') as $node) {
            foreach (['href', 'src'] as $attribute) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }
                $value = $node->getAttribute($attribute);
                if (str_starts_with($value, '#') || str_starts_with($value, 'data:')) {
                    continue;
                }
                $basename = basename(parse_url($value, PHP_URL_PATH) ?? '');
                if (in_array($basename, $assets, true)) {
                    $node->setAttribute($attribute, 'assets/'.$basename);
                } elseif ($node->nodeName === 'a') {
                    $target = $resolve($value, $locale, $role);
                    $node->setAttribute('href', $target ?? '#preview-notice');
                    if (! $target) {
                        $node->setAttribute('data-preview-action', '');
                    }
                } else {
                    $node->parentNode->removeChild($node);
                    break;
                }
            }
        }
        foreach ($xpath->query('//form') as $form) {
            $form->setAttribute('data-preview-form', '');
            $form->setAttribute('action', '#preview-notice');
            $form->setAttribute('method', 'get');
            $form->setAttribute('novalidate', '');
            $form->removeAttribute('data-confirm');
        }
        foreach ($xpath->query('//input | //textarea | //select | //button') as $input) {
            if ($input->getAttribute('name') === 'locale') {
                foreach (['vi', 'en', 'ja'] as $language) {
                    $input->setAttribute('data-'.$language, $filename($language, $path, $role));
                }
            }
            $input->removeAttribute('name');
            $input->removeAttribute('data-confirm');
            if (in_array($input->getAttribute('type'), ['password', 'email', 'file'], true)) {
                $input->setAttribute('disabled', '');
                $input->setAttribute('autocomplete', 'off');
            }
        }
        foreach ($xpath->query('//*[@data-notifications or @data-endpoint or @data-feed-url]') as $node) {
            foreach (['data-notifications', 'data-endpoint', 'data-feed-url'] as $attribute) {
                $node->removeAttribute($attribute);
            }
        }
        foreach ($xpath->query('//*[@data-map-src]') as $node) {
            $node->removeAttribute('data-map-src');
        }
        $html = $dom->saveHTML();
        $html = preg_replace('/<\?xml[^>]*>/', '', $html);
        $head = '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data: blob:; font-src \'self\' data:; frame-src \'self\' blob:; connect-src \'none\'; form-action \'none\'; base-uri \'none\'; object-src \'none\'"><link rel="stylesheet" href="assets/preview.css"><script src="assets/preview.js" defer></script>';
        if ($path === '/') {
            $head .= '<script type="module" src="build/pages-scene/'.$sceneEntry['file'].'"></script>';
            foreach ($sceneEntry['css'] ?? [] as $css) {
                $head .= '<link rel="stylesheet" href="build/pages-scene/'.$css.'">';
            }
        }
        $banner = '<aside class="preview-banner" aria-label="Demo"><div><strong>'.$text[0].'</strong><span>'.$text[1].'</span></div><nav aria-label="Demo roles">';
        foreach (['' => '/', 'candidate' => '/candidate', 'employer' => '/employer', 'admin' => '/admin'] as $choice => $destination) {
            $banner .= '<a href="'.$filename($locale, $destination, $choice).'">'.$text[array_search($choice, ['', 'candidate', 'employer', 'admin'], true) + 2].'</a>';
        }
        $banner .= '</nav></aside><div id="preview-notice" class="preview-notice" role="status" hidden>'.$text[6].'</div>';
        $html = str_replace('</head>', '<link rel="icon" href="assets/favicon.ico">'.$head.'</head>', $html);
        $html = preg_replace('/(<body[^>]*>)/', '$1'.$banner, $html, 1);
        if (preg_match('/sb_secret_|sb_publishable_|supabase\.co|csrf-token|name="_token"|preview\.invalid|127\.0\.0\.1/', $html)) {
            throw new RuntimeException('Unsafe or unresolved data in '.$name);
        }
        file_put_contents($output.'/'.$name, $html);
        $count++;
    }
}
file_put_contents($output.'/index.html', '<!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="assets/favicon.ico"><meta http-equiv="refresh" content="0;url=vi-index.html"><title>InternBridge — Demo</title><a href="vi-index.html">Xem giao diện InternBridge</a></html>');
file_put_contents($output.'/.nojekyll', '');
file_put_contents($output.'/robots.txt', "User-agent: *\nDisallow: /\n");
(new Filesystem)->deleteDirectory($isolated);
echo 'Exported '.$count.' fixture-only pages to storage/app/pages'.PHP_EOL;
