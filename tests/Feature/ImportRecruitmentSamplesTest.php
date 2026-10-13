<?php

namespace Tests\Feature;

use App\Console\Commands\ImportRecruitmentSamples;
use App\Models\CvDocument;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportRecruitmentSamplesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_requires_exactly_one_explicit_target_and_never_defaults_to_remote(): void
    {
        Http::preventStrayRequests();
        $this->artisan('app:import-recruitment-samples')->assertFailed();
        $this->artisan('app:import-recruitment-samples', ['--local' => true, '--supabase' => true])->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_refuses_local_mode_in_production_and_supabase_mode_without_matching_database(): void
    {
        Http::preventStrayRequests();
        $this->app->instance('env', 'production');
        $this->artisan('app:import-recruitment-samples', ['--local' => true])->assertFailed();
        $this->artisan('app:import-recruitment-samples', ['--supabase' => true])->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_imports_linked_private_cvs_without_applications_and_preserves_edited_records_on_rerun(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        $dataset = $this->dataset();
        $this->artisan('app:import-recruitment-samples', ['--local' => true, '--dataset' => $dataset])->assertSuccessful();
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('job_postings', 1);
        $this->assertDatabaseCount('cv_documents', 1);
        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseCount('moderation_actions', 1);
        $cv = CvDocument::firstOrFail();
        Storage::disk('local')->assertExists($cv->storage_key);
        $this->assertSame('local', $cv->disk);
        $this->assertSame('Mai An', $cv->candidate->name);
        $this->assertTrue($cv->candidate->is_demo);
        $cv->update(['archived_at' => now()]);
        JobPosting::firstOrFail()->update(['title' => 'Edited by employer', 'publication_state' => 'CLOSED', 'version' => 2]);
        $this->artisan('app:import-recruitment-samples', ['--local' => true, '--dataset' => $dataset])->assertSuccessful();
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('cv_documents', 1);
        $this->assertDatabaseCount('job_postings', 1);
        $this->assertDatabaseCount('moderation_actions', 1);
        $this->assertDatabaseHas('job_postings', ['title' => 'Edited by employer', 'publication_state' => 'CLOSED', 'version' => 2]);
        $this->assertNotNull($cv->fresh()->archived_at);
        Http::assertNothingSent();
    }

    public function test_missing_pdf_is_rejected_before_any_records_are_created(): void
    {
        $dataset = $this->dataset();
        $data = json_decode(file_get_contents($dataset), true);
        $data['candidates'][0]['pdf'] = '../outside-workspace.pdf';
        file_put_contents($dataset, json_encode($data));
        $this->artisan('app:import-recruitment-samples', ['--local' => true, '--dataset' => $dataset])->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('cv_documents', 0);
    }

    public function test_does_not_claim_an_existing_real_user_with_a_sample_email(): void
    {
        $dataset = $this->dataset();
        $user = User::factory()->create(['email' => 'mai.an@example.test', 'is_demo' => false]);
        $this->artisan('app:import-recruitment-samples', ['--local' => true, '--dataset' => $dataset])->assertFailed();
        $this->assertFalse($user->fresh()->is_demo);
        $this->assertDatabaseCount('candidate_profiles', 0);
    }

    public function test_cloud_auth_creates_confirmed_synthetic_users_without_emails_or_exposing_credentials(): void
    {
        $dataset = $this->dataset();
        $this->registerCloudTestCommand();
        Http::preventStrayRequests();
        $created = [];
        $ids = ['qa.admin@example.test' => '11111111-9999-4999-8999-111111111111', 'company@example.test' => '22222222-9999-4999-8999-222222222222', 'mai.an@example.test' => '33333333-9999-4999-8999-333333333333'];
        Http::fake(['https://xwkfsxohvpebnaqqhuxz.supabase.co/auth/v1/admin/users*' => function ($request) use (&$created, $ids) {
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertTrue($request->hasHeader('apikey', 'fake-test-server-key'));
            if ($request->method() === 'GET') {
                return Http::response(['users' => array_values($created)]);
            }
            $data = $request->data();
            $this->assertTrue($data['email_confirm']);
            $this->assertSame('internbridge-diverse-v1', $data['app_metadata']['sample_batch']);
            $this->assertNotSame('admin', $data['user_metadata']['account_type']);
            $created[$data['email']] = ['id' => $ids[$data['email']], 'email' => $data['email'], 'app_metadata' => $data['app_metadata']];

            return Http::response($created[$data['email']], 201);
        }]);
        $this->artisan('app:import-recruitment-samples', ['--supabase' => true, '--dataset' => $dataset])->assertSuccessful();
        $credentials = json_decode(Storage::disk('local')->get('sample-credentials.json'), true);
        $this->assertCount(3, $credentials);
        $this->assertGreaterThanOrEqual(32, strlen($credentials['mai.an@example.test']['password']));
        $this->assertDatabaseHas('users', ['id' => $ids['mai.an@example.test'], 'email' => 'mai.an@example.test', 'is_demo' => false]);
        $this->artisan('app:import-recruitment-samples', ['--supabase' => true, '--dataset' => $dataset])->assertSuccessful();
        Http::assertSentCount(9);
        $this->assertDatabaseCount('users', 3);
        $manifest = Storage::disk('local')->get('recruitment-samples-supabase.json');
        $this->assertStringNotContainsString('password', $manifest);
        $this->assertStringNotContainsString($credentials['mai.an@example.test']['password'], Artisan::output());
    }

    public function test_cloud_auth_does_not_claim_an_existing_unmarked_account(): void
    {
        $dataset = $this->dataset();
        $this->registerCloudTestCommand();
        Http::preventStrayRequests();
        Http::fake(['https://xwkfsxohvpebnaqqhuxz.supabase.co/auth/v1/admin/users*' => Http::response(['users' => [['id' => '11111111-9999-4999-8999-111111111111', 'email' => 'qa.admin@example.test', 'app_metadata' => []]]])]);
        $this->artisan('app:import-recruitment-samples', ['--supabase' => true, '--dataset' => $dataset])->assertFailed();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_cloud_auth_does_not_invent_a_password_when_an_existing_sample_journal_is_missing(): void
    {
        $dataset = $this->dataset();
        $this->registerCloudTestCommand();
        Http::preventStrayRequests();
        Http::fake(['https://xwkfsxohvpebnaqqhuxz.supabase.co/auth/v1/admin/users*' => Http::response(['users' => [['id' => '11111111-9999-4999-8999-111111111111', 'email' => 'qa.admin@example.test', 'app_metadata' => ['sample_batch' => 'internbridge-diverse-v1', 'sample_key' => 'sample-admin']]]])]);
        $this->artisan('app:import-recruitment-samples', ['--supabase' => true, '--dataset' => $dataset])->assertFailed();
        Http::assertSentCount(1);
        Storage::disk('local')->assertMissing('sample-credentials.json');
        $this->assertDatabaseCount('users', 0);
    }

    private function registerCloudTestCommand(): void
    {
        config(['supabase.url' => 'https://xwkfsxohvpebnaqqhuxz.supabase.co', 'supabase.secret_key' => 'fake-test-server-key']);
        // Isolate target selection from cloud Auth behavior; the real guard is tested separately.
        Artisan::registerCommand(new class extends ImportRecruitmentSamples
        {
            protected function assertTarget(): void {}
        });
    }

    private function dataset(): string
    {
        Storage::fake('local');
        config(['supabase.cv_disk' => 'local']);
        Storage::disk('local')->put('sample.pdf', "%PDF-1.4\nSynthetic test CV\n%%EOF");
        $root = dirname(base_path(), 3);
        $pdf = str_replace('\\', '/', substr(Storage::disk('local')->path('sample.pdf'), strlen($root) + 1));
        $data = [
            'candidates' => [['key' => 'mai-an', 'name' => 'Mai An', 'email' => 'mai.an@example.test', 'phone' => '0000000011', 'university' => 'Synthetic University', 'major' => 'Software', 'study_year' => 3, 'pdf' => $pdf, 'preferred_job_keys' => ['backend']]],
            'companies' => [['key' => 'company', 'employer_name' => 'Synthetic Owner', 'email' => 'company@example.test', 'name' => 'Synthetic Company']],
            'jobs' => [['key' => 'backend', 'company_key' => 'company', 'category' => 'Software', 'location_code' => 'TEST', 'location_name' => 'Test City', 'title' => 'Backend intern', 'description' => 'Work with a mentor.', 'requirements' => 'Basic PHP.', 'benefits' => 'Flexible schedule.', 'work_mode' => 'HYBRID', 'allowance_min' => 2000000, 'allowance_max' => 3000000, 'deadline_days' => 30]],
        ];
        Storage::disk('local')->put('dataset.json', json_encode($data));

        return Storage::disk('local')->path('dataset.json');
    }
}
