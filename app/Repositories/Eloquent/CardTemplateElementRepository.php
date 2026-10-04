<?php

namespace App\Repositories\Eloquent;

use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
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
        return $template->elements()->get();
    }

    public function replaceForTemplate(CardTemplate $template, array $elements): Collection
    {
        $template->elements()->delete();

        foreach ($elements as $element) {
            $template->elements()->create($element);
        }

        return $this->forTemplate($template);
    }
}
