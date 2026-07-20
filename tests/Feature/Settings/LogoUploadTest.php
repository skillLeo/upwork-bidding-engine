<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_oversized_logo_is_resized_to_fit_512px(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        // Mirrors the real-world case that motivated this: a full
        // camera-resolution upload, 4500x4500, ~423KB on the live site.
        $file = UploadedFile::fake()->image('logo.jpg', 4500, 4500);

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/settings/logo', ['logo' => $file])
            ->assertOk();

        $logoUrl = $response->json('data.logo_url');
        $this->assertNotNull($logoUrl);

        $storedPath = Storage::disk('public')->path(
            str($logoUrl)->after('/storage/')->toString()
        );
        [$width, $height] = getimagesize($storedPath);

        $this->assertLessThanOrEqual(512, $width);
        $this->assertLessThanOrEqual(512, $height);
    }

    public function test_an_already_small_logo_is_stored_without_upscaling(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $file = UploadedFile::fake()->image('logo.png', 64, 64);

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/settings/logo', ['logo' => $file])
            ->assertOk();

        $logoUrl = $response->json('data.logo_url');
        $storedPath = Storage::disk('public')->path(
            str($logoUrl)->after('/storage/')->toString()
        );
        [$width, $height] = getimagesize($storedPath);

        $this->assertSame(64, $width);
        $this->assertSame(64, $height);
    }
}
