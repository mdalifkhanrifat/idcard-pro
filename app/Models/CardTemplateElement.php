<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CardTemplateElementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item placed on a card template side (legacy "template section").
 * Types and fields are listed in config/card_template_elements.php.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $card_template_id
 * @property string $side front|back
 * @property string $type
 * @property string|null $field
 * @property string|null $label
 * @property string|null $content
 * @property array<int, array{field?: string, label?: string|null, text?: string}>|null $qr_lines lines encoded in a qr_code element
 * @property string|null $image_disk
 * @property string|null $image_path
 * @property string $x
 * @property string $y
 * @property string $width
 * @property string $height
 * @property int $z_index
 * @property array<string, mixed>|null $style
 * @property-read CardTemplate $template
 */
#[Fillable([
    'card_template_id', 'side', 'type', 'field', 'label', 'content', 'qr_lines', 'image_disk', 'image_path',
    'x', 'y', 'width', 'height', 'z_index', 'style',
])]
class CardTemplateElement extends Model
{
    /** @use HasFactory<CardTemplateElementFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const SIDES = ['front', 'back'];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x' => 'decimal:2',
            'y' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'z_index' => 'integer',
            'qr_lines' => 'array',
            'style' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CardTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CardTemplate::class, 'card_template_id');
    }
}
