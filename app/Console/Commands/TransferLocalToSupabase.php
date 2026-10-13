<?php

namespace App\Console\Commands;

use App\Models\CvDocument;
use App\Services\CvStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TransferLocalToSupabase extends Command
{
    protected $signature = 'app:transfer-local-to-supabase {--execute : Explicitly copy the local synthetic dataset to the prepared cloud schema}';

    protected $description = 'Copy local demo records, preserving relationships and verifying every private PDF against Supabase.';

    private const PROJECT = 'xwkfsxohvpebnaqqhuxz';

    private const JOURNAL = 'local-cloud-transfer.json';

    private const TABLES = ['users', 'candidate_profiles', 'companies', 'cv_documents', 'job_categories', 'locations', 'job_postings', 'applications', 'application_status_histories', 'moderation_actions', 'account_status_histories', 'reports'];

    private const USER_KEYS = ['users' => ['id'], 'candidate_profiles' => ['user_id'], 'companies' => ['owner_user_id'], 'cv_documents' => ['candidate_id'], 'applications' => ['candidate_id'], 'application_status_histories' => ['changed_by'], 'moderation_actions' => ['admin_id'], 'account_status_histories' => ['target_user_id', 'admin_id'], 'reports' => ['reporter_id', 'assigned_admin_id', 'resolved_by']];

    private array $journal = [];

    private string $stage = 'target validation';

    public function handle(): int
    {
        $lock = null;
        $previousDisk = config('supabase.cv_disk');
        try {
            if (! $this->option('execute')) {
                throw new RuntimeException('Explicit --execute is required. No data was changed.');
            }
            config(['supabase.cv_disk' => 'supabase']);
            $this->assertTargets();
            $disk = Storage::disk('local');
            $disk->makeDirectory('sample-import');
            $lock = fopen($disk->path('sample-import/transfer.lock'), 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another transfer is running.');
            }
            $this->stage = 'source snapshot';
            $snapshot = $this->source()->transaction(function (): array {
                if ($this->source()->getDriverName() === 'pgsql') {
                    $this->source()->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }

                return $this->readTables($this->source());
            });
            foreach ($snapshot['users'] as $user) {
                if (! $user['is_demo'] || ! str_ends_with($user['email'], '@example.test')) {
                    throw new RuntimeException('Only synthetic demo users with reserved example.test email addresses may be copied by this command.');
                }
            }
            if (! $snapshot['users']) {
                throw new RuntimeException('The local dataset is empty.');
            }
            $hashes = $this->sourceHashes($snapshot['cv_documents']);
            $fingerprint = hash('sha256', json_encode([$snapshot, $hashes], JSON_THROW_ON_ERROR));
            if ($disk->exists(self::JOURNAL)) {
                $this->journal = json_decode($disk->get(self::JOURNAL), true, 512, JSON_THROW_ON_ERROR);
                if (($this->journal['project'] ?? null) !== self::PROJECT || ($this->journal['fingerprint'] ?? null) !== $fingerprint) {
                    throw new RuntimeException('The existing transfer journal does not match this project/source snapshot. No remote records were overwritten.');
                }
            } else {
                $this->journal = ['project' => self::PROJECT, 'batch' => (string) Str::uuid(), 'fingerprint' => $fingerprint, 'snapshot' => $snapshot, 'hashes' => $hashes, 'users' => [], 'cvs' => [], 'completed' => false];
                $this->save();
            }
            $this->stage = 'target conflict check';
            $remote = $this->readTables($this->target());
            $hasRows = collect($remote)->contains(fn (array $rows): bool => count($rows) > 0);
            if ($hasRows) {
                $expected = $this->mappedSnapshot();
                if ($expected !== $remote) {
                    throw new RuntimeException('The cloud schema contains records different from this transfer. Refusing to merge, overwrite or delete them.');
                }
            }
            $this->stage = 'Auth identity transfer';
            $this->transferIdentities($snapshot['users']);
            $this->stage = 'private PDF transfer and SHA-256 readback';
            $this->transferCvs($snapshot['cv_documents']);
            $expected = $this->mappedSnapshot();
            if (! $hasRows) {
                $this->stage = 'cloud database transaction';
                $this->target()->transaction(function () use ($expected): void {
                    if ($this->target()->getDriverName() === 'pgsql') {
                        $this->target()->statement('LOCK TABLE '.implode(', ', self::TABLES).' IN EXCLUSIVE MODE');
                    }
                    foreach (self::TABLES as $table) {
                        if ($this->target()->table($table)->exists()) {
                            throw new RuntimeException('Cloud data appeared during the transfer. Transaction cancelled.');
                        }
                    }
                    foreach ($expected as $table => $rows) {
                        foreach (array_chunk($rows, 100) as $chunk) {
                            $this->target()->table($table)->insert($chunk);
                        }
                        if ($this->target()->getDriverName() === 'pgsql' && ! in_array($table, ['users', 'candidate_profiles'], true)) {
                            $this->target()->select("SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1), EXISTS(SELECT 1 FROM {$table}))", [$table]);
                        }
                    }
                });
            }
            $this->stage = 'database readback';
            if ($expected !== $this->readTables($this->target())) {
                throw new RuntimeException('Cloud readback differs from the expected transferred dataset.');
            }
            $this->journal['completed'] = true;
            $this->journal['verified_at'] = now()->toIso8601String();
            $this->save();
            foreach ($expected as $table => $rows) {
                $this->line($table.': '.count($rows));
            }
            $this->info('Transfer verified: Auth identities, all business rows, and every private PDF SHA-256 match. Local data was not changed.');
            $this->line('Private journal and generated test passwords: storage/app/private/'.self::JOURNAL);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Transfer stopped at '.$this->stage.'. Credentials and provider response bodies were not logged. Rerun resumes from the private journal.');

            return self::FAILURE;
        } finally {
            config(['supabase.cv_disk' => $previousDisk]);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    protected function source(): Connection
    {
        return DB::connection('pgsql');
    }

    protected function target(): Connection
    {
        return DB::connection('supabase');
    }

    protected function assertTargets(): void
    {
        $source = $this->source();
        $target = $this->target();
        $host = $target->getConfig('host');
        $projectMatches = $host === 'db.'.self::PROJECT.'.supabase.co' || (is_string($host) && str_ends_with($host, '.pooler.supabase.com') && $target->getConfig('username') === 'postgres.'.self::PROJECT);
        if (! app()->environment('local') || $source->getDriverName() !== 'pgsql' || $source->getConfig('host') !== '127.0.0.1' || (string) $source->getConfig('port') !== '54329' || $source->getDatabaseName() !== 'internship_dev'
            || $target->getDriverName() !== 'pgsql' || ! $projectMatches || (string) $target->getConfig('port') !== '5432' || $target->getConfig('search_path') !== 'internbridge' || $target->getConfig('sslmode') !== 'require'
            || rtrim((string) config('supabase.url'), '/') !== 'https://'.self::PROJECT.'.supabase.co' || config('supabase.cv_disk') !== 'supabase'
            || ! preg_match('/^sb_secret_[A-Za-z0-9_-]{20,}$/D', (string) config('supabase.secret_key'))) {
            throw new RuntimeException('Transfer requires the exact local internship_dev source, intended Supabase project/schema, TLS, full server secret and Supabase CV disk.');
        }
        $bucket = (string) config('supabase.storage_bucket');
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D', $bucket)) {
            throw new RuntimeException('Invalid bucket configuration.');
        }
        $response = $this->client()->get(config('supabase.url').'/storage/v1/bucket/'.$bucket);
        if (! $response->successful() || $response->json('public') !== false) {
            throw new RuntimeException('The destination CV bucket must already exist and be private.');
        }
    }

    private function readTables(Connection $connection): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $snapshot[$table] = $connection->table($table)->orderBy($table === 'candidate_profiles' ? 'user_id' : 'id')->get()->map(function (object $row) use ($table): array {
                $result = (array) $row;
                if ($table === 'users') {
                    $result['is_demo'] = (bool) $result['is_demo'];
                }

                return $result;
            })->all();
        }

        return $snapshot;
    }

    private function sourceHashes(array $cvs): array
    {
        $hashes = [];
        foreach ($cvs as $cv) {
            if (($cv['disk'] ?? 'local') !== 'local' || ! preg_match('/^cvs\/(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_-]+\.pdf$/D', $cv['storage_key'])) {
                throw new RuntimeException('Source CV must use a valid private local path.');
            }
            $path = Storage::disk('local')->path($cv['storage_key']);
            if (! is_file($path) || filesize($path) !== (int) $cv['size_bytes'] || filesize($path) > 5242880 || file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
                throw new RuntimeException('A source CV is missing or does not match its database metadata.');
            }
            $hashes[$cv['id']] = hash_file('sha256', $path);
        }

        return $hashes;
    }

    private function transferIdentities(array $users): void
    {
        $url = config('supabase.url').'/auth/v1/admin/users';
        $existing = [];
        for ($page = 1; $page <= 100; $page++) {
            $response = $this->client()->get($url, ['page' => $page, 'per_page' => 100]);
            if (! $response->successful() || ! is_array($response->json('users'))) {
                throw new RuntimeException('Auth lookup failed. No provider response body was logged.');
            }
            foreach ($response->json('users') as $user) {
                $existing[strtolower($user['email'] ?? '')] = $user;
            }
            if (count($response->json('users')) < 100) {
                break;
            }
            if ($page === 100) {
                throw new RuntimeException('Auth lookup exceeded the bounded transfer limit.');
            }
        }
        foreach ($users as $user) {
            $found = $existing[strtolower($user['email'])] ?? null;
            if ($found && (($found['app_metadata']['transfer_batch'] ?? null) !== $this->journal['batch'] || ($found['app_metadata']['source_user_id'] ?? null) !== $user['id'] || ! isset($this->journal['users'][$user['id']]['password']))) {
                throw new RuntimeException('An Auth email conflicts with an account not owned by this transfer. No password or account was changed.');
            }
        }
        foreach ($users as $user) {
            $found = $existing[strtolower($user['email'])] ?? null;
            if (! isset($this->journal['users'][$user['id']])) {
                $this->journal['users'][$user['id']] = ['email' => $user['email'], 'role' => $user['role'], 'name' => $user['name'], 'password' => Str::random(48)];
                $this->save();
            }
            if (! $found) {
                $response = $this->client()->post($url, ['email' => $user['email'], 'password' => $this->journal['users'][$user['id']]['password'], 'email_confirm' => true,
                    'app_metadata' => ['transfer_batch' => $this->journal['batch'], 'source_user_id' => $user['id']], 'user_metadata' => ['full_name' => $user['name'], 'account_type' => $user['role'] === 'admin' ? 'employer' : $user['role']]]);
                if (! $response->successful()) {
                    throw new RuntimeException('Auth creation failed; its private credential journal has been preserved for retry.');
                }
                $found = $response->json();
            }
            if (! Str::isUuid($found['id'] ?? '')) {
                throw new RuntimeException('Auth returned an invalid UUID.');
            }
            if (isset($this->journal['users'][$user['id']]['id']) && $this->journal['users'][$user['id']]['id'] !== $found['id']) {
                throw new RuntimeException('Auth identity changed since the previous transfer attempt.');
            }
            $this->journal['users'][$user['id']]['id'] = $found['id'];
            $this->save();
        }
    }

    private function transferCvs(array $cvs): void
    {
        $storage = app(CvStorage::class);
        foreach ($cvs as $cv) {
            if (! isset($this->journal['cvs'][$cv['id']])) {
                $stored = $storage->store(new UploadedFile(Storage::disk('local')->path($cv['storage_key']), $cv['original_filename'], 'application/pdf', null, true));
                if ($stored['disk'] !== 'supabase') {
                    throw new RuntimeException('CV was not written to Supabase.');
                }
                $this->journal['cvs'][$cv['id']] = ['disk' => $stored['disk'], 'storage_key' => $stored['key'], 'sha256' => $this->journal['hashes'][$cv['id']]];
                $this->save();
            }
            $stored = $this->journal['cvs'][$cv['id']];
            $model = new CvDocument;
            $model->forceFill(['id' => $cv['id'], 'disk' => 'supabase', 'storage_key' => $stored['storage_key']]);
            $response = $storage->response($model);
            if ($response->getStatusCode() !== 200 || ! hash_equals($stored['sha256'], hash('sha256', $response->getContent()))) {
                throw new RuntimeException('The uploaded CV SHA-256 does not match its local source.');
            }
        }
    }

    private function mappedSnapshot(): array
    {
        $result = $this->journal['snapshot'];
        foreach ($result as $table => &$rows) {
            foreach ($rows as &$row) {
                foreach (self::USER_KEYS[$table] ?? [] as $key) {
                    if ($row[$key] !== null) {
                        $mapped = $this->journal['users'][$row[$key]]['id'] ?? null;
                        if (! $mapped) {
                            throw new RuntimeException('Cloud schema is nonempty while the transfer identity map is incomplete.');
                        }
                        $row[$key] = $mapped;
                    }
                }
                if ($table === 'users') {
                    $row['is_demo'] = false;
                }
                if ($table === 'cv_documents') {
                    $stored = $this->journal['cvs'][$row['id']] ?? null;
                    if (! $stored) {
                        throw new RuntimeException('Cloud schema is nonempty while the CV transfer map is incomplete.');
                    }
                    $row['disk'] = 'supabase';
                    $row['storage_key'] = $stored['storage_key'];
                }
            }
            unset($row);
            $key = $table === 'candidate_profiles' ? 'user_id' : 'id';
            usort($rows, fn (array $left, array $right): int => $left[$key] <=> $right[$key]);
        }
        unset($rows);

        return $result;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->withHeaders(['apikey' => config('supabase.secret_key')])->connectTimeout(5)->timeout(30)->withoutRedirecting();
    }

    private function save(): void
    {
        $path = Storage::disk('local')->path(self::JOURNAL);
        if (file_put_contents($path.'.tmp', json_encode($this->journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false || ! rename($path.'.tmp', $path)) {
            throw new RuntimeException('Could not persist the private transfer journal.');
        }
    }
}
