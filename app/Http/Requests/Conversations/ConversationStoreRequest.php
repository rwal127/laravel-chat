<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConversationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['direct', 'group'])],
            // For direct: require target user id
            'user_id' => ['required_if:type,direct', 'integer', 'nullable', 'exists:users,id'],
            // For group: require name and participants array of user ids
            'name' => ['required_if:type,group', 'string', 'max:255', 'nullable'],
            'participants' => ['required_if:type,group', 'array', 'nullable'],
            'participants.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }
}
