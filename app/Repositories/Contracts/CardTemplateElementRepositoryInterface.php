<?php

namespace App\Repositories\Contracts;

use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
use App\Models\TenantImage;
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

    /**
     * Names of the templates with an element linked to the library image.
     *
     * @return array<int, string>
     */
    public function templatesUsingLibraryImage(TenantImage $image): array;

    /**
     * Link every element using one library image to another one.
     *
     * @return array<int, int> ids of the templates changed
     */
    public function replaceLibraryImage(TenantImage $from, TenantImage $to): array;
}
