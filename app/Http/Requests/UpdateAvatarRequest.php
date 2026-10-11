<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route is behind auth: a user only ever changes their own avatar
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048'
            ],
        ];
    }
}
