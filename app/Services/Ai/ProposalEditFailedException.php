<?php

namespace App\Services\Ai;

/**
 * Thrown when an AI edit cannot be produced or applied - an unusable model
 * response (bad JSON on a selection edit), an empty proposal, or an invalid
 * selection range. The controller maps it to a 422 so nothing is persisted.
 */
class ProposalEditFailedException extends \RuntimeException {}
