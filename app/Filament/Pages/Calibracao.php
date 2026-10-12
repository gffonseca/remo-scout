<?php

namespace App\Filament\Pages;

use App\Services\Calibration;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Divergências entre analistas no mesmo atleta, papel e fase — pauta de calibração (F22). */
class Calibracao extends Page
{
    protected string $view = 'filament.pages.calibracao';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Papéis e especialidades';

    protected static ?string $navigationLabel = 'Calibração';

    protected static ?string $title = 'Calibração entre analistas';

    protected static ?string $slug = 'calibracao';

    public function rows()
    {
        return Calibration::divergences();
    }
}
