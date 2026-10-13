<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CandidateProfileAvatarTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_guest_cannot_view_edit_or_fetch_private_profile(): void
    {
        $this->get(route('candidate.profile.show'))->assertRedirectToRoute('login');
        $this->get(route('candidate.profile.avatar'))->assertRedirectToRoute('login');
        $this->put(route('candidate.profile.update'), ['full_name' => 'Changed'])->assertRedirectToRoute('login');
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_access_candidate_profile(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        if ($role === 'admin') {
            $this->withSession($this->adminMfaSession($user));
        }
        $this->get(route('candidate.profile.show'))->assertForbidden();
        $this->get(route('candidate.profile.avatar'))->assertForbidden();
        $this->put(route('candidate.profile.update'), ['full_name' => 'Changed'])->assertForbidden();
        $this->assertDatabaseCount('candidate_profiles', 0);
    }

    public static function otherRoles(): array
    {
        return [['employer'], ['admin']];
    }

    public function test_profile_view_is_owner_only_escapes_personal_text_and_links_to_editor(): void
    {
        $profile = CandidateProfile::factory()->create([
            'full_name' => '<script>name</script>', 'phone' => '<svg>phone</svg>',
            'university' => '<b>school</b>', 'major' => '<i>major</i>',
            'introduction' => '<script>intro</script>', 'skills_text' => '<img src=x onerror=alert(1)>',
        ]);
        $other = CandidateProfile::factory()->create(['full_name' => 'Private Other Person']);
        $response = $this->actingAs($profile->user)->get(route('candidate.profile.show', ['user_id' => $other->user_id]));

        $response->assertOk()->assertSee($profile->user->email)->assertSee(route('candidate.profile'), false)->assertDontSee('Private Other Person');
        foreach (['full_name', 'phone', 'university', 'major', 'introduction', 'skills_text'] as $field) {
            $response->assertSee(e($profile->$field), false)->assertDontSee($profile->$field, false);
        }
    }

    public function test_profile_update_saves_only_editable_owner_fields_and_preserves_email_and_avatar(): void
    {
        $profile = CandidateProfile::factory()->create();
        $other = CandidateProfile::factory()->create();
        $email = $profile->user->email;
        $payload = ['full_name' => 'Updated Student', 'phone' => '0901122334', 'university' => 'University', 'major' => 'Design', 'study_year' => 4, 'introduction' => 'My introduction', 'skills_text' => 'Figma, HTML', 'email' => 'attacker@example.com', 'role' => 'admin', 'user_id' => $other->user_id, 'avatar_key' => '../forged.png'];

        $this->actingAs($profile->user)->from(route('candidate.profile'))->put(route('candidate.profile.update'), $payload)->assertRedirectToRoute('candidate.profile')->assertSessionHas('success');

        $this->assertDatabaseHas('candidate_profiles', ['user_id' => $profile->user_id, 'full_name' => 'Updated Student', 'phone' => '0901122334', 'major' => 'Design', 'study_year' => 4, 'introduction' => 'My introduction', 'skills_text' => 'Figma, HTML', 'avatar_key' => null]);
        $this->assertDatabaseHas('users', ['id' => $profile->user_id, 'name' => 'Updated Student', 'email' => $email, 'role' => 'candidate']);
        $this->assertSame($other->full_name, $other->fresh()->full_name);
    }

    public function test_required_name_and_invalid_personal_details_do_not_change_profile(): void
    {
        $profile = CandidateProfile::factory()->create();
        $this->actingAs($profile->user)->put(route('candidate.profile.update'), ['full_name' => '', 'study_year' => 9, 'phone' => str_repeat('1', 31), 'introduction' => str_repeat('x', 3001)])->assertSessionHasErrors(['full_name', 'study_year', 'phone', 'introduction']);
        $this->assertSame($profile->full_name, $profile->fresh()->full_name);
    }

    public function test_upload_is_stored_privately_with_opaque_name_and_served_without_shared_cache(): void
    {
        Storage::fake('local');
        $profile = CandidateProfile::factory()->create();
        $this->actingAs($profile->user)->put(route('candidate.profile.update'), ['full_name' => $profile->full_name, 'avatar' => $this->png('personal-name.png')])->assertSessionHasNoErrors();

        $key = $profile->fresh()->avatar_key;
        $this->assertStringStartsWith('profile-avatars/'.$profile->user_id.'/', $key);
        $this->assertStringNotContainsString('personal-name', $key);
        Storage::disk('local')->assertExists($key);
        $this->get(route('candidate.profile.avatar'))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('candidate.profile.show'))->assertOk()->assertSee(route('candidate.profile.avatar'), false);
        $this->get(route('candidate.profile'))->assertOk()->assertSee('multipart/form-data', false)->assertSee('name="remove_avatar"', false);
    }

    public function test_replacement_deletes_old_image_and_removal_clears_metadata_and_bytes(): void
    {
        Storage::fake('local');
        $profile = CandidateProfile::factory()->create();
        $this->actingAs($profile->user);
        $this->put(route('candidate.profile.update'), ['full_name' => $profile->full_name, 'avatar' => $this->png()])->assertSessionHasNoErrors();
        $old = $profile->fresh()->avatar_key;
        $this->put(route('candidate.profile.update'), ['full_name' => $profile->full_name, 'avatar' => $this->png()])->assertSessionHasNoErrors();
        $new = $profile->fresh()->avatar_key;
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($new);

        $this->put(route('candidate.profile.update'), ['full_name' => $profile->full_name, 'remove_avatar' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($profile->fresh()->avatar_key);
        Storage::disk('local')->assertMissing($new);
        $this->get(route('candidate.profile.avatar'))->assertNotFound();
    }

    public function test_missing_avatar_and_another_owners_key_cannot_be_read_or_deleted(): void
    {
        Storage::fake('local');
        $owner = CandidateProfile::factory()->create();
        $this->actingAs($owner->user)->put(route('candidate.profile.update'), ['full_name' => $owner->full_name, 'avatar' => $this->png()])->assertSessionHasNoErrors();
        $key = $owner->fresh()->avatar_key;
        $other = CandidateProfile::factory()->create();
        $this->actingAs($other->user)->get(route('candidate.profile.avatar', ['user_id' => $owner->user_id]))->assertNotFound();
        $other->update(['avatar_key' => $key]);
        $this->get(route('candidate.profile.avatar'))->assertNotFound();
        $this->put(route('candidate.profile.update'), ['full_name' => $other->full_name, 'remove_avatar' => '1'])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($key);
    }

    #[DataProvider('invalidAvatars')]
    public function test_invalid_image_does_not_change_profile_or_store_bytes(string $kind): void
    {
        Storage::fake('local');
        $profile = CandidateProfile::factory()->create();
        $file = match ($kind) {
            'svg' => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'text' => UploadedFile::fake()->createWithContent('photo.png', '<?php echo "not an image"; ?>'),
            'gif' => UploadedFile::fake()->createWithContent('photo.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')),
            'large' => $this->png()->size(2049),
            'dimensions' => $this->widePng(),
        };

        $this->actingAs($profile->user)->put(route('candidate.profile.update'), ['full_name' => 'Should Not Save', 'avatar' => $file])->assertSessionHasErrors('avatar');
        $this->assertSame($profile->full_name, $profile->fresh()->full_name);
        $this->assertNull($profile->fresh()->avatar_key);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function invalidAvatars(): array
    {
        return [['svg'], ['text'], ['gif'], ['large'], ['dimensions']];
    }

    public function test_upload_and_remove_together_returns_clear_validation_error(): void
    {
        Storage::fake('local');
        $profile = CandidateProfile::factory()->create();
        $this->actingAs($profile->user)->put(route('candidate.profile.update'), ['full_name' => 'Should Not Save', 'avatar' => $this->png(), 'remove_avatar' => '1'])->assertSessionHasErrors(['avatar' => __('profile.avatar_conflict')]);
        $this->assertSame($profile->full_name, $profile->fresh()->full_name);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_database_write_rolls_back_name_and_removes_new_file(): void
    {
        Storage::fake('local');
        Exceptions::fake();
        $profile = CandidateProfile::factory()->create();
        $user = $profile->user;
        $this->actingAs($user)->put(route('candidate.profile.update'), ['full_name' => $profile->full_name, 'avatar' => $this->png()])->assertSessionHasNoErrors();
        $oldKey = $profile->fresh()->avatar_key;
        $savedName = $user->fresh()->name;
        User::updated(function (): void {
            throw new \RuntimeException('Simulated profile write failure.');
        });

        try {
            $this->put(route('candidate.profile.update'), ['full_name' => 'Should Roll Back', 'avatar' => $this->png()])->assertInternalServerError();
        } finally {
            User::flushEventListeners();
        }

        Exceptions::assertReported(\RuntimeException::class);
        $this->assertSame($oldKey, $profile->fresh()->avatar_key);
        $this->assertSame($profile->full_name, $profile->fresh()->full_name);
        $this->assertSame($savedName, $user->fresh()->name);
        $this->assertSame([$oldKey], Storage::disk('local')->allFiles());
    }

    #[DataProvider('locales')]
    public function test_profile_pages_localize_new_controls(string $locale, string $editLabel): void
    {
        $profile = CandidateProfile::factory()->create();
        $this->actingAs($profile->user)->withSession(['locale' => $locale])->get(route('candidate.profile.show'))->assertOk()->assertSee($editLabel)->assertDontSee('profile.overview');
        $this->get(route('candidate.profile'))->assertOk()->assertSee($editLabel)->assertDontSee('profile.avatar_help');
    }

    public static function locales(): array
    {
        return [['vi', 'Chỉnh sửa hồ sơ'], ['en', 'Edit profile'], ['ja', 'プロフィールを編集']];
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    private function widePng(): UploadedFile
    {
        $file = $this->png();
        $contents = file_get_contents($file->getRealPath());
        $contents = substr_replace($contents, pack('N', 4097), 16, 4);
        $contents = substr_replace($contents, pack('N', crc32(substr($contents, 12, 17))), 29, 4);

        return UploadedFile::fake()->createWithContent('wide.png', $contents);
    }
}
