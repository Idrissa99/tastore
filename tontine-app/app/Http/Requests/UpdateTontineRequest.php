<?php

namespace App\Http\Requests;

use App\Models\Tontine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Modification d'une tontine qui n'a pas encore démarré.
 *
 * Tant que la tontine est "open" (donc avant son activation, qui n'a lieu
 * qu'une fois le nombre de membres atteint), les cotisations n'ont pas encore
 * été générées : aucun montant n'est engagé et rien n'est recalculé sur des
 * transactions existantes.
 *
 * Dès que la tontine passe "active", la modification devient impossible. Le
 * contrôle est fait côté service (voir TontineController::update) et non
 * seulement ici : une FormRequest ne voit que les champs, pas l'état métier.
 */
class UpdateTontineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::in(['product', 'cash'])],
            'product_id' => ['sometimes', 'nullable', 'required_if:type,product', 'exists:products,id'],
            'total_amount' => ['sometimes', 'nullable', 'required_if:type,cash', 'numeric', 'min:100'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'frequency' => ['sometimes', 'required', Rule::in(['daily', 'weekly', 'monthly'])],
            'max_members' => ['sometimes', 'required', 'integer', 'min:2', 'max:100'],

            // Volontairement SANS "after_or_equal:today" contrairement à la
            // création : une date de démarrage déjà passée reste un fait
            // accompli. L'interdire rendrait la tontine non modifiable à
            // partir du lendemain, alors qu'elle est encore "open".
            'start_date' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'max_members.min' => 'Une tontine doit avoir au moins 2 membres.',
            'max_members.max' => 'Une tontine ne peut pas dépasser 100 membres.',
            'product_id.required_if' => 'Choisis un produit, ou passe en tontine argent.',
            'total_amount.required_if' => 'Indique le montant total à réunir pour cette tontine argent.',
        ];
    }

    /**
     * La tontine visée, résolue depuis l'identifiant de route.
     */
    public function tontine(): Tontine
    {
        return $this->route('tontine');
    }
}
