<?php

namespace App\Repositories\Eloquent;

use App\Models\BiometricCapture;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Repositories\Contracts\BiometricCaptureRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<BiometricCapture>
 */
class BiometricCaptureRepository extends EloquentRepository implements BiometricCaptureRepositoryInterface
{
    protected function model(): string
    {
        return BiometricCapture::class;
    }

    public function currentFor(Cardholder $cardholder, ?FamilyMember $member = null): Collection
    {
        return $this->ownedBy($cardholder, $member)
            ->current()
            ->with('capturer')
            ->orderBy('modality')
            ->orderBy('position')
            ->get();
    }

    public function markReplaced(Cardholder $cardholder, ?FamilyMember $member, string $modality, string $position): int
    {
        return $this->ownedBy($cardholder, $member)
            ->current()
            ->where('modality', $modality)
            ->where('position', $position)
            ->update(['replaced_at' => now(), 'current_key' => null]);
    }

    public function locationsFor(Cardholder $cardholder): Collection
    {
        return $this->locations($this->query()->where('cardholder_id', $cardholder->id));
    }

    public function locationsForMember(FamilyMember $member): Collection
    {
        return $this->locations($this->query()->where('family_member_id', $member->id));
    }

    /**
     * @param  Builder<BiometricCapture>  $query
     * @return Collection<int, array{disk: string, path: string}>
     */
    private function locations(Builder $query): Collection
    {
        return $query->whereNotNull('disk')
            ->get(['disk', 'image_path', 'template_path'])
            ->flatMap(fn (BiometricCapture $capture) => collect([$capture->image_path, $capture->template_path])
                ->filter()
                ->map(fn (string $path) => ['disk' => (string) $capture->disk, 'path' => $path]))
            ->values();
    }

    /**
     * Captures of the cardholder themselves, or of one of their family members.
     *
     * @return Builder<BiometricCapture>
     */
    private function ownedBy(Cardholder $cardholder, ?FamilyMember $member): Builder
    {
        return $this->query()
            ->where('cardholder_id', $cardholder->id)
            ->when(
                $member,
                fn (Builder $q, FamilyMember $m) => $q->where('family_member_id', $m->id),
                fn (Builder $q) => $q->whereNull('family_member_id'),
            );
    }
}
