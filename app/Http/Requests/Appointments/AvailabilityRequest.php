<?php

namespace App\Http\Requests\Appointments;

use App\Enums\BookingAudience;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * What a place has free: a kind of visit, the days (the organization's, both whole; from today and a week by
 * default, at most config('appointments.availability.max_days')), and for whom (staff by default; `public`
 * shows the public link's view); `preview` shows it as it will be once appointments and the place are on;
 * `except` (an appointment's id — for those who book) shows it as for moving that appointment, its own place free.
 */
class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::any(['appointments.view', 'settings.update']) && (! $this->filled('except') || Gate::allows('appointments.book'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'audience' => ['sometimes', new Enum(BookingAudience::class)],
            'preview' => ['sometimes', 'boolean'],
            'except' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('appointments', 'public_id')->where('tenant_id', app(TenantContext::class)->id())],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }
            [$from, $to] = $this->days();
            $max = (int) config('appointments.availability.max_days');
            if ((int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > $max) {
                $validator->errors()->add('to', __('appointments.availability.too_many_days', ['max' => app(ReportValueText::class)->number($max)]));
            }
        }];
    }

    /**
     * The days asked for: from today (the organization's) and a week, when not given.
     *
     * @return array{0: string, 1: string}
     */
    public function days(): array
    {
        $from = (string) $this->input('from', app(TenantContext::class)->today()->toDateString());

        return [$from, (string) $this->input('to', CarbonImmutable::parse($from)->addDays(6)->toDateString())];
    }

    public function audience(): BookingAudience
    {
        return BookingAudience::from((string) $this->input('audience', BookingAudience::Staff->value));
    }
}
