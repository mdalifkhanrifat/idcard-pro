<?php

namespace App\Http\Requests\Appointments;

use App\Services\Reports\ReportValueText;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Counting appointments by day and status (5.5g — the week view): the list's own criteria, with the days asked for
 * (`filter[from]`, `filter[to]`, both needed, at most appointments.summary.max_days of them).
 */
class SummarizeAppointmentsRequest extends ListAppointmentsRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'filter' => ['required', 'array:search,number,from,to,location,status,service,source,cardholder,family_member'],
            'filter.from' => ['required', 'date_format:Y-m-d'],
            'filter.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:filter.from'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $from = $this->input('filter.from');
            $to = $this->input('filter.to');
            $max = (int) config('appointments.summary.max_days');
            if ($validator->errors()->isEmpty() && is_string($from) && is_string($to) && (strtotime($to) - strtotime($from)) / 86400 + 1 > $max) {
                $validator->errors()->add('filter.to', __('appointments.summary.too_many_days', ['days' => app(ReportValueText::class)->number($max)]));
            }
        }];
    }
}
