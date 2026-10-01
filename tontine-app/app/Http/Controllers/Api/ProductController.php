<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('merchant', 'media')
            ->where('status', 'published')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->input('q') . '%'))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->latest()
            ->paginate(12);

        return ProductResource::collection($products);
    }

    public function show(Product $product)
    {
        abort_unless($product->status === 'published', 404);

        return new ProductResource($product->load('merchant', 'media'));
    }
}
