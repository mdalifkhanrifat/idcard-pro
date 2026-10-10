<?php

namespace App\Services\Cards;

use App\Exceptions\CardRequirementsException;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Cards\Requirements\CardRequirement;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Everything a person must meet before a card is issued or printed (config/cards.php `requirements`),
 * checked the same way by the issue and print screens, batches, PDFs, the API and connected systems.
 * Someone with `cards.override_requirements` may go ahead anyway with a reason, which is kept in the
 * card's audit log.
 */
class CardRequirements
{
    public const MOMENTS = ['issue', 'print'];

    /** @var array<int, CardRequirement>|null */
    private ?array $requirements = null;

    /**
     * What is missing for the person at this moment.
     *
     * @return array<int, array{code: string, message: string, details: array<string, mixed>}>
     */
    public function problems(Cardholder $cardholder, ?FamilyMember $member, string $moment): array
    {
        return array_merge([], ...array_map(fn (CardRequirement $requirement): array => $requirement->problems($cardholder, $member, $moment), $this->requirements()));
    }

    /**
     * What is missing before a card can be printed (for its holder: the cardholder or the family member).
     *
     * @return array<int, array{code: string, message: string, details: array<string, mixed>}>
     */
    public function forCard(Card $card, string $moment = 'print'): array
    {
        return $this->problems($card->cardholder, $card->familyMember, $moment);
    }

    public function canOverride(?User $user): bool
    {
        return (bool) $user?->can('cards.override_requirements');
    }

    /**
     * Stop unless the person meets everything, or the user may override and gave a reason.
     *
     * @return array<int, array{code: string, message: string, details: array<string, mixed>}> problems that were overridden
     *
     * @throws CardRequirementsException
     */
    public function enforce(Cardholder $cardholder, ?FamilyMember $member, string $moment, ?string $overrideReason, User $actor): array
    {
        return $this->decide($this->problems($cardholder, $member, $moment), $moment, $overrideReason, $actor);
    }

    /**
     * The same for several cards at once (a batch): all of them may go ahead, or none.
     *
     * @param  Collection<int, Card>  $cards
     * @return array<string, array<int, array{code: string, message: string, details: array<string, mixed>}>> overridden problems per card public id
     *
     * @throws CardRequirementsException
     */
    public function enforceCards(Collection $cards, string $moment, ?string $overrideReason, User $actor): array
    {
        // Holders of the whole batch in two queries
        EloquentCollection::make($cards->all())->loadMissing(['cardholder', 'familyMember']);
        $perCard = [];
        foreach ($cards as $card) {
            if (($problems = $this->forCard($card, $moment)) !== []) {
                $perCard[$card->public_id] = array_map(fn (array $problem): array => [...$problem, 'card' => $card->number], $problems);
            }
        }
        $this->decide(array_merge([], ...array_values($perCard)), $moment, $overrideReason, $actor);

        return $perCard;
    }

    /**
     * Keep in the card's audit log that it was issued or printed although something was missing.
     *
     * @param  array<int, array{code: string, message: string, details: array<string, mixed>}>  $problems
     */
    public function recordOverride(Card $card, string $moment, string $reason, array $problems, User $actor): void
    {
        activity('cards')
            ->performedOn($card)
            ->causedBy($actor)
            ->event('requirements_overridden')
            ->withProperties(['moment' => $moment, 'reason' => $reason, 'requirements' => array_column($problems, 'code')])
            ->log('requirements_overridden');
    }

    /**
     * @param  array<int, array<string, mixed>>  $problems
     * @return array<int, array{code: string, message: string, details: array<string, mixed>}>
     */
    private function decide(array $problems, string $moment, ?string $overrideReason, User $actor): array
    {
        if ($problems === []) {
            return [];
        }
        $canOverride = $this->canOverride($actor);
        if (! $canOverride || blank($overrideReason)) {
            $message = __('cards.requirements.blocked', ['moment' => __("cards.requirements.moments.{$moment}")]).' '.implode(' ', array_unique(array_column($problems, 'message')));
            throw new CardRequirementsException($message, array_values($problems), $canOverride);
        }

        /** @var array<int, array{code: string, message: string, details: array<string, mixed>}> $problems */
        return $problems;
    }

    /**
     * @return array<int, CardRequirement>
     */
    private function requirements(): array
    {
        return $this->requirements ??= array_map(fn (string $class): CardRequirement => app($class), (array) config('cards.requirements', []));
    }
}
