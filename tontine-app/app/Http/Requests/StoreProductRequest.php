<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isMerchant() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'in:draft,published,archived'],
            // upload optionnel : plusieurs images + une vidéo
            'images' => ['nullable', 'array', 'max:6'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], // 5 Mo par image
            'video' => ['nullable', 'file', 'mimes:mp4,mov,webm', 'max:51200'], // 50 Mo max
        ];
    }

    public function messages(): array
    {
        return [
            'images.max' => 'Maximum 6 images par produit.',
            'images.*.max' => 'Chaque image doit faire moins de 5 Mo.',
            'video.max' => 'La vidéo doit faire moins de 50 Mo.',
        ];
    }
}
