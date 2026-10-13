<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function adminMfaSession(User $admin): array
    {
        $claims = rtrim(strtr(base64_encode(json_encode(['sub' => $admin->id, 'aal' => 'aal2', 'exp' => now()->addHour()->timestamp])), '+/', '-_'), '=');

        return [
            'admin_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp, 'mfa' => true],
            'supabase_session' => ['access_token' => 'eyJhbGciOiJIUzI1NiJ9.'.$claims.'.test-signature', 'refresh_token' => 'test-refresh', 'expires_at' => now()->addHour()->timestamp],
        ];
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();
        $safeSqlite = $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        $safePostgres = $connection->getDriverName() === 'pgsql'
            && $connection->getConfig('host') === '127.0.0.1'
            && (string) $connection->getConfig('port') === '54329'
            && in_array($connection->getDatabaseName(), ['internship_test', 'internship_concurrency_test'], true);
        if (! $app->environment('testing') || (! $safeSqlite && ! $safePostgres)) {
            throw new RuntimeException('Tests require SQLite :memory: or the dedicated local PostgreSQL test databases. Refusing to touch development/remote data.');
        }

        return $app;
    }
}
