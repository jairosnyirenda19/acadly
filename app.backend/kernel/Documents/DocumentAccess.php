<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Identity\User;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The one rule for who may open or change a document (D-053): the kind's
 * permission, held at the scope of the owner record. A link to another
 * record adds nothing. A document whose owner record no longer exists is
 * open only to `documents.manage`. Callers answer "not found" when this
 * says no, so a refusal never confirms that a document exists.
 */
final class DocumentAccess
{
    public function __construct(
        private readonly DocumentCatalogue $kinds,
        private readonly Access $access,
        private readonly Container $container,
    ) {}

    public function canRead(?User $who, Document $document): bool
    {
        return $this->allowed($who, $document, false);
    }

    public function canWrite(?User $who, Document $document): bool
    {
        return $this->allowed($who, $document, true);
    }

    /** May this person add a document of this kind to this owner record? */
    public function canStore(?User $who, DocumentKind $kind, string $ownerId): bool
    {
        $scope = $this->scopeOf($kind, $ownerId);

        return $scope !== null && $this->access->allows($who, $kind->writePermission(), $scope);
    }

    public function scopeOf(DocumentKind $kind, string $ownerId): ?ScopeRef
    {
        return $this->resolver($kind)->scopeOf($ownerId);
    }

    public function ownerLabel(DocumentKind $kind, string $ownerId): string
    {
        return $this->resolver($kind)->labelOf($ownerId);
    }

    private function allowed(?User $who, Document $document, bool $write): bool
    {
        if ($document->purged_at !== null || ! $this->kinds->has($document->kind)) {
            return false;
        }

        $kind = $this->kinds->get($document->kind);
        $scope = $this->scopeOf($kind, $document->owner_id);

        if ($scope === null) {
            return $this->access->allows($who, DocumentPermission::Manage);
        }

        return $this->access->allows($who, $write ? $kind->writePermission() : $kind->readPermission(), $scope);
    }

    private function resolver(DocumentKind $kind): DocumentOwner
    {
        $resolver = $this->container->make($kind->owner());

        return $resolver instanceof DocumentOwner ? $resolver : throw new LogicException("[{$kind->owner()}] is not a document owner.");
    }
}
