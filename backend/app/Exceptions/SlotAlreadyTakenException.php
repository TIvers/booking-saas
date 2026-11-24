<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class SlotAlreadyTakenException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The selected booking slot is no longer available.', previous: $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'slot_already_taken',
            'message' => $this->getMessage(),
        ], 409);
    }
}
