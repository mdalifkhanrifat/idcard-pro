<?php

namespace App\Services\CardTemplates;

use App\Enums\TemplateAudience;
use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
use App\Models\CustomField;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Repositories\Contracts\CardTemplateElementRepositoryInterface;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Repositories\Contracts\CustomFieldRepositoryInterface;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use App\Services\Cards\CardDataResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        private CardTemplateImageService $images,
        private CardholderRepositoryInterface $cardholders,
        private FamilyMemberRepositoryInterface $familyMembers,
        private CardDataResolver $cardData,
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
            'fonts' => config('card_template_elements.bundled_fonts'),
            'system_fonts' => config('card_template_elements.system_fonts'),
            'size_mm' => $template->sizeInMm(),
        ];
    }

    /**
     * Real values of one cardholder (and, for family cards, one of their family members) for the designer preview.
     * Card fields (number, dates) are only known once a card is issued, so they are not included.
     *
     * @return array{fields: array<string, string>, images: array<string, string|null>}
     */
    public function preview(CardTemplate $template, string $cardholderId, ?string $familyMemberId): array
    {
        $cardholder = $this->cardholders->findByPublicId($cardholderId) ?? throw new NotFoundHttpException;
        $member = null;
        if ($familyMemberId !== null) {
            $member = $this->familyMembers->findByPublicId($familyMemberId);
            if ($member === null || $member->cardholder_id !== $cardholder->id) {
                throw new NotFoundHttpException;
            }
        }

        return $this->cardData->resolve($template, $cardholder, $member);
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

        [$saved, $unusedImages] = DB::transaction(function () use ($template, $rows): array {
            $previous = $this->elements->forTemplate($template);
            $saved = $this->elements->replaceForTemplate($template, $rows);

            if ($this->designSignature($saved) !== $this->designSignature($previous)) {
                $this->templates->update($template, ['version' => $template->version + 1]);
            }

            return [$saved, $previous->pluck('image_path')->filter()->diff($saved->pluck('image_path')->filter())];
        });

        // Replaced or removed images; uploads never saved in a layout stay until the template is deleted.
        $this->images->delete($unusedImages->unique());

        return $saved;
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

            $imagePath = null;
            if (! empty($options['image']) && ($element['image'] ?? null) !== null) {
                if ($this->images->exists($template, $element['image'])) {
                    $imagePath = $this->images->path($template, $element['image']);
                } else {
                    $errors["{$key}.image"] = __('cards.designer.image_missing');
                }
            }

            $rows[] = [
                'side' => $side,
                'type' => $type,
                'field' => $field,
                'label' => ($element['label'] ?? '') === '' ? null : $element['label'],
                'content' => $content,
                'qr_lines' => $qrLines,
                'image_disk' => $imagePath === null ? null : $this->images->diskName(),
                'image_path' => $imagePath,
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

        foreach (['bold', 'italic', 'underline', 'uppercase', 'auto_fit', 'single_line', 'locked', 'hidden'] as $flag) {
            if (isset($style[$flag])) {
                $style[$flag] = filter_var($style[$flag], FILTER_VALIDATE_BOOL);
            }
        }
        foreach (['font_size', 'line_height', 'letter_spacing', 'padding', 'border_width', 'border_radius', 'opacity'] as $number) {
            if (isset($style[$number])) {
                $style[$number] = (float) $style[$number];
            }
        }

        if (isset($style['rotation'])) {
            $style['rotation'] = (int) $style['rotation'];
        }
        // False flags and no rotation are the defaults; keep layouts small and comparisons stable.
        $style = array_filter($style, fn ($value) => $value !== false && $value !== 0);

        return $style === [] ? null : $style;
    }

    /**
     * @param  Collection<int, CardTemplateElement>  $elements
     */
    private function designSignature(Collection $elements): string
    {
        return (string) json_encode($elements->map(function (CardTemplateElement $element): array {
            $columns = array_map(fn ($value) => is_numeric($value) ? (float) $value : $value, $element->only(self::DESIGN_COLUMNS));
            // Locking only affects editing, not the printed card.
            unset($columns['style']['locked']);

            return $columns;
        })->values()->all());
    }
}
