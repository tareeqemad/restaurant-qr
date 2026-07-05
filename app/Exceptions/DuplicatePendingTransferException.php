<?php

namespace App\Exceptions;

use App\Models\PendingTransfer;
use RuntimeException;

/**
 * Thrown by PendingTransferService::record() when a PENDING transfer already
 * exists for the same table session. One real transfer = one verifiable claim;
 * a second row could be verified into a duplicate payment. Callers catch this
 * and surface a friendly "already recorded" message instead of erroring.
 */
class DuplicatePendingTransferException extends RuntimeException
{
    public function __construct(public readonly PendingTransfer $existing)
    {
        parent::__construct('يوجد تحويل معلّق لهذه الطاولة بالفعل.');
    }
}
