<?php

namespace App\Exceptions;

use App\Models\User;

/**
 * Thrown when something tries to make a second account the platform owner.
 *
 * There is exactly one platform owner, ever. Because the assignment is
 * refused rather than silently ignored, a caller can never end up believing
 * it succeeded — see PlatformOwnership for why this is enforced in the
 * application layer instead of by a unique index.
 */
class PlatformOwnerAlreadyExistsException extends \RuntimeException
{
    public function __construct(public readonly User $currentOwner)
    {
        parent::__construct(
            "Platform ownership is already held by {$currentOwner->email} (user #{$currentOwner->id}). "
            .'There is exactly one platform owner — transfer it rather than assigning a second.'
        );
    }
}
