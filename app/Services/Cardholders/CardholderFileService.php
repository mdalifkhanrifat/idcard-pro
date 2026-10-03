<?php

namespace App\Services\Cardholders;

use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Models\User;
use App\Repositories\Contracts\CardholderFileRepositoryInterface;
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
 * Files are only reachable through short-lived signed links.
 */
class CardholderFileService
{
    public function __construct(
        private CardholderFileRepositoryInterface $files,
        private ImageSanitizer $images,
    ) {}

    /**
     * @return Collection<int, CardholderFile>
     */
    public function list(Cardholder $cardholder, ?string $collection = null, bool $includeReplaced = false): Collection
    {
        return $this->files->listFor($cardholder, $collection, $includeReplaced);
    }

    /**
     * Store a file. In single-file collections the current file is archived, not deleted,
     * so records that refer to it (e.g. issued cards) keep working.
     */
    public function upload(Cardholder $cardholder, string $collection, UploadedFile $upload, ?string $title, User $actor): CardholderFile
    {
        $definition = config("cardholder_files.collections.{$collection}");
        [$contents, $mimeType, $extension, $width, $height] = $this->prepare($upload, $definition);

        $disk = (string) config('cardholder_files.disk');
        $path = sprintf('tenants/%s/cardholders/%s/%s/%s.%s',
            $cardholder->tenant->public_id, $cardholder->public_id, $collection, Str::lower((string) Str::ulid()), $extension);

        Storage::disk($disk)->put($path, $contents);

        try {
            return DB::transaction(function () use ($cardholder, $collection, $definition, $upload, $title, $actor, $disk, $path, $contents, $mimeType, $width, $height): CardholderFile {
                if ($definition['single']) {
                    $this->files->markReplaced($cardholder, $collection);
                }

                return $this->files->create([
                    'cardholder_id' => $cardholder->id,
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
                ])->load('uploader');
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    public function delete(CardholderFile $file): void
    {
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
