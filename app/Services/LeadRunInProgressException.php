<?php

namespace App\Services;

/**
 * Thrown when a rescore/rewrite is requested for a lead that already has
 * one running — callers translate this into a 409 instead of paying for
 * a duplicate AI run.
 */
class LeadRunInProgressException extends \RuntimeException {}
