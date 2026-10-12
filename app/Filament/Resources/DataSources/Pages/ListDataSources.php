<?php

namespace App\Filament\Resources\DataSources\Pages;

use App\Filament\Resources\DataSources\DataSourceResource;
use Filament\Resources\Pages\ListRecords;

class ListDataSources extends ListRecords
{
    protected static string $resource = DataSourceResource::class;

    public function getSubheading(): ?string
    {
        return 'Fontes não oficiais foram autorizadas por Gustavo em 11/10/2026: consulta sob demanda, com intervalo, cache e botão de desligar. Dados delas ficam marcados com a origem.';
    }
}
