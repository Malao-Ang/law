<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertPermissionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = app(\App\Services\Auth\CurrentUser::class);
        $actor = $current->user($this);
        if ($actor === null) {
            return false;
        }
        if (($actor['is_super_admin'] ?? false) || $current->role($actor, $this) === 'inner_admin') {
            return true;
        }
        // An editor ticked "edit" in the active unit manages document permissions.
        $active = $this->session()->get(\App\Services\Auth\CurrentUser::ACTIVE_UNIT_KEY);
        foreach ((array) ($actor['memberships'] ?? []) as $membership) {
            if ($membership['unit_code'] === $active) {
                return in_array('edit', \App\Services\Auth\MongoUserStore::effectiveActions($membership), true);
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['nullable', 'string', 'max:128'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['nullable', 'string', 'max:128'],
        ];
    }
}
