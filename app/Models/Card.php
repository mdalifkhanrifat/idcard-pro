<?php

namespace App\Models;

use App\Enums\CardStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An issued ID card (legacy card_information + the reason from card_print_history). Every issue is
 * its own row and keeps the design and images it was made from, so it always reprints the same.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $cardholder_id
 * @property int|null $family_member_id set for family cards
 * @property int $card_template_id
 * @property int $template_version
 * @property array{size_mm: array{width: float, height: float}, orientation: string, is_double_sided: bool, elements: array<int, array<string, mixed>>} $layout
 * @property array<string, string>|null $values printed text fixed at issue (CardDataResolver fields)
 * @property string $number
 * @property string|null $rf_card_no
 * @property string $reason key of config('cards.reasons')
 * @property string|null $notes
 * @property int|null $document_file_id
 * @property int|null $photo_file_id
 * @property int|null $signature_file_id
 * @property int|null $signatory_id
 * @property Carbon $issue_date
 * @property Carbon|null $expiry_date
 * @property CardStatus $status
 * @property Carbon|null $status_changed_at
 * @property string|null $status_note
 * @property int|null $replaced_by_id
 * @property int|null $issued_by
 * @property int $print_count copies printed so far; 0 = not printed yet (legacy "PENDING")
 * @property Carbon|null $last_printed_at
 * @property string|null $active_number_key
 * @property string|null $active_holder_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cardholder $cardholder
 * @property-read FamilyMember|null $familyMember
 * @property-read CardTemplate $template
 * @property-read CardholderFile|null $document
 * @property-read CardholderFile|null $photo
 * @property-read CardholderFile|null $signature
 * @property-read Signatory|null $signatory
 * @property-read Card|null $replacedBy
 * @property-read User|null $issuer
 * @property-read Collection<int, CardPrint> $prints
 */
#[Fillable([
    'cardholder_id', 'family_member_id', 'card_template_id', 'template_version', 'layout', 'values', 'number', 'rf_card_no',
    'reason', 'notes', 'document_file_id', 'photo_file_id', 'signature_file_id', 'signatory_id', 'issue_date',
    'expiry_date', 'status', 'status_changed_at', 'status_note', 'replaced_by_id', 'issued_by',
    'active_number_key', 'active_holder_key', 'print_count', 'last_printed_at',
])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

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
            'layout' => 'array',
            'values' => 'array',
            'template_version' => 'integer',
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'status' => CardStatus::class,
            'status_changed_at' => 'datetime',
            'print_count' => 'integer',
            'last_printed_at' => 'datetime',
        ];
    }

    /**
     * Key that makes "one active card per holder" a database rule.
     */
    public static function holderKey(int $cardholderId, ?int $familyMemberId): string
    {
        return $familyMemberId === null ? "c{$cardholderId}" : "f{$familyMemberId}";
    }

    public function isActive(): bool
    {
        return $this->status === CardStatus::Active;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lt(today());
    }

    public function isPrinted(): bool
    {
        return $this->print_count > 0;
    }

    /**
     * @return BelongsTo<Cardholder, $this>
     */
    public function cardholder(): BelongsTo
    {
        return $this->belongsTo(Cardholder::class);
    }

    /**
     * @return BelongsTo<FamilyMember, $this>
     */
    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }

    /**
     * @return BelongsTo<CardTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CardTemplate::class, 'card_template_id');
    }

    /**
     * @return BelongsTo<CardholderFile, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(CardholderFile::class, 'document_file_id');
    }

    /**
     * @return BelongsTo<CardholderFile, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(CardholderFile::class, 'photo_file_id');
    }

    /**
     * @return BelongsTo<CardholderFile, $this>
     */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(CardholderFile::class, 'signature_file_id');
    }

    /**
     * @return BelongsTo<Signatory, $this>
     */
    public function signatory(): BelongsTo
    {
        return $this->belongsTo(Signatory::class);
    }

    /**
     * @return BelongsTo<Card, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'replaced_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Every printing of this card, newest first.
     *
     * @return HasMany<CardPrint, $this>
     */
    public function prints(): HasMany
    {
        return $this->hasMany(CardPrint::class)->latest('printed_at')->latest('id');
    }

    /**
     * Only card facts are logged, never personal data from the layout.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'rf_card_no', 'reason', 'card_template_id', 'template_version', 'issue_date', 'expiry_date', 'status', 'status_note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('cards');
    }
}
