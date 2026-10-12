<?php

namespace App\Http\Resources;

use App\Data\Reports\ReportColumn;
use App\Services\Reports\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a report: the report's fixed shape for API clients, and the chosen columns' values as the report
 * shows and downloads them (only when columns were asked for).
 *
 * @property Model $resource
 */
class ReportRowResource extends JsonResource
{
    /**
     * @param  Model  $resource
     * @param  array<int, ReportColumn>  $columns
     */
    public function __construct($resource, private ?Report $report = null, private array $columns = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...($this->report?->present($this->resource) ?? []),
            'values' => $this->when($this->columns !== [], fn () => collect($this->columns)->mapWithKeys(fn (ReportColumn $column) => [$column->key => $column->jsonValueOf($this->resource)])->all()),
        ];
    }
}
