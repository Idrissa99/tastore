@php $product = $product ?? null; @endphp

<label>Nom</label>
<input type="text" name="name" value="{{ old('name', $product?->name) }}" required>
@error('name') <p style="color:red">{{ $message }}</p> @enderror

<label>Description</label>
<textarea name="description">{{ old('description', $product?->description) }}</textarea>

<label>Catégorie</label>
<input type="text" name="category" value="{{ old('category', $product?->category) }}">

<label>Prix</label>
<input type="number" name="price" id="calc_price" value="{{ old('price', $product?->price) }}" required>
@error('price') <p style="color:red">{{ $message }}</p> @enderror

<label>Stock</label>
<input type="number" name="stock" value="{{ old('stock', $product?->stock ?? 0) }}" required>
@error('stock') <p style="color:red">{{ $message }}</p> @enderror

<label>Statut</label>
<select name="status">
    <option value="draft" @selected(old('status', $product?->status) === 'draft')>Brouillon</option>
    <option value="published" @selected(old('status', $product?->status) === 'published')>Publié</option>
    <option value="archived" @selected(old('status', $product?->status) === 'archived')>Archivé</option>
</select>

<label>Ajouter des photos (jusqu'à 6, 5 Mo max chacune)</label>
<input type="file" name="images[]" accept="image/png,image/jpeg,image/webp" multiple>
@error('images') <p style="color:red">{{ $message }}</p> @enderror
@error('images.*') <p style="color:red">{{ $message }}</p> @enderror

<label>Ajouter une vidéo (optionnel, 50 Mo max)</label>
<input type="file" name="video" accept="video/mp4,video/quicktime,video/webm">
@error('video') <p style="color:red">{{ $message }}</p> @enderror

<fieldset style="border:1px solid #ccc; padding:1rem; margin-top:1.5rem;">
    <legend>Calculateur — montant à prévoir pour une tontine sur ce produit</legend>
    <p><small>Ceci est juste un aperçu pour t'aider à fixer la cotisation ; ça ne modifie rien ici, c'est la personne qui crée la tontine qui choisit les vrais montants.</small></p>

    <label>Nombre de membres prévu pour la tontine</label>
    <input type="number" id="calc_members" value="5" min="2">

    <p>Commission plateforme actuelle : <strong>{{ number_format($commissionRate * 100, 2) }}%</strong> par versement</p>

    <ul>
        <li>Cotisation par versement (hors commission) : <strong id="calc_contribution">—</strong> F</li>
        <li>Commission plateforme par versement (à ta charge, prélevée automatiquement) : <strong id="calc_commission">—</strong> F</li>
        <li>Montant global à collecter sur la tontine (prix du produit + commission cumulée) : <strong id="calc_total">—</strong> F</li>
    </ul>
</fieldset>

<script>
    (function () {
        const rate = {{ $commissionRate }};
        const priceInput = document.getElementById('calc_price');
        const membersInput = document.getElementById('calc_members');

        function formatFCFA(n) {
            return Math.round(n).toLocaleString('fr-FR');
        }

        function recalculate() {
            const price = parseFloat(priceInput.value) || 0;
            const members = parseInt(membersInput.value) || 0;

            if (price <= 0 || members <= 0) {
                document.getElementById('calc_contribution').textContent = '—';
                document.getElementById('calc_commission').textContent = '—';
                document.getElementById('calc_total').textContent = '—';
                return;
            }

            const contributionPerPayment = price / members;
            const commissionPerPayment = contributionPerPayment * rate;
            const globalAmount = price * (1 + rate);

            document.getElementById('calc_contribution').textContent = formatFCFA(contributionPerPayment);
            document.getElementById('calc_commission').textContent = formatFCFA(commissionPerPayment);
            document.getElementById('calc_total').textContent = formatFCFA(globalAmount);
        }

        priceInput.addEventListener('input', recalculate);
        membersInput.addEventListener('input', recalculate);
        recalculate();
    })();
</script>
