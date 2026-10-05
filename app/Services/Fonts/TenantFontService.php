<?php

namespace App\Services\Fonts;

use App\Exceptions\BusinessRuleException;
use App\Models\TenantFont;
use App\Repositories\Contracts\CardTemplateElementRepositoryInterface;
use App\Repositories\Contracts\TenantFontRepositoryInterface;
use App\Support\Media\FontInspector;
use App\Support\Media\UnreadableFontException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fonts an organization uploads for its card templates (licensed fonts that cannot be shipped
 * with the application). Files live on the private disk behind signed links.
 */
class TenantFontService
{
    /**
     * CSS generic families can never be a font name.
     */
    private const GENERIC_FAMILIES = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'inherit', 'initial'];

    public function __construct(
        private TenantFontRepositoryInterface $fonts,
        private CardTemplateElementRepositoryInterface $elements,
        private FontInspector $inspector,
        private TenantContext $tenancy,
    ) {}

    /**
     * @return Collection<int, TenantFont>
     */
    public function list(): Collection
    {
        return $this->fonts->list();
    }

    /**
     * @return array<int, string>
     */
    public function families(): array
    {
        return $this->fonts->families();
    }

    /**
     * Store one weight/style of a family. Without a family name the font's own name is used.
     */
    public function upload(UploadedFile $upload, ?string $family, int $weight, string $style): TenantFont
    {
        try {
            $font = $this->inspector->inspect((string) $upload->getRealPath());
        } catch (UnreadableFontException $exception) {
            throw ValidationException::withMessages(['file' => __($exception->reason === 'collection' ? 'fonts.collection' : 'fonts.unreadable')]);
        }

        $family = trim((string) ($family ?: $font->family ?: preg_replace('/[^\pL\pN \-_]+/u', ' ', pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME))));
        $family = (string) preg_replace('/\s+/', ' ', $family);
        if ($family === '') {
            throw ValidationException::withMessages(['family' => __('fonts.family_required')]);
        }
        $reserved = array_map(mb_strtolower(...), [...(array) config('card_template_elements.bundled_fonts'), ...self::GENERIC_FAMILIES]);
        if (in_array(mb_strtolower($family), $reserved, true)) {
            throw ValidationException::withMessages(['family' => __('fonts.reserved', ['family' => $family])]);
        }
        if ($this->fonts->faceExists($family, $weight, $style)) {
            throw ValidationException::withMessages(['family' => __('fonts.duplicate', ['family' => $family])]);
        }

        $disk = (string) config('cardholder_files.disk');
        $path = sprintf('tenants/%s/fonts/%s.%s', $this->tenancy->get()?->public_id, Str::lower((string) Str::ulid()), $font->extension());
        Storage::disk($disk)->put($path, (string) file_get_contents((string) $upload->getRealPath()));

        return $this->fonts->create([
            'family' => mb_substr($family, 0, 60),
            'weight' => $weight,
            'style' => $style,
            'format' => $font->format,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
            'size' => (int) $upload->getSize(),
        ]);
    }

    /**
     * A family that templates still use keeps at least one file.
     */
    public function delete(TenantFont $font): void
    {
        if (! $this->fonts->otherFacesExist($font)) {
            $templates = $this->elements->templatesUsingFont($font->family);
            if ($templates !== []) {
                throw new BusinessRuleException(__('fonts.in_use', ['family' => $font->family, 'templates' => implode(', ', $templates)]));
            }
        }

        $this->fonts->delete($font);
        Storage::disk($font->disk)->delete($font->path);
    }

    /**
     * Fonts are not personal data and are large, so the link stays the same for a day and is cached.
     */
    public function url(TenantFont $font): string
    {
        return URL::temporarySignedRoute(
            'api.v1.font-files.show',
            now()->startOfDay()->addDays(2),
            ['font' => $font->public_id, 'v' => substr(sha1($font->path), 0, 12)],
            absolute: false,
        );
    }

    /**
     * Stream a font for a signed link. The signature has already been verified.
     */
    public function stream(string $publicId, string $version): StreamedResponse
    {
        $font = $this->fonts->findAnyTenant($publicId);

        if ($font === null || substr(sha1($font->path), 0, 12) !== $version || ! Storage::disk($font->disk)->exists($font->path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk($font->disk)->response($font->path, basename($font->path), [
            'Content-Type' => FontInspector::MIME_TYPES[$font->format] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
