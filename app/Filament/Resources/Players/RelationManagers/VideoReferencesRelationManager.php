<?php

namespace App\Filament\Resources\Players\RelationManagers;

use App\Filament\Concerns\RelationCountBadge;
use App\Filament\Support\VideoFields;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VideoReferencesRelationManager extends RelationManager
{
    use RelationCountBadge;

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedFilm;

    protected static string $relationship = 'videoReferences';

    protected static ?string $title = 'Vídeos';

    protected static ?string $modelLabel = 'referência de vídeo';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components(VideoFields::make());
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('match_label')->label('Jogo')->placeholder('—'),
                TextColumn::make('tag')->label('Ação/tag')->placeholder('—'),
                TextColumn::make('polarity')->label('Leitura')->badge()
                    ->color(fn ($state) => ['positivo' => 'success', 'negativo' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('starts_at')->label('Início')->placeholder('—'),
                TextColumn::make('ends_at')->label('Fim')->placeholder('—'),
                TextColumn::make('url')->label('Link')->url(fn ($record) => $record->url, true)->limit(30)->color('primary'),
                TextColumn::make('note')->label('Nota')->limit(50)->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()->label('Adicionar vídeo')->mutateDataUsing(fn (array $d) => $d + ['author_id' => auth()->id()])])
            ->recordActions([EditAction::make()->iconButton(), DeleteAction::make()->iconButton()])
            ->emptyStateHeading('Nenhum vídeo referenciado')
            ->emptyStateDescription('Registre link + minuto. Inclua ações negativas: highlights isolados não bastam.');
    }
}
