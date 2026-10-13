<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\User;
use App\Services\CvMalwareScanner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CvUploadRateLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(CvMalwareScanner::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andReturn(['status' => 'CLEAN', 'engine' => 'test', 'reason' => null]));
    }

    public function test_minute_limit_blocks_storage_writes_and_does_not_block_another_candidate(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Storage::fake('local');
        $candidate = User::factory()->candidate()->create();
        $other = User::factory()->candidate()->create();
        CandidateProfile::factory()->create(['user_id' => $other->id]);
        $this->actingAs($candidate);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/candidate/cvs', [])->assertUnprocessable();
        }

        $this->post('/candidate/cvs', $this->upload())->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('cv_documents', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());

        $this->actingAs($other)->post('/candidate/cvs', $this->upload())->assertRedirect();
        $this->assertDatabaseHas('cv_documents', ['candidate_id' => $other->id, 'display_name' => 'CV security test']);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_hourly_limit_survives_minute_resets_and_expires_after_one_hour(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Storage::fake('local');
        $candidate = User::factory()->candidate()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->actingAs($candidate);

        for ($batch = 0; $batch < 3; $batch++) {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $this->postJson('/candidate/cvs', [])->assertUnprocessable();
            }
            $this->travel(61)->seconds();
        }

        $this->post('/candidate/cvs', $this->upload())->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('cv_documents', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->travel(1)->hours();
        $this->post('/candidate/cvs', $this->upload())->assertRedirect();
        $this->assertDatabaseCount('cv_documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    /** @return array{display_name: string, cv: UploadedFile} */
    private function upload(): array
    {
        return ['display_name' => 'CV security test', 'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\nSecurity test\n%%EOF")];
    }
}
