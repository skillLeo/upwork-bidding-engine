<?php

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deliberately lenient: Vollna's payload shape is a third-party contract we
 * don't fully control, so we validate the one field we truly can't proceed
 * without (title) and defensively extract everything else in the controller
 * rather than 422-ing on minor upstream shape drift. The secret check itself
 * happens earlier, in VerifyVollnaSecret middleware.
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
            'title' => ['required', 'string'],
        ];
    }
}
