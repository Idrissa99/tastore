<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTontineRequest extends FormRequest
{
    public function authorize(): bool
    {
        // seul un client connecté peut créer une tontine
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:product,cash'],
            'product_id' => ['required_if:type,product', 'nullable', 'exists:products,id'],
            'total_amount' => ['required_if:type,cash', 'nullable', 'numeric', 'min:100'],
            'name' => ['required', 'string', 'max:255'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'max_members' => ['required', 'integer', 'min:2', 'max:100'],
            'start_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'max_members.min' => 'Une tontine doit avoir au moins 2 membres.',
            'product_id.required_if' => 'Choisis un produit, ou passe en tontine argent.',
            'total_amount.required_if' => 'Indique le montant total à réunir pour cette tontine argent.',
        ];
    }
}
