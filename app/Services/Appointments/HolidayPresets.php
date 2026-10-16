<?php

namespace App\Services\Appointments;

use App\Exceptions\BusinessRuleException;
use App\Models\AppointmentClosure;
use App\Models\Tenant;
use App\Repositories\Contracts\AppointmentClosureRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The public holidays on a fixed date of the organization's country (config appointments.holiday_presets), to
 * add at once as yearly closures of every place: named in every language, and marked when such a closure is
 * already there. Each one added is a closure like any other (in the audit log, changed or deleted the same way).
 */
class HolidayPresets
{
    public function __construct(
        private AppointmentClosureRepositoryInterface $closures,
        private TenantContext $tenant,
    ) {}

    /**
     * The organization's country's holidays in the order of the year: key, day of the year ('MM-DD'), the name
     * in the reader's language, and whether every place is already closed on it every year.
     *
     * @return array{country: string|null, presets: array<int, array{key: string, date: string, name: string, added: bool}>}
     */
    public function for(Tenant $tenant): array
    {
        $country = $this->country($tenant);
        $added = $this->closures->yearlyEveryPlaceDays();
        $presets = collect($this->presets($country))
            ->map(fn (string $date, string $key): array => ['key' => $key, 'date' => $date, 'name' => $this->name($country, $key), 'added' => in_array($date, $added, true)])
            ->sortBy('date')
            ->values()
            ->all();

        return ['country' => $country, 'presets' => $presets];
    }

    /**
     * Adds the chosen holidays as yearly one-day closures of every place, from this year on (the organization's),
     * leaving out those already there. The reason is written in the organization's language, the other
     * language's name beside it.
     *
     * @param  array<int, string>  $keys
     * @return array{added: array<int, AppointmentClosure>, skipped: int}
     */
    public function add(Tenant $tenant, array $keys): array
    {
        $country = $this->country($tenant);
        $presets = $this->presets($country);
        if ($presets === []) {
            throw new BusinessRuleException(__('appointments.holidays.none'));
        }
        [$main, $other] = $this->languages($tenant);
        $year = $this->tenant->today()->year;

        return DB::transaction(function () use ($keys, $presets, $country, $main, $other, $year): array {
            $existing = $this->closures->yearlyEveryPlaceDays();
            $added = [];
            foreach (array_unique($keys) as $key) {
                $date = $presets[$key];
                if (in_array($date, $existing, true)) {
                    continue;
                }
                $added[] = $this->closures->create([
                    'location_id' => null,
                    'date_from' => "{$year}-{$date}",
                    'date_to' => "{$year}-{$date}",
                    'yearly' => true,
                    'reason' => $this->name($country, $key, $main),
                    'reason_local' => $other === null ? null : $this->name($country, $key, $other),
                ]);
                $existing[] = $date;
            }

            return ['added' => $added, 'skipped' => count(array_unique($keys)) - count($added)];
        });
    }

    /**
     * The keys a country offers (to check a request against).
     *
     * @return array<int, string>
     */
    public function keys(Tenant $tenant): array
    {
        return array_keys($this->presets($this->country($tenant)));
    }

    private function country(Tenant $tenant): ?string
    {
        return $tenant->country_code ? strtoupper($tenant->country_code) : null;
    }

    /**
     * @return array<string, string> key => 'MM-DD'
     */
    private function presets(?string $country): array
    {
        return $country === null ? [] : (array) config("appointments.holiday_presets.{$country}", []);
    }

    private function name(?string $country, string $key, ?string $language = null): string
    {
        $names = (array) __('appointments.holidays', [], $language);

        return (string) ($names[$country][$key] ?? $key);
    }

    /**
     * The organization's language for the reason, and another of the registry's for the reason beside it.
     *
     * @return array{0: string, 1: string|null}
     */
    private function languages(Tenant $tenant): array
    {
        $languages = AppointmentSettings::languages();
        $main = in_array($tenant->locale, $languages, true) ? (string) $tenant->locale : (string) ($languages[0] ?? 'en');
        $other = collect($languages)->first(fn (string $language): bool => $language !== $main);

        return [$main, $other];
    }
}
