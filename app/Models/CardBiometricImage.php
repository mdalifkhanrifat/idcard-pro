<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A biometric image an issued card prints, copied at issue (see the card_biometric_images migration).
 * The file is encrypted (BiometricVault); no file means the person had nothing printable at issue.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $card_id
 * @property string $field biometric.<modality>.<position|auto>
 * @property string $modality
 * @property string|null $position
 * @property int|null $biometric_capture_id
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $image_format
 * @property-read Card $card
 */
#[Fillable(['card_id', 'field', 'modality', 'position', 'biometric_capture_id', 'disk', 'path', 'image_format'])]
class CardBiometricImage extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsTo<Card, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
