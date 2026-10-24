<?php

namespace App\Data\Notifications;

use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;

/**
 * A setting of one notification event (5.7d), e.g. when "card ready" goes or how many days before a card's last
 * day it is reminded of: declared by the event (App\Services\Notifications\HasEventOptions), saved under
 * tenants.settings.notifications.events.<event>.options.<key>, and from there checked, completed, named and offered
 * to the notifications page with nothing written for it anywhere else. Its name, hint and choices are read from
 * lang/{en,bn}/notifications.php → events.<event>.options.<key>, or options.<key> for a setting several events
 * share (e.g. the appointment events' public booking link).
 *
 * Kinds: `choice` (one of `choices`) and `numbers` (a list of whole numbers from `min` to `max`, at most `maxItems`,
 * kept largest first, e.g. days before).
 */
final readonly class EventOption
{
    public const CHOICE = 'choice';

    public const NUMBERS = 'numbers';

    /**
     * @param  string|array<int, int>  $default
     * @param  array<int, string>  $choices
     */
    private function __construct(
        public string $key,
        public string $kind,
        public string|array $default,
        public array $choices = [],
        public int $min = 0,
        public int $max = 0,
        public int $maxItems = 0,
    ) {}

    /**
     * @param  array<int, string>  $choices
     */
    public static function choice(string $key, array $choices, string $default): self
    {
        return new self($key, self::CHOICE, $default, choices: array_values($choices));
    }

    /**
     * @param  array<int, int>  $default
     */
    public static function numbers(string $key, int $min, int $max, int $maxItems, array $default): self
    {
        return new self($key, self::NUMBERS, $default, min: $min, max: $max, maxItems: $maxItems);
    }

    /**
     * The saved value made whole: an unknown choice or a list with nothing usable takes the default; numbers out
     * of range are dropped, the rest kept once each, largest first, at most `maxItems`.
     *
     * @return string|array<int, int>
     */
    public function normalize(mixed $saved): string|array
    {
        if ($this->kind === self::CHOICE) {
            return in_array($saved, $this->choices, true) ? $saved : $this->default;
        }
        $numbers = collect(is_array($saved) ? $saved : [])
            ->filter(fn (mixed $number) => is_numeric($number) && (int) $number >= $this->min && (int) $number <= $this->max)
            ->map(fn (mixed $number) => (int) $number)->unique()->sortDesc()->take($this->maxItems)->values()->all();

        return $numbers !== [] ? $numbers : $this->default;
    }

    /**
     * How a change to it is checked, at `$path` (e.g. notifications.events.card_expiring.options.days).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $path): array
    {
        if ($this->kind === self::CHOICE) {
            return [$path => ['sometimes', Rule::in($this->choices)]];
        }

        return [
            $path => ['sometimes', 'array', 'min:1', "max:{$this->maxItems}"],
            "{$path}.*" => ['integer', 'distinct', "between:{$this->min},{$this->max}"],
        ];
    }

    /**
     * What a settings form needs, named in the reader's language.
     *
     * @return array{key: string, kind: string, label: string, hint: string, default: string|array<int, int>, choices?: array<int, array{key: string, name: string}>, min?: int, max?: int, max_items?: int, unit?: string}
     */
    public function describe(string $event): array
    {
        // The event's own words, or those several events share (notifications.options.<key>)
        $lang = Lang::has("notifications.events.{$event}.options.{$this->key}.label") ? "notifications.events.{$event}.options.{$this->key}" : "notifications.options.{$this->key}";
        $description = ['key' => $this->key, 'kind' => $this->kind, 'label' => (string) __("{$lang}.label"), 'hint' => (string) __("{$lang}.hint"), 'default' => $this->default];
        if ($this->kind === self::CHOICE) {
            return [...$description, 'choices' => array_map(fn (string $choice): array => ['key' => $choice, 'name' => (string) __("{$lang}.choices.{$choice}")], $this->choices)];
        }

        return [...$description, 'min' => $this->min, 'max' => $this->max, 'max_items' => $this->maxItems, 'unit' => (string) __("{$lang}.unit")];
    }
}
