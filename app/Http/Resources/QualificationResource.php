<?php

namespace App\Http\Resources;

use App\Models\Qualification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Qualification
 */
class QualificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'kind' => $this->kind,
            'title' => $this->title,
            'institution' => $this->institution,
            'year' => $this->year,
            'subject' => $this->subject,
            'degree' => $this->degree,
            'result' => $this->result,
            'certificate_no' => $this->certificate_no,
            'sort_order' => $this->sort_order,
            'updated_at' => $this->updated_at,
        ];
    }
}
