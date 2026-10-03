<?php

namespace App\Services\CustomFields;

use App\Enums\CustomFieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Repositories\Contracts\CustomFieldRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Organization-defined extra fields: their definitions, validation and stored values.
 */
class CustomFieldService
{
    public function __construct(private CustomFieldRepositoryInterface $fields) {}

    /**
     * @return Collection<int, CustomField>
     */
    public function list(string $entity, bool $activeOnly = false): Collection
    {
        return $this->fields->listFor($entity, $activeOnly);
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(array $data): CustomField
    {
        return $this->fields->create([
            ...$data,
            'options' => $this->options($data),
            'sort_order' => $data['sort_order'] ?? $this->fields->nextSortOrder($data['entity']),
        ]);
    }

    /**
     * The key, entity and type are fixed once created so stored values stay valid.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CustomField $field, array $data): CustomField
    {
        unset($data['key'], $data['entity'], $data['type']);
        if (array_key_exists('options', $data)) {
            $data['options'] = $field->type === CustomFieldType::Select ? array_values(array_unique($data['options'] ?? [])) : null;
        }

        return $this->fields->update($field, $data);
    }

    /**
     * Stored values stay on the records; they are no longer shown or validated.
     */
    public function delete(CustomField $field): void
    {
        $this->fields->delete($field);
    }

    /**
     * Add the fields of a preset from config/custom_field_presets.php. Existing keys are skipped.
     *
     * @return int number of fields created
     */
    public function applyPreset(string $name): int
    {
        $preset = config("custom_field_presets.{$name}");
        if (! is_array($preset)) {
            throw new BusinessRuleException(__('custom_fields.unknown_preset'));
        }

        return DB::transaction(function () use ($preset): int {
            $created = 0;
            foreach ($preset['fields'] as $definition) {
                if ($this->fields->keyExists($preset['entity'], $definition['key'])) {
                    continue;
                }
                $this->create([...$definition, 'entity' => $preset['entity'], 'group' => $preset['group'] ?? null]);
                $created++;
            }

            return $created;
        });
    }

    /**
     * Validation rules for the values of an entity's active fields, under `custom_fields.*`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $entity): array
    {
        $active = $this->fields->listFor($entity, true);
        $rules = ['custom_fields' => ['sometimes', 'nullable', 'array:'.$active->pluck('key')->implode(',')]];

        foreach ($active as $field) {
            $rules["custom_fields.{$field->key}"] = [
                $field->is_required ? 'required' : 'nullable',
                ...match ($field->type) {
                    CustomFieldType::Text => ['string', 'max:255'],
                    CustomFieldType::Textarea => ['string', 'max:2000'],
                    CustomFieldType::Number => ['numeric', 'between:-999999999,999999999'],
                    CustomFieldType::Date => ['date_format:Y-m-d'],
                    CustomFieldType::Select => ['string', Rule::in($field->options ?? [])],
                    CustomFieldType::Checkbox => ['boolean'],
                },
            ];
        }

        return $rules;
    }

    /**
     * Attribute names for validation messages (uses the field labels).
     *
     * @return array<string, string>
     */
    public function attributes(string $entity): array
    {
        return $this->fields->listFor($entity, true)
            ->mapWithKeys(fn (CustomField $field) => ["custom_fields.{$field->key}" => $field->label])
            ->all();
    }

    /**
     * Merge validated values into the stored ones, cast to their types. Values of
     * inactive or deleted fields already on the record are kept untouched.
     *
     * @param  array<string, mixed>  $values  validated `custom_fields` input
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>|null
     */
    public function mergeValues(string $entity, array $values, ?array $stored): ?array
    {
        $result = $stored ?? [];

        foreach ($this->fields->listFor($entity, true) as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }
            $value = $values[$field->key];
            $result[$field->key] = match (true) {
                $value === null || $value === '' => null,
                $field->type === CustomFieldType::Number => $value + 0,
                $field->type === CustomFieldType::Checkbox => filter_var($value, FILTER_VALIDATE_BOOL),
                default => $value,
            };
        }

        $result = array_filter($result, fn (mixed $value) => $value !== null);

        return $result === [] ? null : $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>|null
     */
    private function options(array $data): ?array
    {
        return ($data['type'] ?? null) === CustomFieldType::Select->value
            ? array_values(array_unique($data['options'] ?? []))
            : null;
    }
}
