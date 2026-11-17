<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || ($this->user()?->can('update', $this->route('room')) ?? false);
    }

    public function rules(): array
    {
        $room = $this->route('room');
        $existingCount = ($room && ! $this->boolean('replace')) ? $room->images()->count() : 0;
        $maxNew = max(0, 8 - $existingCount);

        return [
            'images' => ['required', 'array', 'min:1', "max:{$maxNew}"],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'replace' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.max' => 'A room cannot have more than 8 photos in total.',
        ];
    }
}
