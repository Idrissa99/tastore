<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\ProductMediaService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(protected ProductMediaService $mediaService)
    {
    }

    public function index(Request $request)
    {
        $products = $request->user()->merchant->products()->with('media')->latest()->get();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();
        unset($validated['images'], $validated['video']);

        $product = $request->user()->merchant->products()->create($validated);

        $this->mediaService->attachUploads($product, $request->file('images', []), $request->file('video'));

        return (new ProductResource($product->load('media')))->response()->setStatusCode(201);
    }

    public function update(StoreProductRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        $validated = $request->validated();
        unset($validated['images'], $validated['video']);

        $product->update($validated);

        $this->mediaService->attachUploads($product, $request->file('images', []), $request->file('video'));

        return new ProductResource($product->load('media'));
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorize('delete', $product);

        abort_if($product->tontines()->exists(), 409, 'Impossible de supprimer un produit lié à des tontines existantes. Archive-le plutôt.');

        foreach ($product->media as $media) {
            $this->mediaService->delete($media);
        }

        $product->delete();

        return response()->json(['message' => 'Produit supprimé.']);
    }

    public function updateStock(Request $request, Product $product)
    {
        $this->authorize('updateStock', $product);

        $validated = $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
        ]);

        $product->update(['stock' => $validated['stock']]);

        return new ProductResource($product);
    }

    public function destroyMedia(Request $request, ProductMedia $media)
    {
        $this->authorize('deleteMedia', $media);

        $this->mediaService->delete($media);

        return response()->json(['message' => 'Média supprimé.']);
    }
}
