<?php

namespace Tests\Feature\Media;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_merchant_can_upload_images_and_a_video_when_creating_a_product(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);

        $response = $this->actingAs($merchant->user)->post(route('merchant.products.store'), [
            'name' => 'Produit avec médias',
            'price' => 15000,
            'stock' => 5,
            'status' => 'published',
            'images' => [
                UploadedFile::fake()->create('photo1.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('photo2.jpg', 100, 'image/jpeg'),
            ],
            'video' => UploadedFile::fake()->create('demo.mp4', 2000, 'video/mp4'),
        ]);

        $response->assertRedirect();

        $product = Product::where('name', 'Produit avec médias')->first();
        $this->assertNotNull($product);
        $this->assertSame(2, $product->images()->count());
        $this->assertSame(1, $product->videos()->count());

        Storage::disk('public')->assertExists($product->images()->first()->path);
    }

    public function test_image_upload_rejects_oversized_or_wrong_type_files(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);

        $response = $this->actingAs($merchant->user)->post(route('merchant.products.store'), [
            'name' => 'Produit invalide',
            'price' => 15000,
            'stock' => 5,
            'status' => 'published',
            'images' => [UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')],
        ]);

        $response->assertSessionHasErrors('images.0');
        $this->assertDatabaseMissing('products', ['name' => 'Produit invalide']);
    }

    public function test_merchant_can_delete_a_media_from_their_product(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);

        $media = $product->media()->create([
            'type' => 'image',
            'path' => 'products/images/fake.jpg',
            'position' => 1,
        ]);
        Storage::disk('public')->put($media->path, 'fake-content');

        $this->actingAs($merchant->user)
            ->delete(route('merchant.products.media.destroy', $media))
            ->assertRedirect();

        $this->assertDatabaseMissing('product_media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing($media->path);
    }

    public function test_merchant_cannot_delete_another_merchants_product_media(): void
    {
        $ownerMerchant = Merchant::factory()->create(['status' => 'approved']);
        $otherMerchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $ownerMerchant->id]);

        $media = $product->media()->create([
            'type' => 'image', 'path' => 'products/images/fake.jpg', 'position' => 1,
        ]);

        $this->actingAs($otherMerchant->user)
            ->delete(route('merchant.products.media.destroy', $media))
            ->assertForbidden();
    }

    public function test_product_catalog_returns_media_via_api(): void
    {
        $product = Product::factory()->create(['status' => 'published']);
        $product->media()->create(['type' => 'image', 'path' => 'products/images/fake.jpg', 'position' => 1]);

        $this->getJson("/api/produits/{$product->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.images');
    }
}
