<?php

namespace App\Support;

use App\Models\Club;
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
    public static function crestTag(?Club $club, int $size = 18): string
    {
        $url = $club?->crestUrl();
        if (! $url) {
            return '';
        }

        return '<img src="'.e($url).'" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()" style="width:'.$size.'px;height:'.$size.'px;object-fit:contain;display:inline-block;vertical-align:middle;margin-right:6px">';
    }

    /** Placeholder acessível e consistente: iniciais do atleta (sem rosto gerado). */
    public static function initialsAvatar(?string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim((string) $name)) ?: []));
        $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="32" fill="#2c4466"/>'
            .'<text x="32" y="40" font-family="Arial,sans-serif" font-size="24" font-weight="700" fill="#dbe7f5" text-anchor="middle">'.e($ini).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
