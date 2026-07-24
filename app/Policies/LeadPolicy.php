<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\Lead;
use App\Models\User;

/**
 * Every lead-related authorization decision, in one place.
 *
 * Controllers call $this->authorize(...) against these methods rather than
 * checking a role name inline, so the rule for "who may delete a lead" has
 * exactly one home and cannot drift between endpoints. Tenant isolation is a
 * SEPARATE layer (the global scope already guarantees a user only ever sees
 * their own tenant's leads), so these methods reason purely about permission.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::LEADS_VIEW);
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->can(Permissions::LEADS_VIEW);
    }

    public function updateStatus(User $user, Lead $lead): bool
    {
        return $user->can(Permissions::LEADS_UPDATE_STATUS);
    }

    public function rescore(User $user, Lead $lead): bool
    {
        return $user->can(Permissions::LEADS_RESCORE);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can(Permissions::LEADS_DELETE);
    }

    public function editProposal(User $user, Lead $lead): bool
    {
        return $user->can(Permissions::PROPOSALS_EDIT);
    }

    public function rewriteProposal(User $user, Lead $lead): bool
    {
        return $user->can(Permissions::PROPOSALS_REWRITE);
    }
}
