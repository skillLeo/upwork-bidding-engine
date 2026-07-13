<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only bidder-driven, forward transitions — new/scoring/ready are system-controlled.
            'status' => ['required', 'string', Rule::in(['sent', 'replied', 'won', 'archived'])],
        ];
    }
}
