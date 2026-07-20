<?php

namespace App\Services\Ai;

/**
 * Thrown when proposal_writing_enabled is off. Never a failure: callers
 * treat this as an intentional, operator-controlled pause, not an error
 * to retry or alert about.
 */
class ProposalWritingPausedException extends \RuntimeException {}
