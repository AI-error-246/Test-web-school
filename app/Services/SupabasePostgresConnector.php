<?php

namespace App\Services;

use Illuminate\Database\Connectors\PostgresConnector;
use PDO;

class SupabasePostgresConnector extends PostgresConnector
{
    public function connect(array $config): PDO
    {
        if (($config['name'] ?? null) !== 'supabase') {
            return parent::connect($config);
        }

        $connectionConfig = $config;
        unset($connectionConfig['timezone'], $connectionConfig['search_path'], $connectionConfig['schema']);
        $connection = parent::connect($connectionConfig);
        $statements = [];

        if (isset($config['timezone'])) {
            $statements[] = 'SET TIME ZONE '.$connection->quote($config['timezone']);
        }

        if (isset($config['search_path']) || isset($config['schema'])) {
            $schemas = $this->parseSearchPath($config['search_path'] ?? $config['schema']);
            $identifiers = array_map(static fn (string $schema): string => '"'.str_replace('"', '""', $schema).'"', $schemas);
            $statements[] = 'SET search_path TO '.implode(', ', $identifiers);
        }

        if ($statements !== []) {
            $connection->exec(implode('; ', $statements));
        }

        return $connection;
    }
}
