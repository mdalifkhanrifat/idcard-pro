<?php

namespace App\Services\Cardholders;

use App\Exceptions\BusinessRuleException;
use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Models\FamilyMember;
use App\Models\User;
use App\Repositories\Contracts\CardholderFileRepositoryInterface;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use App\Support\Media\ImageSanitizer;
use App\Support\Media\UnreadableImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Stores cardholder files (config/cardholder_files.php) on a private disk.
 * Family members keep their own photo and signature here too (collections marked `family`).
 * Files are only reachable through short-lived signed links.
 */
class CardholderFileService
{
    public function __construct(
        private CardholderFileRepositoryInterface $files,
        private ImageSanitizer $images,
        private CardRepositoryInterface $cards,
        private FamilyMemberRepositoryInterface $familyMembers,
    ) {}

    /**
     * Files of the cardholder, or of one of their family members when given.
     *
     * @return Collection<int, CardholderFile>
     */
    public function list(Cardholder $cardholder, ?string $collection = null, bool $includeReplaced = false, ?FamilyMember $member = null): Collection
    {
        return $this->files->listFor($cardholder, $collection, $includeReplaced, $member);
    }

    /**
     * The family member a request refers to; it must belong to the cardholder.
     */
    public function familyMemberOf(Cardholder $cardholder, ?string $publicId): ?FamilyMember
    {
        if ($publicId === null) {
            return null;
        }
        $member = $this->familyMembers->findByPublicId($publicId);
        if ($member === null || $member->cardholder_id !== $cardholder->id) {
            throw ValidationException::withMessages(['family_member' => __('cardholder_files.family_member_unknown')]);
        }

        return $member;
    }

    /**
     * Store a file for the cardholder, or for one of their family members. In single-file collections
     * the current file is archived, not deleted, so records that refer to it (e.g. issued cards) keep working.
     */
    public function upload(Cardholder $cardholder, string $collection, UploadedFile $upload, ?string $title, User $actor, ?FamilyMember $member = null): CardholderFile
    {
        $definition = config("cardholder_files.collections.{$collection}");
        if ($member !== null && ! ($definition['family'] ?? false)) {
            throw ValidationException::withMessages(['collection' => __('cardholder_files.not_for_family')]);
        }
        [$contents, $mimeType, $extension, $width, $height] = $this->prepare($upload, $definition);

        $disk = (string) config('cardholder_files.disk');
        $owner = $member === null ? '' : "family/{$member->public_id}/";
        $path = sprintf('tenants/%s/cardholders/%s/%s%s/%s.%s',
            $cardholder->tenant->public_id, $cardholder->public_id, $owner, $collection, Str::lower((string) Str::ulid()), $extension);

        Storage::disk($disk)->put($path, $contents);

        try {
            return DB::transaction(function () use ($cardholder, $member, $collection, $definition, $upload, $title, $actor, $disk, $path, $contents, $mimeType, $width, $height): CardholderFile {
                if ($definition['single']) {
                    $this->files->markReplaced($cardholder, $collection, $member);
                }

                return $this->files->create([
                    'cardholder_id' => $cardholder->id,
                    'family_member_id' => $member?->id,
                    'collection' => $collection,
                    'title' => $title,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => Str::limit(basename($upload->getClientOriginalName()), 250, ''),
                    'mime_type' => $mimeType,
                    'size' => strlen($contents),
                    'width' => $width,
                    'height' => $height,
                    'checksum' => hash('sha256', $contents),
                    'uploaded_by' => $actor->id,
                ])->load(['uploader', 'familyMember']);
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    public function delete(CardholderFile $file): void
    {
        if ($this->cards->usesFile($file->id)) {
            throw new BusinessRuleException(__('cards.in_use.file'));
        }
        $this->files->delete($file);
        Storage::disk($file->disk)->delete($file->path);
    }

    /**
     * Where a cardholder's files are stored; read before the records are deleted.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function storedLocations(Cardholder $cardholder): Collection
    {
        return $this->files->locationsFor($cardholder);
    }

    /**
     * Where a family member's own files are stored; read before the member is deleted.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function storedLocationsOfMember(FamilyMember $member): Collection
    {
        return $this->files->locationsForMember($member);
    }

    /**
     * Remove stored files once their records are gone.
     *
     * @param  Collection<int, array{disk: string, path: string}>  $locations
     */
    public function deleteStored(Collection $locations): void
    {
        $locations->groupBy('disk')
            ->each(fn (Collection $group, string $disk) => Storage::disk($disk)->delete($group->pluck('path')->all()));
    }

    public function temporaryUrl(CardholderFile $file): string
    {
        return URL::temporarySignedRoute(
            'api.v1.cardholder-files.content',
            now()->addMinutes((int) config('cardholder_files.url_ttl')),
            ['file' => $file->public_id],
            absolute: false,
        );
    }

    /**
     * Stream a file for a signed link. The signature has already been verified.
     */
    public function stream(string $publicId): StreamedResponse
    {
        $file = $this->files->findAnyTenant($publicId);

        if ($file === null || ! Storage::disk($file->disk)->exists($file->path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk($file->disk)->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /**
     * @param  array{image: bool, max_dimension?: int}  $definition
     * @return array{0: string, 1: string, 2: string, 3: int|null, 4: int|null}
     */
    private function prepare(UploadedFile $upload, array $definition): array
    {
        if (! $definition['image']) {
            return [
                (string) $upload->get(),
                (string) $upload->getMimeType(),
                (string) ($upload->guessExtension() ?? 'bin'),
                null,
                null,
            ];
        }

        try {
            $image = $this->images->sanitize((string) $upload->getRealPath(), $definition['max_dimension'] ?? 1200);
        } catch (UnreadableImageException) {
            throw ValidationException::withMessages(['file' => __('cardholder_files.unreadable_image')]);
        }

        return [$image->contents, $image->mimeType, $image->extension, $image->width, $image->height];
    }
}
