<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductMediaService
{
    /**
     * Enregistre les fichiers envoyés (images multiples + une vidéo optionnelle)
     * et crée les lignes ProductMedia correspondantes.
     */
    public function attachUploads(Product $product, array $images = [], ?UploadedFile $video = null): void
    {
        $nextPosition = (int) $product->media()->max('position') + 1;

        foreach ($images as $image) {
            $path = $image->store('products/images', 'public');

            ProductMedia::create([
                'product_id' => $product->id,
                'type' => 'image',
                'path' => $path,
                'position' => $nextPosition++,
            ]);
        }

        if ($video) {
            $path = $video->store('products/videos', 'public');

            ProductMedia::create([
                'product_id' => $product->id,
                'type' => 'video',
                'path' => $path,
                'position' => $nextPosition,
            ]);
        }
    }

    public function delete(ProductMedia $media): void
    {
        Storage::disk('public')->delete($media->path);
        $media->delete();
    }
}
