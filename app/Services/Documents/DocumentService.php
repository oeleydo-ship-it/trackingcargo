<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Contracts\Documentable;
use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files land on the `local` disk, whose root (storage_path('app/private'))
 * is outside the public web root by default — not web-served, never linked
 * from `public/storage`. The stored filename is always an opaque UUID, never
 * derived from the upload's original name, per the architecture guardrail on
 * storage keys. Generic over any Documentable model (Phase 6's
 * CustomsClearance, Phase 7's DeliveryAttempt for POD) rather than one
 * service per owning model.
 */
final readonly class DocumentService
{
    private const string DISK = 'local';

    public function __construct(private AuditService $audit) {}

    public function upload(Model&Documentable $documentable, DocumentCategory $category, UploadedFile $file, User $actor): Document
    {
        $path = $file->storeAs(
            $documentable->documentStoragePath(),
            Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
            self::DISK,
        );

        return DB::transaction(function () use ($documentable, $category, $file, $path, $actor): Document {
            $document = $documentable->documents()->create([
                'category' => $category,
                'original_filename' => $file->getClientOriginalName(),
                'disk' => self::DISK,
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize() ?: 0,
                'uploaded_by' => $actor->getKey(),
            ]);

            $this->audit->record('document.uploaded', $actor, $document, newValues: $document->only(['category', 'original_filename']));

            return $document;
        });
    }

    public function delete(Document $document, User $actor): void
    {
        DB::transaction(function () use ($document, $actor): void {
            Storage::disk($document->disk)->delete($document->path);
            $document->delete();

            $this->audit->record('document.deleted', $actor, $document, oldValues: $document->only(['category', 'original_filename']));
        });
    }
}
