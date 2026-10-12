<?php

namespace App\Http\Controllers;

use App\Console\Commands\PlayerPhotos;
use App\Models\Player;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Foto servida pelo próprio CIFUT (prompt mestre 3.7: armazenamento persistente e cache).
 * O navegador nunca acessa a fonte nem o bucket diretamente — redes que bloqueiam o host da fonte (filtros de DNS,
 * proxies) deixavam todas as fotos em branco. Primeira visualização baixa a imagem da fonte permitida e guarda no bucket.
 */
class PlayerPhotoController extends Controller
{
    private const CACHE_DIR = 'players/cache/';

    public function show(Player $player): Response
    {
        $disk = Storage::disk('media');
        $path = $player->photo_path;
        if (! $path) {
            if (! $player->photo_url || in_array($player->photo_status, Player::PHOTO_HIDDEN, true)) {
                abort(404);
            }
            $path = self::CACHE_DIR.sha1($player->photo_url);
            if (! $disk->exists($path)) {
                $img = PlayerPhotos::fetch($player->photo_url);
                if ($img['state'] === 'bloqueada') {
                    return redirect()->away($player->photo_url); // a fonte recusa o servidor, não o navegador (host já validado)
                }
                if ($img['state'] !== 'ok') {
                    abort(404); // a tela cai nas iniciais; o estado é gravado pela verificação em lote, não aqui
                }
                $disk->put($path, $img['body']);
            }
        }
        $bin = $disk->get($path);
        abort_if($bin === null, 404);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bin) ?: 'image/jpeg';
        abort_unless(str_starts_with($mime, 'image/'), 404);

        return response($bin, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }
}
