<?php

namespace App\Console\Commands;

use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CvDocument;
use App\Models\JobCategory;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\ModerationAction;
use App\Models\User;
use App\Services\CvStorage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

class ImportRecruitmentSamples extends Command
{
    private const BATCH = 'internbridge-diverse-v1';

    private const PROJECT = 'xwkfsxohvpebnaqqhuxz';

    protected $signature = 'app:import-recruitment-samples {--local : Import into the isolated local database} {--supabase : Import into the configured Supabase project} {--dataset= : Dataset JSON path}';

    protected $description = 'Import synthetic recruitment records and private CVs without resetting existing records.';

    private array $manifest = [];

    private string $manifestKey;

    private bool $cloud;

    public function handle(): int
    {
        $lock = null;
        try {
            if ((bool) $this->option('local') === (bool) $this->option('supabase')) {
                throw new RuntimeException('Choose exactly one explicit mode: --local or --supabase.');
            }
            $this->cloud = (bool) $this->option('supabase');
            $this->assertTarget();
            $dataset = $this->readDataset();
            $disk = Storage::disk('local');
            $disk->makeDirectory('sample-import');
            $lock = fopen($disk->path('sample-import/import.lock'), 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another sample import is running.');
            }
            $this->manifestKey = 'recruitment-samples-'.($this->cloud ? 'supabase' : 'local').'.json';
            $this->manifest = $disk->exists($this->manifestKey) ? json_decode($disk->get($this->manifestKey), true, 512, JSON_THROW_ON_ERROR) : ['batch' => self::BATCH, 'users' => [], 'jobs' => [], 'cvs' => []];
            if (($this->manifest['batch'] ?? null) !== self::BATCH) {
                throw new RuntimeException('Sample manifest belongs to another batch.');
            }
            $admin = $this->importUser('sample-admin', 'admin', 'Quản trị kiểm thử', 'qa.admin@example.test');
            $companies = [];
            foreach ($dataset['companies'] as $company) {
                $owner = $this->importUser($company['key'], 'employer', $company['employer_name'], $company['email']);
                $companies[$company['key']] = Company::firstOrCreate(['owner_user_id' => $owner->id], array_merge(array_intersect_key($company, array_flip(['name', 'description', 'website', 'address', 'contact_email'])), ['visibility' => 'VISIBLE']));
            }
            foreach ($dataset['jobs'] as $job) {
                $company = $companies[$job['company_key']];
                $existingId = $this->manifest['jobs'][$job['key']]['id'] ?? null;
                $posting = $existingId ? JobPosting::where('company_id', $company->id)->findOrFail($existingId) : null;
                if (! $posting) {
                    $posting = DB::transaction(function () use ($job, $company, $admin): JobPosting {
                        $category = JobCategory::firstOrCreate(['name' => $job['category']]);
                        $location = Location::firstOrCreate(['code' => $job['location_code']], ['name' => $job['location_name']]);
                        $posting = JobPosting::firstOrCreate(['company_id' => $company->id, 'title' => $job['title']], array_merge(array_intersect_key($job, array_flip(['description', 'requirements', 'benefits', 'work_mode', 'allowance_min', 'allowance_max'])), [
                            'category_id' => $category->id, 'location_id' => $location->id, 'currency' => 'VND',
                            'deadline_at' => now()->addDays($job['deadline_days']), 'publication_state' => 'OPEN',
                            'moderation_state' => 'APPROVED', 'published_at' => now(), 'version' => 1,
                        ]));
                        if ($posting->wasRecentlyCreated) {
                            ModerationAction::create(['admin_id' => $admin->id, 'job_id' => $posting->id, 'action' => 'APPROVE', 'from_state' => 'PENDING', 'to_state' => 'APPROVED', 'reason' => 'Dữ liệu tuyển dụng giả lập được nhập để kiểm thử theo yêu cầu.', 'reviewed_version' => 1, 'created_at' => now()]);
                        }

                        return $posting;
                    });
                }
                $this->manifest['jobs'][$job['key']] = ['id' => $posting->id, 'company_id' => $company->id, 'employer_id' => $company->owner_user_id];
                $this->saveManifest();
            }
            foreach ($dataset['candidates'] as $candidate) {
                $user = $this->importUser($candidate['key'], 'candidate', $candidate['name'], $candidate['email']);
                CandidateProfile::firstOrCreate(['user_id' => $user->id], array_merge(array_intersect_key($candidate, array_flip(['phone', 'university', 'major', 'study_year', 'introduction', 'skills_text'])), ['full_name' => $candidate['name']]));
                $this->importCv($user, $candidate);
            }
            $this->info('Imported sample records. Applications must be submitted through the website.');
            $this->line('Private manifest: storage/app/private/'.$this->manifestKey);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Database and HTTP exceptions can contain credentials or request payloads.
            $this->error($exception instanceof RuntimeException && get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Import stopped safely. Check target configuration, dataset, migrations and storage; no existing records were reset.');

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    protected function assertTarget(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! $this->cloud) {
            $testDatabase = ($driver === 'sqlite' && $connection->getDatabaseName() === ':memory:')
                || ($driver === 'pgsql' && $connection->getConfig('host') === '127.0.0.1'
                    && (string) $connection->getConfig('port') === '54329'
                    && in_array($connection->getDatabaseName(), ['internship_test', 'internship_concurrency_test'], true));
            $test = app()->environment('testing') && $testDatabase;
            $local = app()->environment('local') && $driver === 'pgsql' && $connection->getConfig('host') === '127.0.0.1' && (string) $connection->getConfig('port') === '54329' && $connection->getDatabaseName() === 'internship_dev';
            if ((! $test && ! $local) || config('supabase.cv_disk', 'local') !== 'local') {
                throw new RuntimeException('Local mode requires the isolated internship_dev database and local CV storage.');
            }

            return;
        }
        $host = $connection->getConfig('host');
        $username = $connection->getConfig('username');
        $direct = $host === 'db.'.self::PROJECT.'.supabase.co';
        $pooler = is_string($host) && str_ends_with($host, '.pooler.supabase.com') && $username === 'postgres.'.self::PROJECT;
        $secret = config('supabase.secret_key');
        if (config('database.default') !== 'supabase' || $driver !== 'pgsql' || (! $direct && ! $pooler) || (string) $connection->getConfig('port') !== '5432' || $connection->getConfig('search_path') !== 'internbridge' || rtrim((string) config('supabase.url'), '/') !== 'https://'.self::PROJECT.'.supabase.co' || config('supabase.cv_disk') !== 'supabase' || ! is_string($secret) || ! preg_match('/^sb_secret_[A-Za-z0-9_-]{20,}$/D', $secret)) {
            throw new RuntimeException('Supabase mode requires the matching project database, private Supabase CV storage and a complete server secret key.');
        }
    }

    private function readDataset(): array
    {
        $root = dirname(base_path(), 3);
        $path = $this->option('dataset') ?: $root.'/PROJECT/06_Testing/diverse-samples/dataset.json';
        if (! is_file($path)) {
            throw new RuntimeException('Dataset JSON is missing.');
        }
        $dataset = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        Validator::make($dataset, [
            'candidates' => 'required|array|min:1|max:50', 'companies' => 'required|array|min:1|max:20', 'jobs' => 'required|array|min:1|max:100',
            'candidates.*.key' => 'required|string|distinct|regex:/^[a-z0-9-]+$/', 'candidates.*.name' => 'required|string|max:120', 'candidates.*.email' => 'required|email|distinct|ends_with:@example.test',
            'candidates.*.phone' => 'required|string|max:30', 'candidates.*.university' => 'required|string|max:255', 'candidates.*.major' => 'required|string|max:255', 'candidates.*.study_year' => 'required|integer|between:1,8', 'candidates.*.pdf' => 'required|string',
            'companies.*.key' => 'required|string|distinct|regex:/^[a-z0-9-]+$/', 'companies.*.email' => 'required|email|distinct|ends_with:@example.test', 'companies.*.employer_name' => 'required|string|max:120', 'companies.*.name' => 'required|string|max:255',
            'jobs.*.key' => 'required|string|distinct|regex:/^[a-z0-9-]+$/', 'jobs.*.company_key' => 'required|string', 'jobs.*.category' => 'required|string|max:255', 'jobs.*.location_code' => 'required|string|max:30', 'jobs.*.location_name' => 'required|string|max:255',
            'jobs.*.title' => 'required|string|max:255', 'jobs.*.description' => 'required|string', 'jobs.*.requirements' => 'required|string', 'jobs.*.benefits' => 'required|string', 'jobs.*.work_mode' => 'required|in:REMOTE,HYBRID,ONSITE', 'jobs.*.deadline_days' => 'required|integer|between:1,365', 'jobs.*.allowance_min' => 'nullable|numeric|min:0', 'jobs.*.allowance_max' => 'nullable|numeric|min:0',
        ])->validate();
        $keys = array_merge(array_column($dataset['candidates'], 'key'), array_column($dataset['companies'], 'key'), ['sample-admin']);
        $emails = array_merge(array_column($dataset['candidates'], 'email'), array_column($dataset['companies'], 'email'), ['qa.admin@example.test']);
        if (count($keys) !== count(array_unique($keys)) || count($emails) !== count(array_unique($emails))) {
            throw new RuntimeException('Sample identities must have distinct keys and emails.');
        }
        foreach ($dataset['jobs'] as $job) {
            if (! in_array($job['company_key'], array_column($dataset['companies'], 'key'), true)) {
                throw new RuntimeException('A job references an unknown company key.');
            }
        }
        foreach ($dataset['candidates'] as &$candidate) {
            $pdf = realpath($root.'/'.$candidate['pdf']);
            if (! $pdf || ! str_starts_with(strtolower($pdf), strtolower($root.DIRECTORY_SEPARATOR)) || ! is_file($pdf) || filesize($pdf) > 5 * 1024 * 1024 || file_get_contents($pdf, false, null, 0, 5) !== '%PDF-') {
                throw new RuntimeException('A sample PDF is missing, outside the workspace, invalid or larger than 5 MB.');
            }
            $candidate['pdf_absolute'] = $pdf;
        }

        return $dataset;
    }

    private function importUser(string $key, string $role, string $name, string $email): User
    {
        $id = $this->cloud ? $this->cloudIdentity($key, $role, $name, $email) : (string) Uuid::uuid5('6ba7b811-9dad-11d1-80b4-00c04fd430c8', self::BATCH.':'.$key);
        $existing = User::where('id', $id)->orWhere('email', $email)->first();
        if ($existing && ($existing->id !== $id || $existing->email !== $email || $existing->role !== $role || $existing->is_demo !== ! $this->cloud)) {
            throw new RuntimeException('An existing user conflicts with a sample identity; no account was changed.');
        }
        $user = User::firstOrCreate(['id' => $id], ['name' => $name, 'email' => $email, 'role' => $role, 'status' => 'ACTIVE', 'is_demo' => ! $this->cloud]);
        $this->manifest['users'][$key] = ['id' => $id, 'email' => $email, 'role' => $role];
        $this->saveManifest();

        return $user;
    }

    private function cloudIdentity(string $key, string $role, string $name, string $email): string
    {
        $disk = Storage::disk('local');
        $credentialKey = 'sample-credentials.json';
        $credentials = $disk->exists($credentialKey) ? json_decode($disk->get($credentialKey), true, 512, JSON_THROW_ON_ERROR) : [];
        if (isset($credentials[$email]) && ($credentials[$email]['batch'] ?? null) !== self::BATCH) {
            throw new RuntimeException('Existing credentials do not belong to this sample batch.');
        }
        $url = rtrim(config('supabase.url'), '/').'/auth/v1/admin/users';
        $client = Http::acceptJson()->connectTimeout(3)->timeout(20)->withoutRedirecting()->withHeaders(['apikey' => config('supabase.secret_key')]);
        $found = null;
        for ($page = 1; $page <= 20; $page++) {
            $response = $client->get($url, ['page' => $page, 'per_page' => 100]);
            if (! $response->successful() || ! is_array($response->json('users'))) {
                throw new RuntimeException('Supabase Auth user lookup failed; no response body was logged.');
            }
            $users = $response->json('users');
            foreach ($users as $user) {
                if (strtolower($user['email'] ?? '') === strtolower($email)) {
                    $found = $user;
                    break 2;
                }
            }
            if (count($users) < 100) {
                break;
            }
            if ($page === 20) {
                throw new RuntimeException('Auth lookup exceeded the bounded sample-import limit.');
            }
        }
        if ($found) {
            if (($found['app_metadata']['sample_batch'] ?? null) !== self::BATCH || ($found['app_metadata']['sample_key'] ?? null) !== $key) {
                throw new RuntimeException('An existing Auth email belongs to another account; it was not changed.');
            }
            if (! isset($credentials[$email]['password'])) {
                throw new RuntimeException('A sample Auth account already exists but its private credential journal is missing; no password was reset.');
            }
        } else {
            if (! isset($credentials[$email])) {
                $credentials[$email] = ['password' => Str::random(48), 'batch' => self::BATCH];
                $this->saveJson($credentialKey, $credentials);
            }
            $response = $client->post($url, ['email' => $email, 'password' => $credentials[$email]['password'], 'email_confirm' => true, 'app_metadata' => ['sample_batch' => self::BATCH, 'sample_key' => $key], 'user_metadata' => ['full_name' => $name, 'account_type' => $role === 'admin' ? 'employer' : $role]]);
            if (! $response->successful()) {
                throw new RuntimeException('Supabase Auth sample creation failed; credentials and response body were not logged.');
            }
            $found = $response->json();
        }
        if (! is_string($found['id'] ?? null) || ! Str::isUuid($found['id'])) {
            throw new RuntimeException('Supabase Auth returned no valid identity.');
        }

        return $found['id'];
    }

    private function importCv(User $user, array $candidate): void
    {
        $existingId = $this->manifest['cvs'][$candidate['key']]['id'] ?? null;
        $cv = $existingId ? CvDocument::where('candidate_id', $user->id)->findOrFail($existingId) : CvDocument::where('candidate_id', $user->id)->where('original_filename', basename($candidate['pdf_absolute']))->first();
        if (! $cv) {
            $storage = app(CvStorage::class);
            $stored = $storage->store(new UploadedFile($candidate['pdf_absolute'], basename($candidate['pdf_absolute']), 'application/pdf', null, true));
            try {
                $cv = CvDocument::create(['candidate_id' => $user->id, 'display_name' => 'CV — '.$user->name, 'disk' => $stored['disk'], 'storage_key' => $stored['key'], 'original_filename' => basename($candidate['pdf_absolute']), 'mime_type' => 'application/pdf', 'size_bytes' => filesize($candidate['pdf_absolute']), 'uploaded_at' => now()]);
            } catch (Throwable $exception) {
                $storage->delete($stored['disk'], $stored['key']);
                throw $exception;
            }
        }
        $this->manifest['cvs'][$candidate['key']] = ['id' => $cv->id, 'candidate_id' => $user->id, 'disk' => $cv->disk, 'storage_key' => $cv->storage_key, 'preferred_job_keys' => $candidate['preferred_job_keys'] ?? []];
        $this->saveManifest();
    }

    private function saveManifest(): void
    {
        $this->saveJson($this->manifestKey, $this->manifest);
    }

    private function saveJson(string $key, array $data): void
    {
        $path = Storage::disk('local')->path($key);
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false || ! rename($temporary, $path)) {
            throw new RuntimeException('Unable to persist the private import journal.');
        }
    }
}
