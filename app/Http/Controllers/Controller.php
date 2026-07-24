<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Lets every controller call $this->authorize(...) against a Policy,
    // which is how model-action authorization flows now — never an inline
    // role-name check.
    use AuthorizesRequests;
}
