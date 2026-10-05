<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something shared that an issued card's design uses and must keep (see the card_assets migration).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $card_id
 * @property string $kind library_image or font
 * @property string $reference tenant_images.id or a font family
 */
#[Fillable(['card_id', 'kind', 'reference'])]
class CardAsset extends Model
{
    use BelongsToTenant;

    public const LIBRARY_IMAGE = 'library_image';

    public const FONT = 'font';

    public $timestamps = false;
}
