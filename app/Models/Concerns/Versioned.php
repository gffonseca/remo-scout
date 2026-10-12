<?php

namespace App\Models\Concerns;

use App\Models\CatalogVersion;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Versionamento de itens do catálogo (papéis e especialidades).
 * Cada alteração de conteúdo incrementa `version` e grava snapshot;
 * avaliações antigas guardam a versão vigente quando foram feitas.
 */
trait Versioned
{
    /** Campos cuja alteração gera nova versão. */
    abstract protected function versionedFields(): array;

    public static function bootVersioned(): void
    {
        static::creating(fn ($m) => $m->version ??= 1);
        static::created(fn ($m) => $m->snapshotVersion('Versão inicial'));
        static::updating(function ($m) {
            if ($m->isDirty($m->versionedFields())) {
                $m->version = ((int) $m->getOriginal('version')) + 1;
            }
        });
        static::updated(function ($m) {
            if ($m->wasChanged('version')) {
                $m->snapshotVersion($m->pendingChangeNote ?? null);
            }
        });
    }

    public ?string $pendingChangeNote = null;

    public function versions(): MorphMany
    {
        return $this->morphMany(CatalogVersion::class, 'versionable')->orderByDesc('version');
    }

    public function snapshotVersion(?string $note): void
    {
        $this->versions()->create([
            'version' => $this->version,
            'snapshot' => $this->only(array_merge(['code', 'name', 'status'], $this->versionedFields())),
            'change_note' => $note,
            'changed_by' => auth()->id(),
        ]);
    }
}
