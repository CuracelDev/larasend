<?php

namespace App\Http\Requests;

use App\Support\ProjectContext;
use Illuminate\Foundation\Http\FormRequest;

class TransferWorkspaceOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return app(ProjectContext::class)
            ->workspaceFor($user)
            ->owner_id === $user->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
