<?php

namespace App\Http\Requests\ImageLibrary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload (file and optional name) or rename (name only) of a library image.
 */
class SaveTenantImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('image') ? 'images.update' : 'images.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        if ($this->route('image')) {
            return ['name' => ['required', 'string', 'max:100']];
        }

        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
