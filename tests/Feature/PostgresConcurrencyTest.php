<?php

namespace Tests\Feature;

use App\Models\ApplicationStatusHistory;
use App\Models\CandidateProfile;
use App\Models\CvDocument;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres-concurrency')]
class PostgresConcurrencyTest extends TestCase
{
    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_PG_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Opt-in: RUN_PG_CONCURRENCY=1 with the separate internship_concurrency_test database.');
        }
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertSame('internship_concurrency_test', $connection->getDatabaseName());
        $this->assertSame('127.0.0.1', $connection->getConfig('host'));
        $this->assertSame('54329', (string) $connection->getConfig('port'));
        $this->assertSame('internship_concurrency_test', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed for the separate worker connections.');
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        DB::statement('TRUNCATE TABLE reports, account_status_histories, moderation_actions, application_status_histories, applications, job_postings, locations, job_categories, cv_documents, companies, candidate_profiles, users RESTART IDENTITY CASCADE');
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0.5);
            }
        }
        if (isset($this->app)) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        parent::tearDown();
    }

    public function test_two_parallel_submissions_commit_exactly_one_application_and_initial_history(): void
    {
        [$candidate, $cv, $job] = $this->fixture();
        DB::beginTransaction();
        User::whereKey($job->company->owner_user_id)->lockForUpdate()->firstOrFail();
        [$first] = $this->worker('duplicate_a', 'apply', $candidate, $job, $cv);
        $this->waitForDatabaseLock('duplicate_a');
        [$second] = $this->worker('duplicate_b', 'apply', $candidate, $job, $cv);
        $this->waitForDatabaseLock('duplicate_b');
        $this->assertTrue($first->isRunning() && $second->isRunning(), 'Both PHP requests must overlap before releasing the gate.');
        DB::commit();

        $firstResult = $this->completed($first);
        $secondResult = $this->completed($second);
        $this->assertSame(302, $firstResult['status']);
        $this->assertSame(302, $secondResult['status']);
        $this->assertSame($firstResult['location'], $secondResult['location']);
        $this->assertSame(1, JobApplication::count());
        $application = JobApplication::sole();
        $this->assertSame($candidate->id, $application->candidate_id);
        $this->assertSame($cv->id, $application->cv_id);
        $this->assertSame(1, ApplicationStatusHistory::count());
        $this->assertSame('SUBMITTED', $application->history->sole()->to_status);
    }

    /** @return array<string, array{string, bool}> */
    public static function opposingActions(): array
    {
        return [
            'close commits before submission' => ['close', false],
            'hide commits before submission' => ['hide', false],
            'submission commits before close' => ['close', true],
            'submission commits before hide' => ['hide', true],
        ];
    }

    #[DataProvider('opposingActions')]
    public function test_submission_serializes_with_closing_or_hiding_without_partial_history(string $action, bool $submitFirst): void
    {
        [$candidate, $cv, $job] = $this->fixture();
        $moderator = $action === 'hide' ? User::factory()->admin()->create() : $job->company->owner;
        $firstAction = $submitFirst ? 'apply' : $action;
        $secondAction = $submitFirst ? $action : 'apply';
        $firstActor = $submitFirst ? $candidate : $moderator;
        $secondActor = $submitFirst ? $moderator : $candidate;
        [$first, $barrier] = $this->worker('first_held', $firstAction, $firstActor, $job, $cv, true);
        $this->waitForHeldTransaction($first);
        [$second] = $this->worker('second_waiting', $secondAction, $secondActor, $job, $cv);
        $this->waitForDatabaseLock('second_waiting');
        $this->assertTrue($first->isRunning() && $second->isRunning(), 'The second request must wait for the first uncommitted action.');
        $barrier->write("COMMIT\n");
        $barrier->close();
        $firstResult = $this->completed($first);
        $secondResult = $this->completed($second);
        $this->assertSame(302, $firstResult['status']);
        $this->assertSame($submitFirst ? 302 : 422, $secondResult['status']);
        if (! $submitFirst) {
            $this->assertArrayHasKey('job', $secondResult['errors']);
        }
        $this->assertSame($submitFirst ? 1 : 0, JobApplication::count());
        $this->assertSame($submitFirst ? 1 : 0, ApplicationStatusHistory::count());
        $this->assertFalse(JobPosting::accepting()->whereKey($job->id)->exists());
        $this->assertSame($action === 'hide' ? 'HIDDEN' : 'APPROVED', $job->fresh()->moderation_state);
        $this->assertSame($action === 'close' ? 'CLOSED' : 'OPEN', $job->fresh()->publication_state);
        $this->assertSame($action === 'hide' ? 1 : 0, ModerationAction::count());
    }

    /** @return array{User, CvDocument, JobPosting} */
    private function fixture(): array
    {
        $profile = CandidateProfile::factory()->create();
        $cv = CvDocument::factory()->create(['candidate_id' => $profile->user_id]);
        $job = JobPosting::factory()->accepting()->create();

        return [$profile->user, $cv, $job];
    }

    /** @return array{Process, InputStream} */
    private function worker(string $name, string $action, User $actor, JobPosting $job, CvDocument $cv, bool $hold = false): array
    {
        $process = new Process([
            PHP_BINARY, base_path('tests/Support/concurrent_request.php'), $name, $action,
            $actor->id, (string) $job->id, (string) $cv->id, $hold ? 'hold' : 'run',
        ], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'RUN_PG_CONCURRENCY' => '1',
            'APP_KEY' => config('app.key') ?: 'base64:'.base64_encode(random_bytes(32)),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '54329',
            'DB_DATABASE' => 'internship_concurrency_test', 'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'), 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr',
        ], null, 20);
        $input = new InputStream;
        $process->setInput($input);
        $process->start();
        $this->workers[] = $process;

        return [$process, $input];
    }

    private function waitForDatabaseLock(string $name): void
    {
        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $blocked = DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE datname = current_database() AND application_name = ? AND wait_event_type = 'Lock'", [$name]);
            if ((int) $blocked->total === 1) {
                $this->assertSame(1, (int) $blocked->total);

                return;
            }
            usleep(25000);
        } while (microtime(true) < $deadline);
        $diagnostic = array_map(fn (Process $worker): string => $worker->getOutput().' '.$worker->getErrorOutput(), $this->workers);
        $this->fail('Expected PostgreSQL row-lock wait for '.$name.'. '.implode(' | ', $diagnostic));
    }

    private function waitForHeldTransaction(Process $process): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($process->getOutput(), '"phase":"held"')) {
                return;
            }
            if (! $process->isRunning()) {
                $this->fail('Worker exited before transaction barrier: '.$process->getOutput().' '.$process->getErrorOutput());
            }
            usleep(25000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach the transaction barrier.');
    }

    /** @return array{phase: string, status: int, location: ?string, errors: array<string, mixed>} */
    private function completed(Process $process): array
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $lines = preg_split('/\R/', trim($process->getOutput()));
        $result = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('completed', $result['phase']);

        return $result;
    }
}
