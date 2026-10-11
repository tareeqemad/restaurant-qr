<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class WaiterSubmissionRejected extends RuntimeException
{
    public function __construct(public readonly JsonResponse $response)
    {
        parent::__construct('Waiter submission rejected before commit.');
    }
}
