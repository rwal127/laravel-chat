<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // Already behind auth middleware
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'contact_user_id' => [
                'required',
                'integer',
                // Must not be yourself
                Rule::notIn([$userId]),
                Rule::exists('users', 'id'),
                // Prevent duplicates: unique on (user_id, contact_user_id)
                Rule::unique('contacts', 'contact_user_id')->where(fn ($q) => $q->where('user_id', $userId)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_user_id.different' => __('You cannot add yourself as a contact.'),
            'contact_user_id.unique' => __('This user is already in your contacts.'),
        ];
    }
}
