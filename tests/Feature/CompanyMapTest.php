<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CompanyMapTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_company_and_job_pages_offer_a_map_without_loading_a_third_party_frame(): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        $company = Company::factory()->create(['name' => 'Bridge & Co', 'address' => '12 Main Street, Hanoi']);
        $job = JobPosting::factory()->accepting()->create(['company_id' => $company->id]);

        foreach ([route('companies.show', $company), route('jobs.show', $job)] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-map-placeholder', false)
                ->assertSee('data-map-load hidden', false)
                ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=12%20Main%20Street%2C%20Hanoi', false)
                ->assertSee('data-map-src="https://www.google.com/maps?q=12%20Main%20Street%2C%20Hanoi&amp;output=embed', false)
                ->assertDontSee('query=Bridge', false)
                ->assertSee('rel="noopener noreferrer" data-map-external', false)
                ->assertDontSee('<iframe', false)
                ->assertDontSee('maps.googleapis.com', false)
                ->assertSee('company-map.js', false)
                ->assertSee('company-map.css', false);
        }
        Http::assertNothingSent();
    }

    public function test_company_names_and_addresses_cannot_inject_html_or_replace_the_maps_host(): void
    {
        $company = Company::factory()->create([
            'name' => '<script>alert(1)</script>',
            'address' => '"><iframe src="https://evil.test"></iframe>&output=malicious',
        ]);

        $this->get(route('companies.show', $company))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('data-map-src="https://www.google.com/maps?q=', false)
            ->assertSee('%26output%3Dmalicious&amp;output=embed', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<iframe', false)
            ->assertDontSee('src="https://evil.test"', false);
    }

    public function test_hidden_or_blocked_company_locations_are_not_public(): void
    {
        $hidden = Company::factory()->create(['visibility' => 'HIDDEN']);
        $blocked = Company::factory()->create();
        $blocked->owner->update(['status' => 'BLOCKED']);

        foreach ([$hidden, $blocked] as $company) {
            $job = JobPosting::factory()->accepting()->create(['company_id' => $company->id]);
            $this->get(route('companies.show', $company))->assertNotFound()->assertDontSee('data-company-map', false);
            $this->get(route('jobs.show', $job))->assertNotFound()->assertDontSee('data-company-map', false);
        }
    }

    public function test_employer_preview_uses_their_saved_address_and_updates_after_saving(): void
    {
        $company = Company::factory()->create(['name' => 'Bridge', 'address' => '12 Main Street, Hanoi']);
        Company::factory()->create(['address' => 'Private other-company address']);
        $this->actingAs($company->owner)->withSession(['locale' => 'en'])
            ->get(route('employer.company'))->assertOk()
            ->assertSee('Preview company location')
            ->assertSee('This map uses the saved address.')
            ->assertSee('query=12%20Main%20Street%2C%20Hanoi', false)
            ->assertDontSee('Private other-company address');

        $this->put(route('employer.company.update'), [
            'name' => $company->name,
            'description' => $company->description,
            'contact_email' => $company->contact_email,
            'address' => '80 New Street, Hanoi',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'address' => '80 New Street, Hanoi']);
        $this->get(route('employer.company'))->assertOk()
            ->assertSee('query=80%20New%20Street%2C%20Hanoi', false)
            ->assertDontSee('12 Main Street, Hanoi');
    }

    public function test_employer_without_a_saved_company_is_prompted_for_an_address_without_a_map(): void
    {
        $this->actingAs(User::factory()->employer()->create())->withSession(['locale' => 'en'])
            ->get(route('employer.company'))->assertOk()
            ->assertSee('Include the street number')
            ->assertDontSee('data-company-map', false);
    }

    #[TestWith(['vi', 'Tải bản đồ Google Maps'])]
    #[TestWith(['en', 'Load Google Maps'])]
    #[TestWith(['ja', 'Google マップを読み込む'])]
    public function test_map_controls_follow_the_selected_language(string $locale, string $loadLabel): void
    {
        $company = Company::factory()->create();
        $this->withSession(['locale' => $locale])->get(route('companies.show', $company))->assertOk()
            ->assertSee($loadLabel)
            ->assertSee('&amp;hl='.$locale, false);
    }
}
