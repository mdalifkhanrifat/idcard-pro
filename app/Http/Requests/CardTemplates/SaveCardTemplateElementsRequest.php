<?php

namespace App\Http\Requests\CardTemplates;

use App\Models\CardTemplateElement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a whole designer layout. Rules that depend on the template (fields, card bounds,
 * one background per side) are checked by CardTemplateDesignService.
 */
class SaveCardTemplateElementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('templates.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $colour = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'elements' => ['present', 'array', 'max:200'],
            'elements.*' => ['array:side,type,field,label,content,qr_lines,x,y,width,height,style'],
            'elements.*.side' => ['required', Rule::in(CardTemplateElement::SIDES)],
            'elements.*.type' => ['required', Rule::in(array_keys((array) config('card_template_elements.types')))],
            'elements.*.field' => ['nullable', 'string', 'max:60'],
            'elements.*.label' => ['nullable', 'string', 'max:100'],
            'elements.*.content' => ['nullable', 'string', 'max:500'],
            'elements.*.qr_lines' => ['nullable', 'array', 'max:20'],
            'elements.*.qr_lines.*' => ['array:field,label,text'],
            'elements.*.qr_lines.*.field' => ['nullable', 'string', 'max:60'],
            'elements.*.qr_lines.*.label' => ['nullable', 'string', 'max:50'],
            'elements.*.qr_lines.*.text' => ['nullable', 'string', 'max:200'],
            'elements.*.x' => ['required', 'numeric', 'min:0', 'max:1000'],
            'elements.*.y' => ['required', 'numeric', 'min:0', 'max:1000'],
            'elements.*.width' => ['required', 'numeric', 'min:0.5', 'max:1000'],
            'elements.*.height' => ['required', 'numeric', 'min:0.5', 'max:1000'],
            'elements.*.style' => ['nullable', 'array:font_family,font_size,color,label_color,background,bold,italic,underline,align'],
            'elements.*.style.font_family' => ['nullable', Rule::in((array) config('card_template_elements.fonts'))],
            'elements.*.style.font_size' => ['nullable', 'numeric', 'between:1,72'],
            'elements.*.style.color' => $colour,
            'elements.*.style.label_color' => $colour,
            'elements.*.style.background' => $colour,
            'elements.*.style.bold' => ['nullable', 'boolean'],
            'elements.*.style.italic' => ['nullable', 'boolean'],
            'elements.*.style.underline' => ['nullable', 'boolean'],
            'elements.*.style.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
        ];
    }
}
