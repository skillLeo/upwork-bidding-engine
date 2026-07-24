<?php

namespace App\Exceptions;

/**
 * Thrown by AiManager BEFORE any provider call when a tenant is over its
 * ai_monthly_token_cap with ai_hard_stop_on_cap on. Deliberately its own
 * exception type (not a generic RuntimeException) so callers that need to
 * tell "the AI is down" apart from "this workspace paused itself" — the
 * dashboard banner, the scoring job's error handling — can catch it
 * specifically instead of pattern-matching a message string.
 */
class AiQuotaExceededException extends \RuntimeException
{
    public function __construct(int $capTokens, int $usedTokens)
    {
        parent::__construct(sprintf(
            'This workspace has used %s of its %s token AI cap for this month, with the hard stop on — scoring and proposal writing are paused until next month or the cap is raised in Settings.',
            number_format($usedTokens),
            number_format($capTokens),
        ));
    }
}
