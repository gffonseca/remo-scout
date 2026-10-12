<?php

namespace App\Filament\Resources\Fixtures\Pages;

use App\Filament\Resources\Fixtures\FixtureResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFixture extends ViewRecord
{
    protected static string $resource = FixtureResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->label();
    }
}
