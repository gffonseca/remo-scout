<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** Mídia enviada pelo departamento: URL temporária (bucket privado) ou pública (local). */
class Media
{
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $disk = Storage::disk('media');
        try {
            return config('filesystems.disks.media.driver') === 's3' ? $disk->temporaryUrl($path, now()->addMinutes(30)) : $disk->url($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /** <img> inline do escudo (lista/kanban). Vazio quando não há escudo. */
    public static function crestTag(?\App\Models\Club $club, int $size = 18): string
    {
        $url = $club?->crestUrl();
        if (! $url) {
            return '';
        }

        return '<img src="'.e($url).'" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()" style="width:'.$size.'px;height:'.$size.'px;object-fit:contain;display:inline-block;vertical-align:middle;margin-right:6px">';
    }
}
