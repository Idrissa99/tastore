<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstallmentPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'installments_count' => $this->installments_count,
            'installment_amount' => $this->installment_amount,
            'product_price' => $this->product_price,
            'status' => $this->status,
            'delivery_status' => $this->delivery_status,
            'delivered_at' => $this->delivered_at,
            'paid_installments_count' => $this->paidInstallmentsCount(),
            'installments' => $this->whenLoaded('installments', fn () => $this->installments->map(fn ($i) => [
                'id' => $i->id,
                'installment_number' => $i->installment_number,
                'amount' => $i->amount,
                'status' => $i->status,
                'payment_method' => $i->payment_method,
                'transaction_reference' => $i->transaction_reference,
                'verification_status' => $i->verification_status,
                'transfer_code' => $i->transfer_code,
                'submitted_at' => $i->submitted_at,
                'paid_at' => $i->paid_at,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
