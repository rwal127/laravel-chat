<?php

namespace App\Http\Requests\Attachments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AttachmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB
                // Extension allow-list (defense-in-depth)
                'mimes:jpg,jpeg,png,webp,gif,pdf,txt,doc,docx,zip',
                // MIME allow-list (based on actual detected mime)
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip,application/x-zip-compressed',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (!$this->hasFile('file')) {
                return;
            }
            $file = $this->file('file');
            // Quick content checks for common web-executable payloads in text files
            $mime = (string) ($file->getMimeType() ?? '');
            $isTextLike = str_starts_with($mime, 'text/');
            if ($isTextLike) {
                try {
                    $stream = fopen($file->getRealPath(), 'rb');
                    if ($stream) {
                        $chunk = fread($stream, 4096) ?: '';
                        fclose($stream);
                        $lower = strtolower($chunk);
                        if (str_contains($lower, '<?php') || str_contains($lower, '<script')) {
                            $v->errors()->add('file', __('This file type/content is not allowed.'));
                        }
                    }
                } catch (\Throwable $e) {
                    // If we cannot safely inspect, fail closed
                    $v->errors()->add('file', __('Unable to verify file content.'));
                }
            }
        });
    }
}
