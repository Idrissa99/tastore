<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('merchant', 'media')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->input('q') . '%'))
            ->latest()
            ->paginate(20);

        return ProductResource::collection($products);
    }

    public function archive(Product $product)
    {
        $product->update(['status' => 'archived']);

        return new ProductResource($product);
    }

    public function destroy(Product $product)
    {
        abort_if($product->tontines()->exists(), 409, 'Impossible de supprimer un produit lié à des tontines existantes. Archive-le plutôt.');

        $product->delete();

        return response()->json(['message' => 'Produit supprimé.']);
    }
}
