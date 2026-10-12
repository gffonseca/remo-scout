<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/** Campos de referência de vídeo (link para plataforma licenciada; não baixa/republica). */
class VideoFields
{
    public static function make(): array
    {
        $ts = '/^(\d{1,2}:)?\d{1,2}:\d{2}$/';

        return [
            TextInput::make('url')->label('URL do vídeo')->url()->required()->columnSpan(2),
            Select::make('platform')->label('Plataforma')->options(['wyscout' => 'Wyscout', 'youtube' => 'YouTube', 'outro' => 'Outra'])->default('wyscout'),
            TextInput::make('match_label')->label('Jogo')->placeholder('ex.: Remo x Paysandu, 12/03/2026'),
            TextInput::make('starts_at')->label('Início (mm:ss)')->regex($ts)->placeholder('12:30'),
            TextInput::make('ends_at')->label('Fim (mm:ss)')->regex($ts)->placeholder('12:55'),
            TextInput::make('tag')->label('Ação/tag')->placeholder('ex.: saída sob pressão'),
            Select::make('polarity')->label('Leitura')->options(['positivo' => 'Positiva', 'negativo' => 'Negativa', 'neutro' => 'Neutra/contexto'])->default('neutro')->required(),
            Textarea::make('note')->label('Nota')->rows(2)->columnSpanFull(),
        ];
    }
}
