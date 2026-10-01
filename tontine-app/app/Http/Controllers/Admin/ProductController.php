<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('merchant')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->input('q') . '%'))
            ->latest()
            ->paginate(20);

        return view('admin.products.index', compact('products'));
    }

    /**
     * Archive un produit litigieux (ne le supprime pas : les tontines déjà
     * créées dessus doivent pouvoir continuer normalement, il disparaît juste
     * du catalogue public et devient impossible à sélectionner pour une nouvelle tontine).
     */
    public function archive(Product $product)
    {
        $product->update(['status' => 'archived']);

        return back()->with('success', "Produit « {$product->name} » archivé.");
    }

    public function destroy(Product $product)
    {
        abort_if($product->tontines()->exists(), 409, 'Impossible de supprimer un produit lié à des tontines existantes. Archive-le plutôt.');

        $product->delete();

        return back()->with('success', 'Produit supprimé.');
    }
}
