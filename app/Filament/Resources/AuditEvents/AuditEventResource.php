<?php

namespace App\Filament\Resources\AuditEvents;

use App\Models\AuditEvent;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static ?string $slug = 'auditoria';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'evento de auditoria';

    protected static ?string $pluralModelLabel = 'Trilha de auditoria';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s'),
            TextEntry::make('user.name')->label('Quem')->placeholder('sistema'),
            TextEntry::make('action')->label('Ação')->badge(),
            TextEntry::make('auditable_type')->label('Objeto'),
            TextEntry::make('auditable_id')->label('ID'),
            TextEntry::make('ip')->label('IP')->placeholder('—'),
            KeyValueEntry::make('changes')->label('Dados')->columnSpanFull()
                ->state(fn (AuditEvent $e) => collect($e->changes ?? [])->map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v)->all()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('user.name')->label('Quem')->placeholder('sistema'),
                TextColumn::make('action')->label('Ação')->badge(),
                TextColumn::make('auditable_type')->label('Objeto'),
                TextColumn::make('auditable_id')->label('ID')->limit(12),
            ])
            ->filters([
                SelectFilter::make('action')->options(fn () => AuditEvent::query()->distinct()->pluck('action', 'action')),
                SelectFilter::make('auditable_type')->label('Objeto')->options(fn () => AuditEvent::query()->whereNotNull('auditable_type')->distinct()->pluck('auditable_type', 'auditable_type')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditEvents::route('/')];
    }
}
