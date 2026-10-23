<?php

namespace App\Http\Requests\Appointments\PublicBooking;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The free times of a place for a kind of visit on the public link (5.6), over some days (at most
 * appointments.availability.max_days; from the organization's today for a week when left out).
 */
class PublicAvailabilityRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'location' => ['required', 'string', 'size:26', Rule::exists('appointment_locations', 'public_id')->where('tenant_id', app(TenantContext::class)->id())],
            'service' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function days(): array
    {
        $from = (string) ($this->validated('from') ?? app(TenantContext::class)->today()->toDateString());
        $to = (string) ($this->validated('to') ?? date('Y-m-d', (int) strtotime($from.' +6 days')));
        $max = (int) config('appointments.availability.max_days');
        if ((strtotime($to) - strtotime($from)) / 86400 + 1 > $max) {
            $to = date('Y-m-d', (int) strtotime($from.' +'.($max - 1).' days'));
        }

        return [$from, $to];
    }
}
