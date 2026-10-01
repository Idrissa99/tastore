<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use RuntimeException;

class PaymentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function render(Request $request)
    {
        return response()->json(['message' => $this->getMessage()], $this->statusCode);
    }
}
