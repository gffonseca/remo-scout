<?php

namespace App\Sources;

use Carbon\Carbon;

/**
 * Parser da "Súmula On-line" (formato usado por FPF e CBF): extrai cabeçalho, escalações com ID CBF,
 * substituições, gols, cartões e acréscimos a partir do texto (pdftotext -layout).
 * Cada atleta guarda a linha-fonte para auditoria. Não extrai/guarda dados pessoais além de nome e ID CBF.
 */
class SumulaParser
{
    public static function textFromPdf(string $path): string
    {
        $out = [];
        $code = 0;
        exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null', $out, $code);
        if ($code !== 0) {
            throw new SourceException('Não foi possível ler o PDF (pdftotext).', 'formato');
        }

        return static::fixEncoding(implode("\n", $out));
    }

    /** Corrige mojibake comum (UTF-8 lido como Latin-1: "BrandÃ£o"). */
    public static function fixEncoding(string $s): string
    {
        if (preg_match('/Ã[\x{0080}-\x{00BF}£§¡©¢ª³º]/u', $s)) {
            $fixed = preg_replace_callback('/(?:Ã|Â)[\x{0080}-\x{00BF}£§¡©¢ª³ºµ¶·¸¹»¼½¾¿]/u', function ($m) {
                $c = @iconv('UTF-8', 'Windows-1252//IGNORE', $m[0]);

                return $c !== false && mb_check_encoding($c, 'UTF-8') ? $c : $m[0];
            }, $s);

            return $fixed ?? $s;
        }

        return $s;
    }

    public static function parse(string $text): array
    {
        $lines = preg_split('/\R/', $text);
        $data = ['competition' => null, 'season' => null, 'game_number' => null, 'round' => null, 'home' => null, 'away' => null,
            'date' => null, 'score_home' => null, 'score_away' => null, 'stoppage_1t' => null, 'stoppage_2t' => null,
            'players' => ['home' => [], 'away' => []], 'subs' => [], 'goals' => [], 'yellows' => [], 'reds' => []];

        $joined = implode("\n", $lines);
        if (preg_match('/Campeonato:\s*(.+?)\s{2,}/u', $joined, $m)) {
            $data['competition'] = trim($m[1]);
            if (preg_match('/(20\d\d)/', $m[1], $y)) {
                $data['season'] = $y[1];
            }
        }
        if (preg_match('/Jogo:\s*(\d+)/u', $joined, $m)) {
            $data['game_number'] = $m[1];
        }
        if (preg_match('/Rodada:\s*(\S+)/u', $joined, $m)) {
            $data['round'] = $m[1];
        }
        if (preg_match('/Jogo:\s+(.+?)\s+X\s+(.+?)\s*$/mu', $joined, $m)) {
            $data['home'] = static::teamName($m[1]);
            $data['away'] = static::teamName($m[2]);
        }
        if (preg_match('/Data:\s*(\d{2}\/\d{2}\/\d{4})/u', $joined, $m)) {
            $data['date'] = Carbon::createFromFormat('d/m/Y', $m[1])->toDateString();
        }
        if (preg_match('/Resultado Final:\s*(\d+)\s*X\s*(\d+)/u', $joined, $m)) {
            [$data['score_home'], $data['score_away']] = [(int) $m[1], (int) $m[2]];
        }
        if (preg_match_all('/Acréscimo:\s*(\d+)\s*min/u', $joined, $m)) {
            $data['stoppage_1t'] = isset($m[1][0]) ? (int) $m[1][0] : null;
            $data['stoppage_2t'] = isset($m[1][1]) ? (int) $m[1][1] : null;
        }

        $section = null;
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^Relação de Jogadores/u', $t)) {
                $section = 'players';

                continue;
            }
            if (preg_match('/^Comissão Técnica/u', $t)) {
                $section = null;

                continue;
            }
            if ($t === 'Gols') {
                $section = 'goals';

                continue;
            }
            if (preg_match('/^Cartões Amarelos/u', $t)) {
                $section = 'yellows';

                continue;
            }
            if (preg_match('/^Cartões Vermelhos/u', $t)) {
                $section = 'reds';

                continue;
            }
            if (preg_match('/^(Ocorrências|Observações Eventuais)/u', $t)) {
                $section = null;

                continue;
            }
            if ($t === 'Substituições') {
                $section = 'subs';

                continue;
            }
            if (preg_match('/^FEDERA|^CONFEDERA|Página \d+$/u', $t)) {
                continue;
            }

            if ($section === 'players') {
                // até dois atletas por linha: Nº  Apelido  Nome  T|R(g)?  P|A  CBF
                if (preg_match_all('/(?:^|\s)(\d{1,3})\s+(.+?)\s+(T|R)(\(g\))?\s+(P|A)\s+(\d{5,8})(?=\s|$)/u', $line, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $i => $m) {
                        $side = count($mm) === 2 ? ($i === 0 ? 'home' : 'away') : (mb_strpos($line, $m[0]) > mb_strlen($line) / 2 ? 'away' : 'home');
                        [$nick, $full] = static::splitNames(trim($m[2]));
                        $data['players'][$side][$m[1]] = ['shirt' => $m[1], 'nick' => $nick, 'full' => $full, 'starter' => $m[3] === 'T',
                            'gk' => $m[4] === '(g)', 'cbf_id' => $m[6], 'line' => mb_substr(trim($line), 0, 250)];
                    }
                }
            } elseif ($section === 'subs') {
                if (preg_match('/^(\+?\d{1,2}:\d{2})\s+(1T|2T)\s+(.+?)\s{2,}(\d{1,3})\s*-\s*(.+?)\s{2,}(\d{1,3})\s*-\s*(.+)$/u', $t, $m)) {
                    $data['subs'][] = ['clock' => $m[1], 'half' => $m[2], 'team' => static::teamName($m[3]), 'in' => $m[4], 'out' => $m[6]];
                }
            } elseif ($section === 'goals') {
                if (preg_match('/^(\+?\d{1,2}(?::\d{2})?)\s+([12])\s+(\d{1,3})\s+(NR|PN|CT|FT)\s+(.+?)\s{2,}(.+)$/u', $t, $m)) {
                    $data['goals'][] = ['clock' => $m[1], 'half' => $m[2].'T', 'shirt' => $m[3], 'type' => $m[4], 'name' => trim($m[5]), 'team' => static::teamName($m[6])];
                }
            } elseif ($section === 'yellows' || $section === 'reds') {
                if (preg_match('/^(\+?\d{1,2}:\d{2})\s+(1T|2T)\s+(\d{1,3})\s+(.+?)\s{2,}(.+)$/u', $t, $m)) {
                    $data[$section][] = ['clock' => $m[1], 'half' => $m[2], 'shirt' => $m[3], 'name' => trim($m[4]), 'team' => static::teamName($m[5])];
                }
            }
        }

        return $data;
    }

    public static function teamName(string $s): string
    {
        $s = trim(preg_replace('/\s*[\/-]\s*[A-Z]{2}\s*$/u', '', trim($s)));

        return $s;
    }

    /** "Mano   João Manoel Alves d ..." → [Mano, João Manoel Alves d...]; quando colado, apelido = primeiro termo. */
    private static function splitNames(string $s): array
    {
        if (preg_match('/^(.+?)\s*\.\.\.\s+(.+)$/u', $s, $m) && ! str_contains($m[2], '...') === false || preg_match('/^(.+?)\s\.\.\.\s+(\S.+)$/u', $s, $m)) {
            // apelido truncado seguido do nome ("Pedro Henr ... Pedro Henrique de ...")
            $full = trim($m[2]);
            $s = implode(' ', array_slice(explode(' ', trim(preg_replace('/\s*\S*\s*\.\.\.$/u', '', $full))), 0, 2)).'  '.$full;
        }
        $parts = preg_split('/\s{2,}/u', $s);
        if (count($parts) >= 2) {
            [$nick, $full] = [trim($parts[0]), trim(implode(' ', array_slice($parts, 1)))];
        } else {
            $words = preg_split('/\s+/u', $s);
            // colado ("RONALBSON Ronalbson de Souza d ...", "BRAIAN CUFREBraian Ezequiel Cufre"): apelido = 1º termo
            [$nick, $full] = count($words) > 2 ? [$words[0], implode(' ', array_slice($words, 1))] : [$s, null];
        }
        $nick = trim(preg_replace('/\s*\.\.\.$/u', '', $nick));
        $fullClean = $full ? trim(preg_replace('/\s*\S*\s*\.\.\.$/u', '', $full)) : null; // remove palavra truncada
        if ($full && str_ends_with($full, '...') && $fullClean) {
            // apelido truncado ("Nicolas Fe") → usa as duas primeiras palavras do nome completo
            if (mb_strlen($nick) >= 9 && str_contains($s, '...') && preg_match('/\.\.\.\s/u', $s)) {
                $nick = implode(' ', array_slice(explode(' ', $fullClean), 0, 2));
            }
        }
        if ($fullClean) {
            $fullClean = trim(preg_replace('/\s+(de|da|do|dos|das|e)$/iu', '', $fullClean));
        }
        if ($nick === mb_strtoupper($nick)) {
            $nick = mb_convert_case(mb_strtolower($nick), MB_CASE_TITLE);
        }

        return [$nick, $full && ! str_ends_with($full, '...') ? $full : ($fullClean ?: null)];
    }

    /** "29:00" + 2T → 74; "+05:00"/"+5" → fim do período (45/90). */
    public static function absMinute(string $clock, string $half): int
    {
        $base = $half === '2T' ? 45 : 0;
        if (str_starts_with($clock, '+')) {
            return $base + 45;
        }

        return $base + min(45, (int) explode(':', $clock)[0]);
    }
}
