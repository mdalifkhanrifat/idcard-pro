<?php

namespace App\Repositories\Contracts;

use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<CardTemplateElement>
 */
interface CardTemplateElementRepositoryInterface extends RepositoryInterface
{
    /**
     * Elements of both sides, bottom layer first.
     *
     * @return Collection<int, CardTemplateElement>
     */
    public function forTemplate(CardTemplate $template): Collection;

    /**
     * Delete every element of the template and insert the given ones.
     *
     * @param  array<int, array<string, mixed>>  $elements
     * @return Collection<int, CardTemplateElement>
     */
    public function replaceForTemplate(CardTemplate $template, array $elements): Collection;

    /**
     * Names of the templates with an element in the given font family.
     *
     * @return array<int, string>
     */
    public function templatesUsingFont(string $family): array;
}
