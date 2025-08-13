<?php

namespace App\Http\Requests\Messages;

use Illuminate\Foundation\Http\FormRequest;

class MessageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
            'body' => ['nullable', 'string'],
            // Optional attachments (multipart)
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => [
                'file',
                'max:10240', // 10MB
                'mimes:jpg,jpeg,png,webp,gif,pdf,txt,doc,docx,zip',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip,application/x-zip-compressed',
            ],
        ];
    }
}
