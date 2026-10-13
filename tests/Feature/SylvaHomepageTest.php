<?php

namespace Tests\Feature;

use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SylvaHomepageTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['vi', 'Đổi giao diện'])]
    #[TestWith(['en', 'Change appearance'])]
    #[TestWith(['ja', '表示モードを切り替え'])]
    public function test_homepage_has_a_localized_appearance_toggle_and_retains_recruitment_content(string $locale, string $label): void
    {
        $job = JobPosting::factory()->accepting()->create(['title' => 'Internship with mentoring']);
        $this->withSession(['locale' => $locale])->get(route('home'))->assertOk()
            ->assertSee($label)
            ->assertSee('data-theme-toggle', false)
            ->assertSee('data-sylva-host', false)
            ->assertSeeInOrder(['data-sylva-host', '<header class="site-header">'], false)
            ->assertSee('sylva-entry', false)
            ->assertSee('id="hero-query" name="q"', false)
            ->assertSee('action="'.route('jobs.index').'" method="get"', false)
            ->assertSee($job->title)
            ->assertSee(route('jobs.show', $job), false)
            ->assertDontSee('data-sylva-toggle', false)
            ->assertDontSee('ambient-sparkles', false)
            ->assertDontSee('hero-particles.js', false);
    }

    public function test_non_home_pages_use_sparkles_and_the_appearance_toggle_without_the_scene_bundle(): void
    {
        $this->get(route('jobs.index'))->assertOk()
            ->assertSee('data-theme-toggle', false)
            ->assertSee('class="ambient-sparkles" aria-hidden="true"', false)
            ->assertDontSee('sylva-entry', false)
            ->assertDontSee('data-sylva-host', false);
        $this->actingAs(User::factory()->candidate()->create())->get(route('candidate.profile'))->assertOk()
            ->assertSee('data-theme-toggle', false)
            ->assertSee('class="ambient-sparkles" aria-hidden="true"', false)
            ->assertDontSee('sylva-entry', false)
            ->assertDontSee('data-sylva-host', false);
    }

    public function test_original_font_is_available_to_the_opaque_sandbox_without_exposing_other_files(): void
    {
        $this->get(route('sylva.font'), ['Origin' => 'null'])->assertOk()
            ->assertHeader('Content-Type', 'font/woff2')
            ->assertHeader('Access-Control-Allow-Origin', '*');
        $this->get('/inner-green-assets/other.woff2')->assertNotFound();
    }
}
