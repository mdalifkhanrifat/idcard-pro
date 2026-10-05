<?php

namespace App\Repositories\Eloquent;

use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
use App\Models\TenantImage;
use App\Repositories\Contracts\CardTemplateElementRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<CardTemplateElement>
 */
class CardTemplateElementRepository extends EloquentRepository implements CardTemplateElementRepositoryInterface
{
    protected function model(): string
    {
        return CardTemplateElement::class;
    }

    public function forTemplate(CardTemplate $template): Collection
    {
        return $template->elements()->get()->each->setRelation('template', $template);
    }

    public function replaceForTemplate(CardTemplate $template, array $elements): Collection
    {
        $template->elements()->delete();

        foreach ($elements as $element) {
            $template->elements()->create($element);
        }

        return $this->forTemplate($template);
    }

    public function templatesUsingFont(string $family): array
    {
        return $this->query()
            ->where('style->font_family', $family)
            ->join('card_templates', 'card_templates.id', '=', 'card_template_elements.card_template_id')
            ->distinct()
            ->orderBy('card_templates.name')
            ->pluck('card_templates.name')
            ->all();
    }

    public function templatesUsingLibraryImage(TenantImage $image): array
    {
        return $this->query()
            ->where('library_image_id', $image->id)
            ->join('card_templates', 'card_templates.id', '=', 'card_template_elements.card_template_id')
            ->distinct()
            ->orderBy('card_templates.name')
            ->pluck('card_templates.name')
            ->all();
    }

    public function replaceLibraryImage(TenantImage $from, TenantImage $to): array
    {
        $templateIds = $this->query()->where('library_image_id', $from->id)->distinct()->pluck('card_template_id')->all();
        $this->query()->where('library_image_id', $from->id)->update(['library_image_id' => $to->id]);

        return $templateIds;
    }
}
