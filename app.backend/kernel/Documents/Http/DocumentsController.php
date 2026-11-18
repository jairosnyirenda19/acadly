<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Http;

use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Documents\Actions\AddVersion;
use Acadly\Kernel\Documents\Actions\RestoreTrash;
use Acadly\Kernel\Documents\Actions\StoreDocument;
use Acadly\Kernel\Documents\Actions\TrashDocuments;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentCatalogue;
use Acadly\Kernel\Documents\DocumentFile;
use Acadly\Kernel\Documents\DocumentReader;
use Acadly\Kernel\Documents\Storage\BlobStore;
use Acadly\Kernel\Documents\Thumbnails;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents over the API. Every answer goes through the one access rule; a
 * document the person may not open is "not found".
 */
final class DocumentsController
{
    /** Types a browser may show in place: images and PDF. Everything else downloads. */
    private const array INLINE = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(private readonly DocumentReader $reader) {}

    /**
     * Adds a document of a kind to its owner record.
     *
     * @response 201 array{id: string, kind: string, title: string, version: int}
     */
    public function store(Request $request, DocumentCatalogue $kinds, StoreDocument $store): JsonResponse
    {
        $request->validate([
            'kind' => ['required', 'string', 'max:120'],
            'owner_id' => ['required', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file'],
        ]);

        $key = $request->string('kind')->toString();

        if (! $kinds->has($key)) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        $title = $request->string('title')->toString();
        $document = $store->handle(self::user($request), $kinds->get($key), $request->string('owner_id')->toString(),
            self::upload($request), $title !== '' ? $title : null);

        return response()->json(['id' => $document->id, 'kind' => $document->kind, 'title' => $document->title, 'version' => 1], 201);
    }

    /**
     * A document, its versions and the records it is linked to.
     *
     * @response array{id: string, kind: string, title: string, owner_type: string, owner_id: string, held: bool, current_version: int, versions: list<array{version: int, name: string, type: string, size: int, note: string|null, uploaded_at: string}>, links: list<array{record_type: string, record_id: string}>}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $document = $this->reader->find(self::user($request), $id);
        $versions = $this->reader->versions($document);
        $current = 0;

        foreach ($versions as $version) {
            if ($version->id === $document->current_file_id) {
                $current = $version->version;
            }
        }

        return response()->json([
            'id' => $document->id,
            'kind' => $document->kind,
            'title' => $document->title,
            'owner_type' => $document->owner_type,
            'owner_id' => $document->owner_id,
            'held' => $document->isHeld(),
            'current_version' => $current,
            'versions' => array_map(fn (DocumentFile $file): array => $file->toArray(), $versions),
            'links' => $this->reader->links($document),
        ]);
    }

    /** The bytes of the current version, or of `?version=`, streamed. `?disposition=inline` shows an image or PDF in place. */
    public function content(Request $request, string $id): StreamedResponse
    {
        $who = self::user($request);
        $version = $request->query('version');
        $opened = $this->reader->open($who, $this->reader->find($who, $id), is_string($version) && ctype_digit($version) ? (int) $version : null);
        $file = $opened['file'];
        $inline = $request->query('disposition') === 'inline' && in_array($file->mime, self::INLINE, true);

        return new StreamedResponse(function () use ($opened): void {
            foreach ($opened['bytes'] as $piece) {
                echo $piece;
                flush();
            }
        }, 200, [
            'Content-Type' => $file->mime,
            'Content-Length' => (string) $file->size,
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $file->name, 'document'),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /**
     * Adds a new version of a document.
     *
     * @response 201 array{id: string, version: int}
     */
    public function addVersion(Request $request, string $id, AddVersion $add, Thumbnails $thumbnails): JsonResponse
    {
        $request->validate(['file' => ['required', 'file'], 'note' => ['nullable', 'string', 'max:1000'], 'preview' => ['nullable', 'file']]);
        $who = self::user($request);
        $note = $request->string('note')->toString();
        $document = $add->handle($who, $this->reader->find($who, $id), self::upload($request), $note !== '' ? $note : null);
        $preview = $request->file('preview');

        // A PDF's first page, drawn by the browser; the server draws it again (D-090).
        if ($preview instanceof UploadedFile && $document->current_file_id !== null) {
            $thumbnails->fromPreview($document->current_file_id, $preview);
        }

        return response()->json(['id' => $document->id, 'version' => count($this->reader->versions($document))], 201);
    }

    /**
     * The picture of the first page of the current version (D-090). A secret
     * document has none: its look is its content, and opening it is recorded.
     */
    public function thumbnail(Request $request, string $id, Thumbnails $thumbnails, BlobStore $blobs): StreamedResponse
    {
        $document = $this->reader->find(self::user($request), $id);
        $sha = $document->classification === FieldClass::Secret->value ? null : $thumbnails->of($document->current_file_id);

        if ($sha === null) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        return new StreamedResponse(function () use ($blobs, $sha): void {
            foreach ($blobs->read($sha) as $piece) {
                echo $piece;
            }
        }, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Moves a document to the trash, with a reason.
     *
     * @response array{batch: string}
     */
    public function trash(Request $request, string $id, TrashDocuments $trash): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $who = self::user($request);

        return response()->json(['batch' => $trash->handle($who, [$this->reader->find($who, $id)], $request->string('reason')->toString())]);
    }

    /**
     * Restores every document of one trash batch.
     *
     * @response array{restored: int}
     */
    public function restore(Request $request, string $batch, RestoreTrash $restore): JsonResponse
    {
        return response()->json(['restored' => $restore->handle(self::user($request), $batch)]);
    }

    private static function upload(Request $request): UploadedFile
    {
        $file = $request->file('file');

        return $file instanceof UploadedFile ? $file : throw ValidationException::withMessages(['file' => ['A file is required.']]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
