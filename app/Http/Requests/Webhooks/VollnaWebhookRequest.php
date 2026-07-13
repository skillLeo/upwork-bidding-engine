<?php

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deliberately lenient: Vollna's payload shape is a third-party contract we
 * don't fully control, so we validate only the envelope this delivery type
 * always has (a non-empty projects array) and defensively extract everything
 * else per-project in the controller rather than 422-ing on minor upstream
 * shape drift. The secret check itself happens earlier, in
 * VerifyVollnaSecret middleware.
 */
class VollnaWebhookRequest extends FormRequest
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
            'projects' => ['required', 'array', 'min:1'],
            'projects.*.title' => ['required', 'string'],
        ];
    }
}
