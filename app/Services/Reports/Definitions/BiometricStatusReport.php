<?php

namespace App\Services\Reports\Definitions;

use App\Data\Cardholders\CardholderFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Enums\BiometricStatus;
use App\Enums\CardholderStatus;
use App\Models\BiometricCapture;
use App\Models\Cardholder;
use App\Models\User;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Services\Biometrics\BiometricRequirement;
use App\Services\Reports\Columns\CardholderColumns;
use App\Services\Reports\Concerns\DescribesCardholders;
use App\Services\Reports\NamesTotals;
use App\Services\Reports\Report;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use WeakMap;

/**
 * Who still needs which biometrics (5.8): the cardholder list's criteria, each cardholder's overall status and,
 * for every kind of biometric in the registry (a column each — a new kind comes by itself), how it stands against
 * the organization's settings — taken, still to take (which positions) or done with exceptions (which positions
 * could not be taken, and why) — worked out by the same rule as the enrollment screen and the card checks
 * (BiometricRequirement). And when the last capture was taken, by whom. Only statuses: never an image or template.
 * Family members' overall status is in the family members report.
 *
 * @implements Report<Cardholder>
 */
class BiometricStatusReport implements NamesTotals, Report
{
    use DescribesCardholders;

    /** @var WeakMap<Cardholder, array{applies: bool, modalities: array<string, array<string, mixed>>}> each row's evaluation, worked out once */
    private WeakMap $evaluations;

    public function __construct(
        private CardholderColumns $cardholderColumns,
        private CardholderRepositoryInterface $cardholders,
        private ReportValueText $text,
    ) {
        $this->evaluations = new WeakMap;
    }

    public function key(): string
    {
        return 'biometric_status';
    }

    /**
     * People's biometric state, as the cardholder list shows it to those who may see biometrics.
     */
    public function allows(User $user): bool
    {
        return $user->can('cardholders.view') && $user->can('biometrics.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $columns = [
            ...$this->cardholderColumns->for(fn (Cardholder $holder) => $holder),
            'cardholder.status' => $this->column('cardholder.status', ReportColumn::TEXT, 'cardholder', fn (Cardholder $holder) => (string) __("reports.values.cardholder_status.{$holder->status->value}")),
            'biometric.status' => $this->column('biometric.status', ReportColumn::TEXT, 'biometric', fn (Cardholder $holder) => (string) __("reports.values.biometric_status.{$holder->biometric_status->value}")),
        ];
        foreach (array_keys((array) config('biometrics.modalities')) as $modality) {
            $columns["biometric.{$modality}"] = new ReportColumn("biometric.{$modality}", (string) __("biometrics.modalities.{$modality}"), ReportColumn::TEXT, 'biometric', fn (Cardholder $holder) => $this->modalityText($holder, $modality));
        }

        return [
            ...$columns,
            'biometric.last_captured_at' => $this->column('biometric.last_captured_at', ReportColumn::DATETIME, 'biometric', fn (Cardholder $holder) => $this->lastCapture($holder)?->captured_at->setTimezone($timezone)),
            'biometric.last_captured_by' => $this->column('biometric.last_captured_by', ReportColumn::TEXT, 'biometric', fn (Cardholder $holder) => $this->lastCapture($holder)?->capturer?->name),
        ];
    }

    public function defaultColumns(): array
    {
        return ['cardholder.full_name', 'cardholder.employee_no', 'cardholder.rank', 'cardholder.department', 'biometric.status', ...array_map(fn (string $modality) => "biometric.{$modality}", $this->modalitiesOn()), 'biometric.last_captured_at'];
    }

    public function filters(): array
    {
        $option = fn (string $group) => fn (\BackedEnum $case) => ['value' => (string) $case->value, 'label' => (string) __("reports.values.{$group}.{$case->value}")];

        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_cardholders')),
            new ReportFilterField('biometrics', ReportFilterField::SELECT, $this->columnLabel('biometric.status'), array_map($option('biometric_status'), BiometricStatus::cases())),
            new ReportFilterField('status', ReportFilterField::SELECT, $this->columnLabel('cardholder.status'), array_map($option('cardholder_status'), CardholderStatus::cases())),
            ...$this->lookupFilters(),
            ...$this->customFieldFilters(),
        ];
    }

    public function sorts(): array
    {
        return [
            'cardholder.full_name' => 'full_name',
            'cardholder.employee_no' => 'employee_no',
            'cardholder.created_at' => 'created_at',
        ];
    }

    public function defaultSort(): string
    {
        return 'full_name';
    }

    public function totalKeys(): array
    {
        return ['cardholders', ...array_map(fn (BiometricStatus $status) => "biometric_{$status->value}", BiometricStatus::cases())];
    }

    public function totalLabel(string $key): ?string
    {
        return str_starts_with($key, 'biometric_') ? (string) __('reports.values.biometric_status.'.substr($key, 10)) : null;
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->cardholders->biometricReport($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->cardholders->biometricReportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['cardholders'];
    }

    public function totals(ReportQuery $query): array
    {
        $counts = $this->cardholders->biometricTotals($this->filter($query));

        return ['cardholders' => $counts['cardholders'], ...collect(BiometricStatus::cases())->mapWithKeys(fn (BiometricStatus $status) => ["biometric_{$status->value}" => $counts[$status->value]])->all()];
    }

    /**
     * @param  Cardholder  $row
     */
    public function present(Model $row): array
    {
        return $this->presentCardholder($row);
    }

    private function filter(ReportQuery $query): CardholderFilter
    {
        return CardholderFilter::fromArray($query->input(), biometrics: true);
    }

    /**
     * Kinds the organization captures (on, required or optional; none while biometrics are off), in registry order:
     * the columns shown by default.
     *
     * @return array<int, string>
     */
    private function modalitiesOn(): array
    {
        $settings = app(TenantContext::class)->get()?->biometricSettings();
        if (! ($settings['enabled'] ?? false)) {
            return [];
        }

        return array_values(array_filter(array_keys((array) config('biometrics.modalities')), fn (string $modality) => ($settings['modalities'][$modality]['mode'] ?? 'off') !== 'off'));
    }

    /**
     * How one kind stands for the person, in the reader's language: not captured here, taken (n of needed), done
     * with exceptions (which positions could not be taken, and why), or what is still to take; an optional kind
     * not taken yet is said to be optional.
     */
    private function modalityText(Cardholder $holder, string $modality): string
    {
        $state = $this->evaluate($holder)['modalities'][$modality] ?? null;
        if ($state === null) {
            return (string) __('reports.values.biometric_modality.off');
        }
        $counts = ['counted' => $this->text->number((int) $state['counted']), 'needed' => $this->text->number((int) $state['needed'])];
        if ($state['with_exceptions']) {
            $reasons = $holder->biometricCaptures->where('modality', $modality)->reject(fn (BiometricCapture $capture) => $capture->isCaptured())
                ->map(fn (BiometricCapture $capture) => __("biometrics.positions.{$capture->position}").($capture->unavailable_reason ? ': '.__("biometrics.reasons.{$capture->unavailable_reason}") : ''));

            return (string) __('reports.values.biometric_modality.exceptions', [...$counts, 'positions' => $reasons->implode(', ')]);
        }
        if ($state['complete']) {
            return (string) __('reports.values.biometric_modality.done', $counts);
        }

        return (string) __($state['required'] ? 'reports.values.biometric_modality.missing' : 'reports.values.biometric_modality.optional', [
            ...$counts,
            'positions' => implode(', ', array_map(fn (string $position) => (string) __("biometrics.positions.{$position}"), (array) $state['next'])),
        ]);
    }

    /**
     * @return array{applies: bool, modalities: array<string, array<string, mixed>>}
     */
    private function evaluate(Cardholder $holder): array
    {
        /** @var array{enabled: bool, modalities: array<string, array{mode: string, count: int, positions: array<int, string>, substitutes: bool}>, family_members: bool} $settings */
        $settings = (array) app(TenantContext::class)->get()?->biometricSettings();

        return $this->evaluations[$holder] ??= BiometricRequirement::evaluate($settings, $holder->biometricCaptures, false);
    }

    private function lastCapture(Cardholder $holder): ?BiometricCapture
    {
        return $holder->biometricCaptures->sortByDesc('captured_at')->first();
    }
}
