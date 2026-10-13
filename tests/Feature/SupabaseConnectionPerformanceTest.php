<?php

namespace Tests\Feature;

use App\Services\SupabasePostgresConnector;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class SupabaseConnectionPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the dedicated local PostgreSQL test database.');
        }
    }

    public function test_batched_connection_keeps_schema_timezone_and_encoding_in_one_setup_command(): void
    {
        $connection = $this->connector()->connect($this->localConfig());
        $settings = $connection->query("SELECT current_schema() AS schema, current_setting('TimeZone') AS timezone, current_setting('client_encoding') AS encoding")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(['schema' => 'public', 'timezone' => 'UTC', 'encoding' => 'UTF8'], $settings);
        $this->assertSame(1, $connection->setupCommands);
    }

    public function test_schema_quotes_and_sql_like_text_remain_identifiers(): void
    {
        $config = $this->localConfig();
        $config['search_path'] = ['odd"schema; SET TIME ZONE \'Europe/Paris\'; --', 'public'];
        $connection = $this->connector()->connect($config);

        $this->assertSame('UTC', $connection->query('SHOW TIME ZONE')->fetchColumn());
        $this->assertSame('public', $connection->query('SELECT current_schema()')->fetchColumn());
        $this->assertSame('"odd""schema; SET TIME ZONE \'Europe/Paris\'; --", public', $connection->query('SHOW search_path')->fetchColumn());
        $this->assertSame(1, $connection->setupCommands);
    }

    public function test_other_connections_keep_the_framework_setup(): void
    {
        $config = $this->localConfig();
        $config['name'] = 'pgsql';
        $config['timezone'] = 'Asia/Bangkok';
        $connection = $this->connector()->connect($config);

        $this->assertSame('Asia/Bangkok', $connection->query('SHOW TIME ZONE')->fetchColumn());
        $this->assertSame('public', $connection->query('SELECT current_schema()')->fetchColumn());
        $this->assertSame(0, $connection->setupCommands);
    }

    public function test_legacy_schema_configuration_is_preserved(): void
    {
        $config = $this->localConfig();
        unset($config['search_path'], $config['timezone']);
        $config['schema'] = 'pg_catalog,public';
        $connection = $this->connector()->connect($config);

        $this->assertSame('pg_catalog', $connection->query('SELECT current_schema()')->fetchColumn());
        $this->assertSame(1, $connection->setupCommands);
    }

    /** @return array<string, mixed> */
    private function localConfig(): array
    {
        return array_replace(DB::connection()->getConfig(), ['name' => 'supabase', 'search_path' => 'public', 'timezone' => 'UTC']);
    }

    private function connector(): SupabasePostgresConnector
    {
        return new class extends SupabasePostgresConnector
        {
            public function createConnection($dsn, array $config, array $options): PDO
            {
                return new class($dsn, $config['username'], $config['password'], $options) extends PDO
                {
                    public int $setupCommands = 0;

                    public function exec(string $statement): int|false
                    {
                        $this->setupCommands++;

                        return parent::exec($statement);
                    }
                };
            }
        };
    }
}
