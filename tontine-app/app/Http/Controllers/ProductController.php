<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('merchant', 'media')
            ->where('status', 'published')
            ->when($request->filled('q'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->input('q') . '%');
            })
            ->when($request->filled('category'), function ($query) use ($request) {
                $query->where('category', $request->input('category'));
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $categories = Product::where('status', 'published')
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        return view('products.index', compact('products', 'categories'));
    }

    public function show(Product $product)
    {
        abort_unless($product->status === 'published', 404);

        $product->load('merchant', 'media');

        return view('products.show', compact('product'));
    }
}
