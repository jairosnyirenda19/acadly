<?php

declare(strict_types=1);

use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\AuditCatalogue;
use Acadly\Kernel\Documents\Actions\AddVersion;
use Acadly\Kernel\Documents\Actions\HoldDocument;
use Acadly\Kernel\Documents\Actions\LinkDocument;
use Acadly\Kernel\Documents\Actions\PurgeTrash;
use Acadly\Kernel\Documents\Actions\RestoreTrash;
use Acadly\Kernel\Documents\Actions\RestoreVersion;
use Acadly\Kernel\Documents\Actions\StoreDocument;
use Acadly\Kernel\Documents\Actions\TrashDocuments;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentCatalogue;
use Acadly\Kernel\Documents\DocumentPermission;
use Acadly\Kernel\Documents\DocumentReader;
use Acadly\Kernel\Documents\Scanning\MissingScanner;
use Acadly\Kernel\Documents\Scanning\VirusScanner;
use Acadly\Kernel\Documents\Storage\BlobStore;
use Acadly\Kernel\Documents\Storage\StorageMeter;
use Acadly\Kernel\Events\EventCatalogue;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\Licence\Licence;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\Licence\LicenceStatus;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Notify\NoticeCatalogue;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\AttendanceModule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Toy\Contracts\ToyDocument;
use Tests\Fixtures\Toy\Contracts\ToyPermission;
use Tests\Fixtures\Toy\ToyErrand;
use Tests\Fixtures\Toy\ToyModule;

uses(DatabaseTransactions::class);

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

beforeEach(function (): void {
    Storage::fake('documents');
    config(['acadly.documents.chunk_size' => 64 * 1024]);

    app()->instance(ModuleRegistry::class, new ModuleRegistry([new InstitutionCore, new AttendanceModule, new ToyModule]));

    foreach ([PermissionCatalogue::class, AuditCatalogue::class, EventCatalogue::class, NoticeCatalogue::class, Notifier::class,
        DocumentCatalogue::class, Audit::class, Outbox::class] as $service) {
        app()->forgetInstance($service);
    }

    app()->forgetScopedInstances();
    $this->artisan('acadly:sync')->assertSuccessful();
});

/** @param  list<ToyPermission|DocumentPermission>  $permissions */
function clerk(array $permissions = [ToyPermission::Submit]): User
{
    $user = User::factory()->create();
    $role = Role::query()->create(['key' => 'docs_'.substr(Ids::next(), -12), 'name' => 'Documents']);
    $role->grant($permissions);

    RoleAssignment::query()->create([
        'user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'institution',
        'scope_id' => null, 'starts_at' => now()->subMinute(),
    ]);

    return $user;
}

function errand(): ToyErrand
{
    return ToyErrand::query()->create(['title' => 'Collect the register books', 'state' => 'draft', 'journey_ref' => 'toy.errand@2026.1']);
}

function pdf(string $text = 'Receipt for the register books'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n% {$text}\n%%EOF\n");
}

function png(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('photo.png', (string) base64_decode(PNG));
}

function storeReceipt(User $who, ?ToyErrand $owner = null, ?UploadedFile $file = null): Document
{
    return app(StoreDocument::class)->handle($who, ToyDocument::Receipt, ($owner ?? errand())->id, $file ?? pdf());
}

/** Reads the whole current content back through the reader. */
function contentOf(User $who, Document $document, ?int $version = null): string
{
    return implode('', iterator_to_array(app(DocumentReader::class)->open($who, $document, $version)['bytes'], false));
}

describe('storing', function (): void {
    it('stores a document with one owner, its first file, encrypted, and reads it back', function (): void {
        $who = clerk();
        $errand = errand();
        $document = storeReceipt($who, $errand);
        $file = DB::table('document_files')->where('document_id', $document->id)->first();
        $blob = DB::table('blobs')->where('sha256', $file?->blob_sha256)->first();
        $onDisk = Storage::disk('documents')->get((string) $blob?->storage_path);

        expect($document->owner_type)->toBe('toy_errand')
            ->and($document->owner_id)->toBe($errand->id)
            ->and($document->title)->toBe('receipt')
            ->and($document->classification)->toBe('personal')
            ->and($file?->version_no)->toBe(1)
            ->and($file?->mime_type)->toBe('application/pdf')
            ->and($onDisk)->not->toContain('Receipt for the register books')
            ->and(contentOf($who, $document))->toBe("%PDF-1.4\n% Receipt for the register books\n%%EOF\n")
            ->and(DB::table('audit_events')->where('action', 'documents.stored')->where('subject_id', $document->id)->exists())->toBeTrue();
    });

    it('stores the same content once, however many documents hold it', function (): void {
        $who = clerk();
        storeReceipt($who);
        storeReceipt($who);

        expect(DB::table('document_files')->distinct()->count('blob_sha256'))->toBe(1)
            ->and(DB::table('blobs')->count())->toBe(1);
    });

    it('streams a large file in pieces and verifies every piece', function (): void {
        $who = clerk();
        $big = UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n".str_repeat('A long scanned page. ', 20000));
        $document = storeReceipt($who, file: $big);
        $sha = (string) DB::table('document_files')->where('document_id', $document->id)->value('blob_sha256');

        expect(iterator_count(app(BlobStore::class)->read($sha)))->toBeGreaterThan(5)
            ->and(app(BlobStore::class)->verify($sha))->toBeTrue();
    });

    it('refuses to read bytes that were changed on disk', function (): void {
        $document = storeReceipt($who = clerk());
        $path = (string) DB::table('blobs')->value('storage_path');
        $stored = (string) Storage::disk('documents')->get($path);
        Storage::disk('documents')->put($path, substr($stored, 0, 20).chr(ord($stored[20]) ^ 1).substr($stored, 21));

        expect(fn () => contentOf($who, $document))->toThrow(RuntimeException::class, 'does not verify');
    });
});

describe('the upload gate', function (): void {
    it('refuses a type the kind does not accept, judged by content, not by name', function (): void {
        $fake = UploadedFile::fake()->createWithContent('receipt.pdf', 'This is plain text with a PDF name.');

        expect(fn () => storeReceipt(clerk(), file: $fake))->toThrow(ValidationException::class);
        expect(DB::table('audit_events')->where('refusal', 'upload:type')->exists())->toBeTrue()
            ->and(DB::table('documents')->count())->toBe(0);
    });

    it('refuses a file over the kind limit', function (): void {
        $large = UploadedFile::fake()->createWithContent('photo.png', (string) base64_decode(PNG).str_repeat("\0", 2000));

        expect(fn () => app(StoreDocument::class)->handle(clerk(), ToyDocument::Photo, errand()->id, $large))
            ->toThrow(ValidationException::class, 'larger than');
    });

    it('refuses every upload when no virus scanner can be asked', function (): void {
        app()->instance(VirusScanner::class, new MissingScanner);

        expect(fn () => storeReceipt(clerk()))->toThrow(ValidationException::class, 'could not be checked for viruses');
        expect(DB::table('audit_events')->where('refusal', 'upload:scanner_unavailable')->exists())->toBeTrue();
    });

    it('refuses an infected file and keeps none of it', function (): void {
        app()->instance(VirusScanner::class, new class implements VirusScanner
        {
            public function scan(string $path): ?string
            {
                return 'Eicar-Test-Signature';
            }
        });

        expect(fn () => storeReceipt(clerk()))->toThrow(ValidationException::class, 'contains a virus');
        expect(DB::table('blobs')->count())->toBe(0);
    });

    it('stops ordinary documents when storage is full, never essential ones, and never content already stored', function (): void {
        $who = clerk();
        $errand = errand();
        app(StoreDocument::class)->handle($who, ToyDocument::Photo, $errand->id, png());

        (fn () => $this->current = new Licence(LicenceStatus::Valid, 'T-1', 'Test', [], ['storage_gb' => 0]))->call(app(Licences::class));
        app(StorageMeter::class)->forget();

        $other = UploadedFile::fake()->createWithContent('other.png', (string) base64_decode(PNG)."\0");

        expect(fn () => app(StoreDocument::class)->handle($who, ToyDocument::Photo, $errand->id, $other))
            ->toThrow(ValidationException::class, 'storage is full');
        expect(app(StoreDocument::class)->handle($who, ToyDocument::Photo, $errand->id, png()))->toBeInstanceOf(Document::class)
            ->and(storeReceipt($who, $errand))->toBeInstanceOf(Document::class);
    });
});

describe('access', function (): void {
    it('refuses to store for a person without the kind permission', function (): void {
        expect(fn () => storeReceipt(clerk([ToyPermission::Complete])))->toThrow(AuthorizationException::class);
    });

    it('answers not found to a person who may not open the document', function (): void {
        $document = storeReceipt(clerk());
        $outsider = clerk([ToyPermission::Complete]);

        expect(fn () => app(DocumentReader::class)->find($outsider, $document->id))->toThrow(ModelNotFoundException::class);
        $this->actingAs($outsider)->getJson("/api/v1/documents/{$document->id}")->assertNotFound();
    });

    it('records the read of a personal document, and not of a plain one', function (): void {
        $who = clerk();
        $receipt = storeReceipt($who);
        $photo = app(StoreDocument::class)->handle($who, ToyDocument::Photo, errand()->id, png());

        contentOf($who, $receipt);
        contentOf($who, $photo);

        expect(DB::table('audit_events')->where('action', 'documents.read')->pluck('subject_id')->all())->toBe([$receipt->id]);
    });

    it('opens a document whose owner record is gone only for document managers', function (): void {
        $who = clerk();
        $errand = errand();
        $document = storeReceipt($who, $errand);
        $errand->delete();

        expect(fn () => app(DocumentReader::class)->find($who, $document->id))->toThrow(ModelNotFoundException::class)
            ->and(app(DocumentReader::class)->find(clerk([DocumentPermission::Manage]), $document->id)->id)->toBe($document->id);
    });
});

describe('versions and links', function (): void {
    it('keeps every version, and restores an old one as a new version', function (): void {
        $who = clerk();
        $document = storeReceipt($who);
        app(AddVersion::class)->handle($who, $document, pdf('Corrected receipt'), 'Corrected the amount.');
        app(RestoreVersion::class)->handle($who, $document->refresh(), 1);

        $versions = app(DocumentReader::class)->versions($document->refresh());

        expect(array_map(fn ($file): int => $file->version, $versions))->toBe([3, 2, 1])
            ->and($versions[0]->sha)->toBe($versions[2]->sha)
            ->and($versions[0]->note)->toBe('Restored from version 1.')
            ->and(contentOf($who, $document))->toContain('Receipt for the register books')
            ->and(contentOf($who, $document, 2))->toContain('Corrected receipt');
    });

    it('links a document to other records without giving anyone access', function (): void {
        $who = clerk();
        $document = storeReceipt($who);
        app(LinkDocument::class)->handle($who, $document, 'toy_class', 'class-7');

        expect(app(DocumentReader::class)->linkedTo($who, 'toy_class', 'class-7')->pluck('id')->all())->toBe([$document->id])
            ->and(app(DocumentReader::class)->linkedTo(clerk([ToyPermission::Complete]), 'toy_class', 'class-7'))->toBeEmpty()
            ->and(fn () => app(LinkDocument::class)->handle(clerk([ToyPermission::Complete]), $document, 'toy_class', 'class-8'))
            ->toThrow(ModelNotFoundException::class);
    });
});

describe('trash and holds', function (): void {
    it('trashes with a reason, restores a batch together, and refuses a held document', function (): void {
        $who = clerk([ToyPermission::Submit, DocumentPermission::Manage]);
        $errand = errand();
        $a = storeReceipt($who, $errand);
        $b = app(StoreDocument::class)->handle($who, ToyDocument::Photo, $errand->id, png());

        $batch = app(TrashDocuments::class)->handle($who, [$a, $b], 'Uploaded to the wrong errand.');

        expect(app(DocumentReader::class)->ownedBy($who, 'toy_errand', $errand->id))->toBeEmpty()
            ->and(app(RestoreTrash::class)->handle($who, $batch))->toBe(2)
            ->and(app(DocumentReader::class)->ownedBy($who, 'toy_errand', $errand->id))->toHaveCount(2);

        app(HoldDocument::class)->handle($who, $a->refresh(), true, 'Inquiry into the purchase.');

        expect(fn () => app(TrashDocuments::class)->handle($who, [$a->refresh()], 'Tidy up.'))->toThrow(ValidationException::class, 'on hold');
    });

    it('empties the trash after the trash period and removes bytes no live document uses', function (): void {
        $who = clerk();
        $kept = storeReceipt($who);
        $gone = storeReceipt($who);
        $alone = storeReceipt($who, file: pdf('Only here'));
        app(TrashDocuments::class)->handle($who, [$gone, $alone], 'Duplicates.');

        $this->travel(31)->days();

        expect(app(PurgeTrash::class)->handle())->toBe(2)
            ->and(Document::query()->whereNotNull('purged_at')->count())->toBe(2)
            ->and(DB::table('blobs')->whereNotNull('released_at')->count())->toBe(1)
            ->and(contentOf($who, $kept))->toContain('Receipt for the register books')
            ->and(fn () => app(DocumentReader::class)->find($who, $alone->id))->toThrow(ModelNotFoundException::class);
    });

    it('warns the document managers once when storage passes a mark', function (): void {
        DB::table('blobs')->insert([
            'sha256' => str_repeat('a', 64), 'storage_path' => 'aa/aa/x', 'byte_size' => 1, 'stored_size' => (int) (0.96 * 1024 ** 3),
            'mime_type' => 'application/pdf', 'chunk_size' => 65536, 'wrapped_key' => '', 'wrap_nonce' => '', 'key_id' => 'k1',
            'created_at' => now(),
        ]);
        (fn () => $this->current = new Licence(LicenceStatus::Valid, 'T-1', 'Test', [], ['storage_gb' => 1]))->call(app(Licences::class));

        expect(app(StorageMeter::class)->warnIfNeeded())->toBe(95)
            ->and(app(StorageMeter::class)->warnIfNeeded())->toBe(95);

        expect(DB::table('notification_requests')->where('topic_key', 'documents.storage_filling')->count())->toBe(1);
    });
});

describe('the API', function (): void {
    it('uploads, describes and streams a document', function (): void {
        $who = clerk();
        $errand = errand();

        $id = $this->actingAs($who)->post('/api/v1/documents', ['kind' => 'toy.photo', 'owner_id' => $errand->id, 'file' => png()], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');

        $this->actingAs($who)->getJson("/api/v1/documents/{$id}")
            ->assertOk()->assertJsonPath('current_version', 1)->assertJsonPath('versions.0.type', 'image/png');

        $response = $this->actingAs($who)->get("/api/v1/documents/{$id}/content?disposition=inline");

        $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
            ->and($response->streamedContent())->toBe((string) base64_decode(PNG));
    });

    it('answers not found for a kind that is not in the build', function (): void {
        $this->actingAs(clerk())->post('/api/v1/documents', ['kind' => 'nothing.here', 'owner_id' => errand()->id, 'file' => png()], ['Accept' => 'application/json'])
            ->assertNotFound();
    });
});
