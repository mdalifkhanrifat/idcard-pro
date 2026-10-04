<?php

namespace App\Services\CardTemplates;

use App\Data\CardTemplates\CardTemplateFilter;
use App\Models\CardTemplate;
use App\Models\User;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Card templates (layout, size, card type). Sections are added by the designer (3.4).
 */
class CardTemplateService
{
    /**
     * Fields that change how a printed card looks; editing them raises the version.
     */
    private const DESIGN_FIELDS = ['orientation', 'width', 'height', 'size_unit', 'is_double_sided'];

    public function __construct(
        private CardTemplateRepositoryInterface $templates,
        private MasterDataRepositoryInterface $masterData,
    ) {}

    /**
     * @return Collection<int, CardTemplate>
     */
    public function list(CardTemplateFilter $filter): Collection
    {
        return $this->templates->list($filter);
    }

    /**
     * @param  array<string, mixed>  $data  validated input; master data references are public ids
     */
    public function create(array $data, User $actor): CardTemplate
    {
        $template = $this->templates->create([
            ...$this->resolveLookups($data),
            'code' => strtoupper($data['code']),
            'width' => $data['width'] ?? CardTemplate::CR80['width'],
            'height' => $data['height'] ?? CardTemplate::CR80['height'],
            'created_by' => $actor->id,
        ]);

        return $template->load(['cardType', 'authorityType']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CardTemplate $template, array $data): CardTemplate
    {
        $data = $this->resolveLookups($data);
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $template->fill($data);
        if ($template->isDirty(self::DESIGN_FIELDS)) {
            $template->version++;
        }
        $template->save();

        return $template->load(['cardType', 'authorityType']);
    }

    public function delete(CardTemplate $template): void
    {
        $this->templates->delete($template);
    }

    /**
     * Copy a template as a new, inactive draft (legacy "copy template").
     */
    public function duplicate(CardTemplate $template, string $name, User $actor): CardTemplate
    {
        return DB::transaction(function () use ($template, $name, $actor): CardTemplate {
            $copy = $this->templates->create([
                ...$template->only([
                    'code', 'card_type_id', 'audience', 'orientation', 'width', 'height', 'size_unit',
                    'authority_type_id', 'validity_months', 'is_double_sided',
                ]),
                'name' => $name,
                'is_active' => false,
                'created_by' => $actor->id,
            ]);

            // Uploaded images are shared by reference; a later upload on either template stores a new file.
            foreach ($template->elements as $element) {
                $copy->elements()->create($element->only([
                    'side', 'type', 'field', 'label', 'content', 'image_disk', 'image_path', 'x', 'y', 'width', 'height', 'z_index', 'style',
                ]));
            }

            return $copy->load(['cardType', 'authorityType']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveLookups(array $data): array
    {
        foreach (['card_type_id', 'authority_type_id'] as $column) {
            if (array_key_exists($column, $data)) {
                $data[$column] = $data[$column] ? $this->masterData->idForPublicId($data[$column]) : null;
            }
        }

        return $data;
    }
}
