<?php

namespace App\Services\Biometrics;

use App\Models\BiometricCapture;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Repositories\Contracts\BiometricCaptureRepositoryInterface;
use App\Repositories\Contracts\CardBiometricImageRepositoryInterface;
use App\Support\Biometrics\BiometricCardField;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Biometric images printed on cards (designer `biometric` elements, 4.10). The organization chooses
 * (biometrics.print_source) whether a card prints the image captured when it was issued, kept as the
 * card's own encrypted copy (image only, never the template), or the person's latest capture.
 * Only images a browser can draw are printed (not WSQ); a family member's card prints their own.
 */
class CardBiometricImageService
{
    public function __construct(
        private BiometricCaptureRepositoryInterface $captures,
        private CardBiometricImageRepositoryInterface $images,
        private BiometricVault $vault,
    ) {}

    /**
     * Fields of the biometric elements a layout prints (hidden elements are never printed).
     *
     * @param  array{elements?: array<int, array<string, mixed>>}  $layout
     * @return array<int, string>
     */
    public static function fieldsIn(array $layout): array
    {
        return collect($layout['elements'] ?? [])
            ->filter(fn (array $element): bool => ($element['type'] ?? null) === 'biometric' && empty($element['style']['hidden']) && BiometricCardField::parse($element['field'] ?? null) !== null)
            ->pluck('field')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The capture a field prints for the person now: the named position, or for `auto` the first captured
     * in the organization's order of preference. Null when the organization does not print that modality,
     * it does not capture family members (for a family card), or nothing printable was captured.
     *
     * @param  Collection<int, BiometricCapture>|null  $current  the person's current captures, when already loaded
     */
    public function latest(Cardholder $cardholder, ?FamilyMember $member, string $field, ?Collection $current = null): ?BiometricCapture
    {
        $settings = $cardholder->tenant->biometricSettings();
        $parsed = BiometricCardField::parse($field);
        $printable = BiometricSettings::printable($settings);
        if ($parsed === null || ! isset($printable[$parsed['modality']]) || ($member !== null && ! $settings['family_members'])) {
            return null;
        }

        $positions = $parsed['position'] === BiometricCardField::AUTO
            ? BiometricSettings::printOrder($settings, $parsed['modality'])
            : array_intersect([$parsed['position']], $printable[$parsed['modality']]);
        $usable = ($current ?? $this->captures->currentFor($cardholder, $member))
            ->filter(fn (BiometricCapture $capture): bool => $capture->modality === $parsed['modality'] && $this->printable($capture))
            ->keyBy('position');

        foreach ($positions as $position) {
            if ($usable->has($position)) {
                return $usable->get($position);
            }
        }

        return null;
    }

    /**
     * Keep, for a card being issued, its own copy of each biometric image its design prints, when the
     * organization prints the image captured at issue. Call inside the issuing transaction: copies made
     * are removed again if it rolls back.
     */
    public function keepForCard(Card $card, Cardholder $cardholder, ?FamilyMember $member): void
    {
        $fields = self::fieldsIn((array) $card->layout);
        if ($fields === [] || $cardholder->tenant->biometricSettings()['print_source'] !== 'issue') {
            return;
        }

        $current = $this->captures->currentFor($cardholder, $member);
        $copied = collect();
        DB::afterRollBack(fn () => $this->vault->delete($copied));

        foreach ($fields as $field) {
            $capture = $this->latest($cardholder, $member, $field, $current);
            $location = null;
            if ($capture !== null) {
                $location = $this->copy($cardholder, $capture);
                $copied->push(...($location === null ? [] : [$location]));
                $capture = $location === null ? null : $capture;
            }

            $this->images->create([
                'card_id' => $card->id,
                'field' => $field,
                'modality' => BiometricCardField::parse($field)['modality'] ?? '',
                'position' => $capture?->position,
                'biometric_capture_id' => $capture?->id,
                'disk' => $location['disk'] ?? null,
                'path' => $location['path'] ?? null,
                'image_format' => $capture?->image_format,
            ]);
        }
    }

    /**
     * The card's own encrypted copy of a capture's image. A capture whose file cannot be read never stops
     * a card from being issued: it is reported, and the card prints as if nothing had been captured.
     *
     * @return array{disk: string, path: string}|null
     */
    private function copy(Cardholder $cardholder, BiometricCapture $capture): ?array
    {
        try {
            return $this->vault->put($cardholder->tenant->public_id, $this->vault->get((string) $capture->disk, (string) $capture->image_path));
        } catch (RuntimeException|DecryptException $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * A captured image a browser can draw (WSQ is kept for exchange, not for printing).
     */
    public function printable(BiometricCapture $capture): bool
    {
        return $capture->image_path !== null
            && (bool) config("biometrics.image_types.{$capture->image_format}.displayable", false);
    }
}
