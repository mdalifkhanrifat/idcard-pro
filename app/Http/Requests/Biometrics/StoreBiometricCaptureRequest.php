<?php

namespace App\Http\Requests\Biometrics;

use App\Data\Biometrics\CaptureData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A capture from a station: the image as a file (upload) or base64 (scanner drivers), an optional
 * template, the measured quality and which try it was. What is allowed for the organization is
 * checked by BiometricService against its settings.
 */
class StoreBiometricCaptureRequest extends FormRequest
{
    /** @var array<string, string> decoded base64 fields */
    private array $decoded = [];

    public function authorize(): bool
    {
        return $this->user()->can('biometrics.enroll');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'family_member' => ['sometimes', 'nullable', 'string', 'max:26'],
            'modality' => ['required', 'string', Rule::in(array_keys((array) config('biometrics.modalities')))],
            'position' => ['required', 'string', 'max:40'],
            'driver' => ['required', 'string', Rule::in(array_keys((array) config('biometrics.drivers')))],
            'image' => ['required_without:image_base64', 'prohibits:image_base64', 'file', 'max:'.(int) config('biometrics.max_image_kb')],
            'image_base64' => ['required_without:image', 'string'],
            'image_format' => ['required', 'string', Rule::in(array_keys((array) config('biometrics.image_types')))],
            'template_base64' => ['sometimes', 'nullable', 'string'],
            'template_format' => ['required_with:template_base64', 'nullable', 'string', 'max:30'],
            'quality' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'attempts' => ['sometimes', 'integer', 'between:1,100'],
            'accept_low_quality' => ['sometimes', 'boolean'],
            'width' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
            'height' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
            'dpi' => ['sometimes', 'nullable', 'integer', 'between:72,4000'],
            'device' => ['sometimes', 'nullable', 'array:vendor,model,serial'],
            'device.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['image_base64' => 'image', 'template_base64' => 'template'] as $field => $name) {
                $value = $this->input($field);
                if (! is_string($value) || $value === '' || $validator->errors()->has($field)) {
                    continue;
                }
                // Drivers may send a data URL ("data:image/png;base64,...")
                $bytes = base64_decode((string) preg_replace('/^data:[^,]*,/', '', $value), true);
                if ($bytes === false || $bytes === '') {
                    $validator->errors()->add($field, __("biometrics.capture.{$name}_invalid", ['format' => strtoupper((string) $this->input('image_format'))]));
                } else {
                    $this->decoded[$name] = $bytes;
                }
            }
        }];
    }

    public function toData(): CaptureData
    {
        $image = $this->decoded['image'] ?? (string) $this->file('image')?->get();
        $device = array_filter((array) $this->validated('device', []), filled(...));

        return new CaptureData(
            modality: $this->validated('modality'),
            position: $this->validated('position'),
            driver: $this->validated('driver'),
            image: $image,
            imageFormat: $this->validated('image_format'),
            template: $this->decoded['template'] ?? null,
            templateFormat: isset($this->decoded['template']) ? $this->validated('template_format') : null,
            quality: $this->validated('quality') !== null ? (int) $this->validated('quality') : null,
            attempts: (int) $this->validated('attempts', 1),
            acceptLowQuality: $this->boolean('accept_low_quality'),
            width: $this->validated('width') !== null ? (int) $this->validated('width') : null,
            height: $this->validated('height') !== null ? (int) $this->validated('height') : null,
            dpi: $this->validated('dpi') !== null ? (int) $this->validated('dpi') : null,
            device: $device !== [] ? $device : null,
        );
    }
}
