<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Posição em campo (com lateralidade quando aplicável).
 * Grupo = agrupamento do catálogo de papéis (catalogo-papeis-remo-scout.json).
 */
enum Position: string implements HasLabel
{
    case GOL = 'GOL';
    case ZAG = 'ZAG';
    case ZAGD = 'ZAGD';
    case ZAGE = 'ZAGE';
    case LD = 'LD';
    case LE = 'LE';
    case ALD = 'ALD';
    case ALE = 'ALE';
    case VOL = 'VOL';
    case MC = 'MC';
    case MEI = 'MEI';
    case MD = 'MD';
    case ME = 'ME';
    case PD = 'PD';
    case PE = 'PE';
    case SA = 'SA';
    case CA = 'CA';

    public function getLabel(): string
    {
        return match ($this) {
            self::GOL => 'Goleiro',
            self::ZAG => 'Zagueiro central',
            self::ZAGD => 'Zagueiro pelo lado direito',
            self::ZAGE => 'Zagueiro pelo lado esquerdo',
            self::LD => 'Lateral direito',
            self::LE => 'Lateral esquerdo',
            self::ALD => 'Ala direito',
            self::ALE => 'Ala esquerdo',
            self::VOL => 'Volante',
            self::MC => 'Meio-campista central',
            self::MEI => 'Meia ofensivo',
            self::MD => 'Meia pela direita',
            self::ME => 'Meia pela esquerda',
            self::PD => 'Ponta direita',
            self::PE => 'Ponta esquerda',
            self::SA => 'Segundo atacante',
            self::CA => 'Centroavante',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::GOL => 'Goleiro',
            self::ZAG, self::ZAGD, self::ZAGE => 'Zagueiro',
            self::LD, self::LE, self::ALD, self::ALE => 'Lateral/ala',
            self::VOL => 'Volante',
            self::MC => 'Meio-campista central',
            self::MEI => 'Meia ofensivo',
            self::MD, self::ME, self::PD, self::PE => 'Ponta/meia de lado',
            self::SA, self::CA => 'Atacante',
        };
    }

    /** @return array<string, string> code => "COD — Rótulo" */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->value] = $c->value.' — '.$c->getLabel();
        }

        return $out;
    }

    /** @return list<string> */
    public static function codesForGroup(string $group): array
    {
        return array_values(array_map(fn (self $p) => $p->value, array_filter(self::cases(), fn (self $p) => $p->group() === $group)));
    }

    /** Converte rótulos livres de planilha ("Lateral esquerdo", "LE", "lat. esq") em código. */
    public static function guess(?string $raw): ?self
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $s = mb_strtolower(trim($raw));
        $s = strtr($s, ['á' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        $upper = strtoupper($s);
        if ($p = self::tryFrom($upper)) {
            return $p;
        }
        $map = [
            'goleiro' => self::GOL, 'gk' => self::GOL, 'go' => self::GOL,
            'zagueiro' => self::ZAG, 'zag' => self::ZAG, 'cb' => self::ZAG, 'beque' => self::ZAG,
            'lateral direito' => self::LD, 'lat dir' => self::LD, 'rb' => self::LD,
            'lateral esquerdo' => self::LE, 'lat esq' => self::LE, 'lb' => self::LE,
            'ala direito' => self::ALD, 'rwb' => self::ALD,
            'ala esquerdo' => self::ALE, 'lwb' => self::ALE,
            'volante' => self::VOL, 'primeiro volante' => self::VOL, 'dm' => self::VOL, 'cdm' => self::VOL,
            'segundo volante' => self::MC, 'meio-campo' => self::MC, 'meio campo' => self::MC, 'cm' => self::MC, 'meio-campista' => self::MC,
            'meia' => self::MEI, 'meia ofensivo' => self::MEI, 'meia-atacante' => self::MEI, 'am' => self::MEI, 'cam' => self::MEI, 'armador' => self::MEI,
            'meia direita' => self::MD, 'rm' => self::MD,
            'meia esquerda' => self::ME, 'lm' => self::ME,
            'ponta direita' => self::PD, 'extremo direito' => self::PD, 'rw' => self::PD,
            'ponta esquerda' => self::PE, 'extremo esquerdo' => self::PE, 'lw' => self::PE,
            'segundo atacante' => self::SA, 'ss' => self::SA,
            'centroavante' => self::CA, 'atacante' => self::CA, 'cf' => self::CA, 'st' => self::CA, 'centro-avante' => self::CA,
        ];
        $s = str_replace(['.', '_'], [' ', ' '], $s);
        $s = preg_replace('/\s+/', ' ', $s);

        return $map[$s] ?? null;
    }
}
