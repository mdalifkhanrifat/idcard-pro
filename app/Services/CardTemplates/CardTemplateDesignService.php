<?php

namespace App\Services\CardTemplates;

use App\Enums\TemplateAudience;
use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
use App\Models\CustomField;
use App\Repositories\Contracts\CardTemplateElementRepositoryInterface;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Repositories\Contracts\CustomFieldRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The template designer: the elements placed on each card side (legacy "template sections").
 * A layout is always saved as a whole; positions and sizes are in millimetres.
 */
class CardTemplateDesignService
{
    /**
     * Allowed rounding slack when checking that an element stays on the card.
     */
    private const BOUNDS_TOLERANCE_MM = 0.01;

    /**
     * Columns compared to decide whether a save changed the printed design.
     */
    private const DESIGN_COLUMNS = ['side', 'type', 'field', 'label', 'content', 'qr_lines', 'image_path', 'x', 'y', 'width', 'height', 'z_index', 'style'];

    public function __construct(
        private CardTemplateElementRepositoryInterface $elements,
        private CardTemplateRepositoryInterface $templates,
        private CustomFieldRepositoryInterface $customFields,
    ) {}

    /**
     * @return Collection<int, CardTemplateElement>
     */
    public function elements(CardTemplate $template): Collection
    {
        return $this->elements->forTemplate($template);
    }

    /**
     * What the designer may place on this template: element types, data fields, fonts and the card size.
     *
     * @return array<string, mixed>
     */
    public function catalog(CardTemplate $template): array
    {
        return [
            'types' => config('card_template_elements.types'),
            'fields' => $this->fieldGroups($template),
            'custom_fields' => $this->customFields->listFor('cardholder', activeOnly: true)
                ->map(fn (CustomField $field) => ['key' => 'custom.'.$field->key, 'label' => $field->label, 'label_local' => $field->label_local])
                ->values()
                ->all(),
            'fonts' => config('card_template_elements.fonts'),
            'size_mm' => $template->sizeInMm(),
        ];
    }

    /**
     * Replace the template's layout. The order of the elements within a side is the layer order
     * (first = bottom). Raises the template version when the printed design changes.
     *
     * @param  array<int, array<string, mixed>>  $input  validated by SaveCardTemplateElementsRequest
     * @return Collection<int, CardTemplateElement>
     */
    public function save(CardTemplate $template, array $input): Collection
    {
        $rows = $this->normalize($template, $input);

        return DB::transaction(function () use ($template, $rows): Collection {
            $before = $this->designSignature($this->elements->forTemplate($template));
            $saved = $this->elements->replaceForTemplate($template, $rows);

            if ($this->designSignature($saved) !== $before) {
                $this->templates->update($template, ['version' => $template->version + 1]);
            }

            return $saved;
        });
    }

    /**
     * Check the template-specific rules and turn the input into table rows.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return array<int, array<string, mixed>>
     */
    private function normalize(CardTemplate $template, array $input): array
    {
        $types = (array) config('card_template_elements.types');
        $size = $template->sizeInMm();
        $allowedFields = array_merge(...array_values($this->fieldGroups($template)));
        $errors = [];
        $rows = [];
        $layers = ['front' => 0, 'back' => 0];
        $backgrounds = [];

        foreach ($input as $index => $element) {
            $key = "elements.{$index}";
            $type = $element['type'];
            $side = $element['side'];
            $options = $types[$type];

            if ($side === 'back' && ! $template->is_double_sided) {
                $errors["{$key}.side"] = __('cards.designer.back_side_disabled');
            }

            if (! empty($options['single_per_side'])) {
                if (isset($backgrounds[$side])) {
                    $errors["{$key}.type"] = __('cards.designer.single_background');
                }
                $backgrounds[$side] = true;
            }

            $field = null;
            if (! empty($options['needs_field'])) {
                $field = $element['field'] ?? null;
                if ($field === null || $field === '') {
                    $errors["{$key}.field"] = __('cards.designer.field_required');
                } elseif (! $this->fieldAllowed($field, $allowedFields)) {
                    $errors["{$key}.field"] = __('cards.designer.field_unknown');
                }
            }

            $content = null;
            if (! empty($options['needs_content'])) {
                $content = trim((string) ($element['content'] ?? ''));
                if ($content === '') {
                    $errors["{$key}.content"] = __('cards.designer.content_required');
                }
            }

            $qrLines = null;
            if ($type === 'qr_code') {
                $qrLines = [];
                foreach ($element['qr_lines'] ?? [] as $lineIndex => $line) {
                    $lineField = $line['field'] ?? null;
                    $text = trim((string) ($line['text'] ?? ''));
                    if ($lineField !== null && $lineField !== '' && $this->fieldAllowed($lineField, $allowedFields)) {
                        $qrLines[] = array_filter(['field' => $lineField, 'label' => $line['label'] ?? null], fn ($value) => $value !== null && $value !== '');
                    } elseif (($lineField === null || $lineField === '') && $text !== '') {
                        $qrLines[] = ['text' => $text];
                    } else {
                        $errors["{$key}.qr_lines.{$lineIndex}"] = __('cards.designer.qr_line_invalid');
                    }
                }
            }

            $x = round((float) $element['x'], 2);
            $y = round((float) $element['y'], 2);
            $width = round((float) $element['width'], 2);
            $height = round((float) $element['height'], 2);
            if ($x + $width > $size['width'] + self::BOUNDS_TOLERANCE_MM || $y + $height > $size['height'] + self::BOUNDS_TOLERANCE_MM) {
                $errors["{$key}.x"] = __('cards.designer.outside_card', ['width' => $size['width'], 'height' => $size['height']]);
            }

            $rows[] = [
                'side' => $side,
                'type' => $type,
                'field' => $field,
                'label' => ($element['label'] ?? '') === '' ? null : $element['label'],
                'content' => $content,
                'qr_lines' => $qrLines,
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'z_index' => $layers[$side]++,
                'style' => $this->cleanStyle($element['style'] ?? null),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /**
     * Data fields by group; family fields are only offered on family member templates.
     *
     * @return array<string, array<int, string>>
     */
    private function fieldGroups(CardTemplate $template): array
    {
        $groups = (array) config('card_template_elements.fields');

        if ($template->audience !== TemplateAudience::FamilyMember) {
            unset($groups['family_member']);
        }

        return $groups;
    }

    /**
     * @param  array<int, string>  $allowedFields
     */
    private function fieldAllowed(string $field, array $allowedFields): bool
    {
        if (str_starts_with($field, 'custom.')) {
            return $this->customFields->keyExists('cardholder', substr($field, 7));
        }

        return in_array($field, $allowedFields, true);
    }

    /**
     * Cast style values to their types and drop empty ones so stored layouts stay small.
     *
     * @param  array<string, mixed>|null  $style
     * @return array<string, mixed>|null
     */
    private function cleanStyle(?array $style): ?array
    {
        $style = array_filter($style ?? [], fn ($value) => $value !== null && $value !== '');

        foreach (['bold', 'italic', 'underline'] as $flag) {
            if (isset($style[$flag])) {
                $style[$flag] = filter_var($style[$flag], FILTER_VALIDATE_BOOL);
            }
        }
        if (isset($style['font_size'])) {
            $style['font_size'] = (float) $style['font_size'];
        }

        return $style === [] ? null : $style;
    }

    /**
     * @param  Collection<int, CardTemplateElement>  $elements
     */
    private function designSignature(Collection $elements): string
    {
        return (string) json_encode($elements->map(fn (CardTemplateElement $element) => array_map(
            fn ($value) => is_numeric($value) ? (float) $value : $value,
            $element->only(self::DESIGN_COLUMNS),
        ))->values()->all());
    }
}
