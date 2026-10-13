<?php

namespace Tests\Feature;

use App\Models\JobCategory;
use App\Models\JobPosting;
use App\Models\Location;
use App\Services\PublicCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PublicCatalogPerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.demo_mode' => false, 'cache.default' => 'array']);
        Cache::flush();
    }

    public function test_warm_public_lists_use_at_most_two_queries_and_keep_filters_and_counts(): void
    {
        $job = JobPosting::factory()->accepting()->create(['title' => 'Laravel mentor']);
        JobPosting::factory()->accepting()->create(['title' => 'Accounting team']);
        $this->get(route('home'))->assertOk();
        $categoryName = $job->category->name;
        $locationName = $job->location->name;

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('home'))->assertOk()->assertViewHas('jobCount', 2)->assertViewHas('companyCount', 2)
            ->assertSee($categoryName)->assertSee($locationName);
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
        DB::flushQueryLog();
        $this->get(route('jobs.index', ['q' => 'Laravel', 'category' => $job->category_id, 'location' => $job->location_id, 'mode' => 'HYBRID']))
            ->assertOk()->assertSee('Laravel mentor')->assertDontSee('Accounting team')->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 1);
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
        DB::flushQueryLog();
        $this->get(route('jobs.show', $job))->assertOk()->assertSee($categoryName)->assertSee($locationName)
            ->assertSee($job->title);
        $this->assertLessThanOrEqual(1, count(DB::getQueryLog()));
        DB::flushQueryLog();
        $this->get(route('companies.show', $job->company_id))->assertOk()->assertSee($job->title)
            ->assertSee($categoryName)->assertSee($locationName);
        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_joined_company_data_preserves_job_identity_attributes_links_and_pagination(): void
    {
        $this->freezeTime();
        $job = JobPosting::factory()->accepting()->create(['id' => 101, 'description' => 'Job description only', 'published_at' => now()]);
        $job->company->update(['name' => 'Distinct Company', 'address' => 'Company address only', 'description' => 'Company description only']);
        JobPosting::factory()->accepting()->count(9)->create(['published_at' => now()->subDay()]);

        $this->get(route('home'))->assertOk()->assertSee(route('jobs.show', 101), false)
            ->assertSee(route('companies.show', $job->company_id), false);
        $this->get(route('jobs.show', 101))->assertOk()->assertSee('Job description only')->assertSee('Company address only')
            ->assertDontSee('Company description only')->assertViewHas('job', function (JobPosting $record) use ($job): bool {
                return $record->id === 101 && $record->company->id === $job->company_id
                    && $record->company->name === 'Distinct Company' && ! $record->isDirty();
            });
        $this->get(route('jobs.index'))->assertOk()->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 10 && $jobs->count() === 9 && $jobs->first()->id === 101);
        $this->get(route('jobs.index', ['page' => 2]))->assertOk()->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 10 && $jobs->count() === 1)
            ->assertDontSee('Job description only');
        $this->get(route('jobs.index', ['q' => 'Distinct Company']))->assertOk()
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 1 && $jobs->first()->id === 101);
    }

    #[DataProvider('visibilityChanges')]
    public function test_warm_catalogs_never_cache_job_visibility_or_live_counts(string $change): void
    {
        $job = JobPosting::factory()->accepting()->create(['title' => 'Initially visible role']);
        $this->get(route('home'))->assertSee($job->title)->assertViewHas('jobCount', 1);
        $this->get(route('jobs.index'))->assertSee($job->title);
        match ($change) {
            'moderation' => $job->update(['moderation_state' => 'HIDDEN']),
            'company' => $job->company->update(['visibility' => 'HIDDEN']),
            'owner' => $job->company->owner->update(['status' => 'BLOCKED']),
            'closed' => $job->update(['publication_state' => 'CLOSED']),
            'expired' => $job->update(['deadline_at' => now()->subSecond()]),
        };

        $this->get(route('home'))->assertOk()->assertDontSee($job->title)->assertViewHas('jobCount', 0)
            ->assertViewHas('companyCount', in_array($change, ['company', 'owner'], true) ? 0 : 1);
        $this->get(route('jobs.index'))->assertOk()->assertDontSee($job->title)->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 0);
        if (in_array($change, ['moderation', 'company', 'owner'], true)) {
            $this->get(route('jobs.show', $job))->assertNotFound();
        } else {
            $this->get(route('jobs.show', $job))->assertOk()->assertSee($job->title);
        }
        if (in_array($change, ['company', 'owner'], true)) {
            $this->get(route('companies.show', $job->company_id))->assertNotFound();
        } else {
            $this->get(route('companies.show', $job->company_id))->assertOk()->assertDontSee($job->title);
        }
    }

    public static function visibilityChanges(): array
    {
        return [['moderation'], ['company'], ['owner'], ['closed'], ['expired']];
    }

    public function test_catalog_create_update_delete_invalidate_both_catalogs_after_commit(): void
    {
        $catalog = app(PublicCatalog::class);
        $this->assertCount(0, $catalog->categories());
        $this->assertCount(0, $catalog->locations());
        $category = JobCategory::factory()->create(['name' => 'Old category']);
        $location = Location::factory()->create(['name' => 'Old location']);
        $this->assertSame('Old category', $catalog->categories()->sole()->name);
        $this->assertSame('Old location', $catalog->locations()->sole()->name);

        DB::transaction(function () use ($category, $location, $catalog): void {
            $category->update(['name' => 'New category']);
            $location->update(['name' => 'New location']);
            $this->assertSame('Old category', $catalog->categories()->sole()->name);
            $this->assertSame('Old location', $catalog->locations()->sole()->name);
        });

        $this->assertSame('New category', $catalog->categories()->sole()->name);
        $this->assertSame('New location', $catalog->locations()->sole()->name);
        $category->delete();
        $location->delete();
        $this->assertCount(0, $catalog->categories());
        $this->assertCount(0, $catalog->locations());
    }

    public function test_rolled_back_catalog_mutation_does_not_pollute_cache(): void
    {
        $category = JobCategory::factory()->create(['name' => 'Committed name']);
        $catalog = app(PublicCatalog::class);
        $catalog->categories();
        try {
            DB::transaction(function () use ($category): void {
                $category->update(['name' => 'Rolled back name']);
                throw new RuntimeException('Rollback this test change');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('Committed name', $catalog->categories()->sole()->name);
        $this->assertDatabaseHas('job_categories', ['id' => $category->id, 'name' => 'Committed name']);
    }

    public function test_catalog_cache_is_partitioned_by_source_fingerprint_and_expires_after_five_minutes(): void
    {
        $category = JobCategory::factory()->create(['name' => 'First source']);
        $catalog = app(PublicCatalog::class);
        $catalog->categories();
        $sourceKey = 'database.connections.'.DB::connection()->getName().'.url';
        $originalUrl = config($sourceKey);
        DB::table('job_categories')->where('id', $category->id)->update(['name' => 'Second source']);

        config([$sourceKey => 'postgresql://example.invalid/test-source-fingerprint']);
        $this->assertSame('Second source', $catalog->categories()->sole()->name);
        config([$sourceKey => $originalUrl]);
        $this->assertSame('First source', $catalog->categories()->sole()->name);
        $this->travel(301)->seconds();
        $this->assertSame('Second source', $catalog->categories()->sole()->name);
    }
}
