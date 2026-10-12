<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Colunas, filtros, ordenação e itens por página salvos por usuário (Bloco 3, layout).
 * Usa a persistência em sessão do Filament: ao abrir, restaura da conta do usuário para a sessão;
 * ao final de cada interação, grava o que estiver na sessão em users.ui_preferences (vale em qualquer navegador).
 */
trait PersistsTablePreferences
{
    /**
     * Mesmas chaves de sessão do Filament (tabelas de resource: sem identificador e sem tenant), montadas sem
     * precisar da tabela já construída — o boot roda antes dela.
     *
     * @return list<string>
     */
    protected function tablePreferenceKeys(): array
    {
        $base = 'tables.'.md5(static::class).'_';

        return [$base.'filters', $base.'sort', $base.'columns', $base.'per_page', $base.'has_reordered_columns'];
    }

    protected function tablePreferenceId(): string
    {
        return class_basename(static::class);
    }

    public function bootPersistsTablePreferences(): void
    {
        $saved = auth()->user()?->ui_preferences['tables'][$this->tablePreferenceId()] ?? [];
        foreach ($this->tablePreferenceKeys() as $i => $key) {
            if (! session()->has($key) && array_key_exists((string) $i, $saved)) {
                session()->put($key, $saved[(string) $i]);
            }
        }
    }

    public function dehydratePersistsTablePreferences(): void
    {
        $u = auth()->user();
        if (! $u) {
            return;
        }
        $current = [];
        foreach ($this->tablePreferenceKeys() as $i => $key) {
            if (session()->has($key)) {
                $current[(string) $i] = session()->get($key);
            }
        }
        $prefs = $u->ui_preferences ?? [];
        if (($prefs['tables'][$this->tablePreferenceId()] ?? []) !== $current) {
            if ($current === []) {
                unset($prefs['tables'][$this->tablePreferenceId()]);
            } else {
                $prefs['tables'][$this->tablePreferenceId()] = $current;
            }
            $u->forceFill(['ui_preferences' => $prefs])->saveQuietly();
        }
    }

    protected function resetTablePreferencesAction(): Action
    {
        return Action::make('resetTablePrefs')->label('Restaurar colunas e filtros')->icon('heroicon-o-arrow-uturn-left')->color('gray')
            ->action(function () {
                $u = auth()->user();
                $prefs = $u->ui_preferences ?? [];
                unset($prefs['tables'][$this->tablePreferenceId()]);
                $u->forceFill(['ui_preferences' => $prefs])->saveQuietly();
                foreach ($this->tablePreferenceKeys() as $key) {
                    session()->forget($key);
                }
                Notification::make()->success()->title('Padrão restaurado')->send();
                $this->redirect(static::getUrl());
            });
    }
}
