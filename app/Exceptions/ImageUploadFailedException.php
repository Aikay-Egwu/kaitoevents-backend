<?php

namespace App\Exceptions;

use Exception;

class ImageUploadFailedException extends Exception
{
    protected $context;

    public function __construct(string $message, array $context = [], int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function render()
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'IMAGE_UPLOAD_FAILED',
                'message' => $this->getMessage(),
                'context' => $this->context,
            ],
        ], 422);
    }
}