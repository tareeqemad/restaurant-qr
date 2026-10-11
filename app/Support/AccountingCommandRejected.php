<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/** Rolls back a command and its receipt while retaining the validation response. */
class AccountingCommandRejected extends RuntimeException
{
    public function __construct(public readonly RedirectResponse $response)
    {
        parent::__construct('Accounting command was not saved.');
    }
}
