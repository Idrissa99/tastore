<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
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

        return view('merchant.products.index', compact('products'));
    }

    public function create()
    {
        $commissionRate = (float) \App\Models\Setting::get('commission_rate', config('commissions.rate', 0));

        return view('merchant.products.create', compact('commissionRate'));
    }

    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();
        unset($validated['images'], $validated['video']);

        $product = $request->user()->merchant->products()->create($validated);

        $this->mediaService->attachUploads($product, $request->file('images', []), $request->file('video'));

        return redirect()->route('merchant.products.index')->with('success', 'Produit créé.');
    }

    public function edit(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $product->load('media');
        $commissionRate = (float) \App\Models\Setting::get('commission_rate', config('commissions.rate', 0));

        return view('merchant.products.edit', compact('product', 'commissionRate'));
    }

    public function update(StoreProductRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        $validated = $request->validated();
        unset($validated['images'], $validated['video']);

        $product->update($validated);

        $this->mediaService->attachUploads($product, $request->file('images', []), $request->file('video'));

        return redirect()->route('merchant.products.index')->with('success', 'Produit mis à jour.');
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorize('delete', $product);

        abort_if($product->tontines()->exists(), 409, 'Impossible de supprimer un produit lié à des tontines existantes. Archive-le plutôt.');

        foreach ($product->media as $media) {
            $this->mediaService->delete($media);
        }

        $product->delete();

        return redirect()->route('merchant.products.index')->with('success', 'Produit supprimé.');
    }

    /**
     * Mise à jour rapide du stock, sans repasser par le formulaire complet.
     */
    public function updateStock(Request $request, Product $product)
    {
        $this->authorize('updateStock', $product);

        $validated = $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
        ]);

        $product->update(['stock' => $validated['stock']]);

        return back()->with('success', 'Stock mis à jour.');
    }

    public function destroyMedia(Request $request, ProductMedia $media)
    {
        $this->authorize('deleteMedia', $media);

        $this->mediaService->delete($media);

        return back()->with('success', 'Média supprimé.');
    }
}
