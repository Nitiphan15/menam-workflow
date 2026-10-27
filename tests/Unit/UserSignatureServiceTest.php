<?php

namespace Tests\Unit;

use App\Services\UserSignatureService;
use App\Services\Po\PoErpService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserSignatureServiceTest extends TestCase
{
    public function test_it_stores_a_normalized_png_for_the_selected_user(): void
    {
        Storage::fake('public');

        $path = app(UserSignatureService::class)->store(
            UploadedFile::fake()->image('signature.jpg', 1200, 400),
            42,
        );

        $this->assertSame('signatures/users/user_42.png', $path);
        Storage::disk('public')->assertExists($path);

        [$width, $height, $type] = getimagesize(Storage::disk('public')->path($path));
        $this->assertLessThanOrEqual(900, $width);
        $this->assertLessThanOrEqual(260, $height);
        $this->assertSame(IMAGETYPE_PNG, $type);
    }

    public function test_po_signature_lookup_uses_the_storage_file_convention(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('signatures/users/user_42.png', 'signature');

        $method = new \ReflectionMethod(PoErpService::class, 'resolveSignaturePath');
        $path = $method->invoke(new PoErpService(), (object) ['actor_user_id' => 42]);

        $this->assertSame('signatures/users/user_42.png', $path);
    }
}
