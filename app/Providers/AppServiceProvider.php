<?php

namespace App\Providers;

use App\Models\JobCategory;
use App\Models\Location;
use App\Services\PublicCatalog;
use App\Services\SupabasePostgresConnector;
use App\Services\TrafficProtection;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('db.connector.pgsql', SupabasePostgresConnector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('partials.pagination');
        Paginator::defaultSimpleView('partials.pagination');
        RateLimiter::for('password-recovery-mfa', static function (Request $request): array {
            $protection = app(TrafficProtection::class);

            return [
                Limit::perMinutes(5, 5)->by($protection->key('recovery-mfa-account', (string) $request->session()->get('password_recovery.user_id', 'guest'))),
                Limit::perMinutes(5, 20)->by($protection->key('recovery-mfa-ip', $protection->ip($request))),
            ];
        });
        RateLimiter::for('admin-mfa', static fn (Request $request): array => [
            Limit::perMinutes(5, 5)->by('mfa-account:'.$request->user()?->id),
            Limit::perMinutes(5, 20)->by(app(TrafficProtection::class)->key('mfa-ip', app(TrafficProtection::class)->ip($request))),
        ]);
        RateLimiter::for('password-recovery', static function (Request $request): array {
            $protection = app(TrafficProtection::class);

            return [
                Limit::perMinutes(15, 5)->by($protection->key('recovery-ip', $protection->ip($request))),
                Limit::perMinutes(15, 3)->by($protection->key('recovery-account', $protection->account($request))),
            ];
        });
        RateLimiter::for('cv-uploads', static fn (Request $request): array => [
            Limit::perMinute(10)->by('cv-minute:'.$request->user()?->id),
            Limit::perHour(30)->by('cv-hour:'.$request->user()?->id),
        ]);
        foreach ([JobCategory::class, Location::class] as $model) {
            $invalidate = static fn (Model $record) => app(PublicCatalog::class)->invalidateAfterCommit($record);
            $model::saved($invalidate);
            $model::deleted($invalidate);
        }
    }
}
