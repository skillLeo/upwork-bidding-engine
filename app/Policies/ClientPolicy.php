<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function view(User $user, Client $client): bool
    {
        return $user->can(Permissions::CLIENTS_VIEW);
    }

    public function message(User $user, Client $client): bool
    {
        return $user->can(Permissions::CLIENTS_MESSAGE);
    }
}
