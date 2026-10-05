<?php

namespace App\Services\CardTemplates;

use App\Data\CardTemplates\CardTemplateFilter;
use App\Models\CardTemplate;
use App\Models\User;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Card templates (layout, size, card type). Their elements are edited through CardTemplateDesignService.
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
        private CardTemplateImageService $images,
        private TenantContext $tenants,
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
        // Without a size, the organization's default (legacy Template Setup) is used, turned for vertical cards.
        $default = $this->tenants->get()?->defaultCardSize() ?? CardTemplate::CR80;
        if (($data['orientation'] ?? 'horizontal') === 'vertical') {
            $default = ['width' => $default['height'], 'height' => $default['width']];
        }
        $template = $this->templates->create([
            ...$this->resolveLookups($data),
            'code' => strtoupper($data['code']),
            'width' => $data['width'] ?? $default['width'],
            'height' => $data['height'] ?? $default['height'],
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
        $this->images->deleteAll($template);
    }

    /**
     * Copy a template as a new, inactive draft (legacy "copy template").
     */
    public function duplicate(CardTemplate $template, string $name, User $actor, ?string $code = null): CardTemplate
    {
        return DB::transaction(function () use ($template, $name, $actor, $code): CardTemplate {
            $copy = $this->templates->create([
                ...$template->only([
                    'code', 'card_type_id', 'audience', 'orientation', 'width', 'height', 'size_unit',
                    'authority_type_id', 'validity_months', 'is_double_sided',
                ]),
                'name' => $name,
                'code' => $code !== null && $code !== '' ? strtoupper($code) : $template->code,
                'is_active' => false,
                'created_by' => $actor->id,
            ]);

            // The copy gets its own image files, so removing a logo from one template never breaks the other;
            // library images stay linked (they never change and cannot be deleted while used).
            $imagePaths = $this->images->copyAll($template, $copy);
            foreach ($template->elements as $element) {
                $copy->elements()->create([
                    ...$element->only(['side', 'type', 'field', 'label', 'content', 'qr_lines', 'image_disk', 'library_image_id', 'x', 'y', 'width', 'height', 'z_index', 'style']),
                    'image_path' => $imagePaths[$element->image_path] ?? null,
                ]);
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
