<?php

namespace App\Services\Cards;

use App\Models\Card;
use App\Repositories\Contracts\TenantImageRepositoryInterface;
use App\Services\CardTemplates\CardTemplateImageService;
use App\Services\ImageLibrary\TenantImageService;

/**
 * An issued card's design snapshot in the shape the designer uses (CardTemplateElementResource),
 * so the card is drawn by the same components as the designer and thumbnails.
 */
class CardLayoutPresenter
{
    public function __construct(
        private CardTemplateImageService $templateImages,
        private TenantImageService $libraryImages,
        private TenantImageRepositoryInterface $library,
    ) {}

    /**
     * @return array{size_mm: array{width: float, height: float}, orientation: string, is_double_sided: bool, elements: array<int, array<string, mixed>>}
     */
    public function present(Card $card): array
    {
        $layout = $card->layout;
        $card->loadMissing('template');
        $library = $this->library->findManyById(array_values(array_filter(array_column($layout['elements'], 'library_image_id'))));

        $elements = [];
        foreach ($layout['elements'] as $element) {
            $libraryImage = isset($element['library_image_id']) ? $library->get($element['library_image_id']) : null;
            $elements[] = [
                'side' => $element['side'],
                'type' => $element['type'],
                'field' => $element['field'] ?? null,
                'label' => $element['label'] ?? null,
                'content' => $element['content'] ?? null,
                'qr_lines' => $element['qr_lines'] ?? null,
                'image' => isset($element['image_path']) ? basename($element['image_path']) : null,
                'library_image' => $libraryImage?->public_id,
                'image_url' => $libraryImage !== null
                    ? $this->libraryImages->url($libraryImage)
                    : $this->templateImages->url($card->template, $element['image_path'] ?? null),
                'x' => (float) $element['x'],
                'y' => (float) $element['y'],
                'width' => (float) $element['width'],
                'height' => (float) $element['height'],
                'z_index' => $element['z_index'] ?? 0,
                'style' => (object) ($element['style'] ?? []),
            ];
        }

        return [...$layout, 'elements' => $elements];
    }
}
