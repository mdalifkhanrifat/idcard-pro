<?php

namespace App\Http\Requests\CardTemplates;

use App\Models\CardTemplateElement;
use App\Services\CardTemplates\CardTemplateImageService;
use App\Services\Fonts\TenantFontService;
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
            'elements.*' => ['array:side,type,field,label,content,qr_lines,image,library_image,x,y,width,height,style'],
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
            // Name returned by POST /card-templates/{id}/images
            'elements.*.image' => ['nullable', 'string', 'regex:'.CardTemplateImageService::NAME_PATTERN],
            // Public id of an image in the organization library (GET /library-images), instead of `image`
            'elements.*.library_image' => ['nullable', 'string', 'size:26'],
            'elements.*.x' => ['required', 'numeric', 'min:0', 'max:1000'],
            'elements.*.y' => ['required', 'numeric', 'min:0', 'max:1000'],
            // 0.2 mm allows thin lines
            'elements.*.width' => ['required', 'numeric', 'min:0.2', 'max:1000'],
            'elements.*.height' => ['required', 'numeric', 'min:0.2', 'max:1000'],
            'elements.*.style' => ['nullable', 'array:'.implode(',', array_merge(...array_values((array) config('card_template_elements.style'))))],
            'elements.*.style.font_family' => ['nullable', Rule::in([
                ...(array) config('card_template_elements.bundled_fonts'),
                ...(array) config('card_template_elements.system_fonts'),
                ...app(TenantFontService::class)->families(),
            ])],
            'elements.*.style.font_size' => ['nullable', 'numeric', 'between:1,72'],
            'elements.*.style.color' => $colour,
            'elements.*.style.label_color' => $colour,
            'elements.*.style.background' => $colour,
            'elements.*.style.bold' => ['nullable', 'boolean'],
            'elements.*.style.italic' => ['nullable', 'boolean'],
            'elements.*.style.underline' => ['nullable', 'boolean'],
            'elements.*.style.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'elements.*.style.vertical_align' => ['nullable', Rule::in(['top', 'middle', 'bottom'])],
            'elements.*.style.line_height' => ['nullable', 'numeric', 'between:0.5,3'],
            'elements.*.style.letter_spacing' => ['nullable', 'numeric', 'between:-2,20'],
            'elements.*.style.uppercase' => ['nullable', 'boolean'],
            'elements.*.style.auto_fit' => ['nullable', 'boolean'],
            'elements.*.style.single_line' => ['nullable', 'boolean'],
            'elements.*.style.padding' => ['nullable', 'numeric', 'between:0,20'],
            'elements.*.style.gradient_to' => $colour,
            'elements.*.style.gradient_direction' => ['nullable', Rule::in((array) config('card_template_elements.gradient_directions'))],
            'elements.*.style.border_color' => $colour,
            'elements.*.style.border_width' => ['nullable', 'numeric', 'between:0,10'],
            'elements.*.style.border_style' => ['nullable', Rule::in(['solid', 'dashed', 'dotted'])],
            // Content turns inside the (unrotated) element box, so the card bounds still apply to the box.
            'elements.*.style.rotation' => ['nullable', 'integer', Rule::in([0, 90, 180, 270])],
            // Tilt of the whole element around its centre; the tilted corners must stay on the card unless `bleed`
            'elements.*.style.angle' => ['nullable', 'numeric', 'between:-180,180'],
            'elements.*.style.bleed' => ['nullable', 'boolean'],
            'elements.*.style.locked' => ['nullable', 'boolean'],
            'elements.*.style.hidden' => ['nullable', 'boolean'],
            'elements.*.style.border_radius' => ['nullable', 'numeric', 'between:0,100'],
            'elements.*.style.opacity' => ['nullable', 'numeric', 'between:0.05,1'],
            'elements.*.style.shadow_color' => $colour,
            // Offsets and blur in mm
            'elements.*.style.shadow_x' => ['nullable', 'numeric', 'between:-10,10'],
            'elements.*.style.shadow_y' => ['nullable', 'numeric', 'between:-10,10'],
            'elements.*.style.shadow_blur' => ['nullable', 'numeric', 'between:0,10'],
            'elements.*.style.shadow_opacity' => ['nullable', 'numeric', 'between:0.05,1'],
            'elements.*.style.fit' => ['nullable', Rule::in(['cover', 'contain'])],
            'elements.*.style.shape' => ['nullable', Rule::in(['rectangle', 'ellipse'])],
            'elements.*.style.barcode_format' => ['nullable', Rule::in((array) config('card_template_elements.barcode_formats'))],
            'elements.*.style.barcode_text' => ['nullable', 'boolean'],
        ];
    }
}
