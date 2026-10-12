<?php

namespace App\Filament\Resources\Agents;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\Agent;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Empresários/agências: contato profissional. Não registrar dados pessoais irrelevantes. */
class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static ?string $slug = 'empresarios';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Jogadores';

    protected static ?string $modelLabel = 'empresário';

    protected static ?string $pluralModelLabel = 'Empresários';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function formFields(): array
    {
        return [
            TextInput::make('name')->label('Nome')->required()->maxLength(150),
            TextInput::make('agency')->label('Agência')->maxLength(150),
            TextInput::make('phone')->label('Telefone')->tel()->maxLength(40),
            TextInput::make('whatsapp')->label('WhatsApp')->tel()->maxLength(40),
            TextInput::make('email')->label('E-mail')->email()->maxLength(150),
            TextInput::make('license_number')->label('Licença de agente (FIFA/CBF)')->maxLength(60),
            TextInput::make('city')->label('Cidade/UF')->maxLength(100),
            Textarea::make('notes')->label('Observações (relacionamento, histórico de contato)')->rows(3)->columnSpanFull(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components(static::formFields());
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('players'))
            ->columns([
                TextColumn::make('name')->label('Empresário')->weight('bold')->searchable(['name', 'agency'])
                    ->description(fn (Agent $a) => $a->agency),
                TextColumn::make('phone')->label('Telefone')->placeholder('—')->copyable(),
                TextColumn::make('whatsapp')->label('WhatsApp')->placeholder('—')
                    ->url(fn (Agent $a) => $a->whatsappUrl(), true)->color('primary'),
                TextColumn::make('email')->label('E-mail')->placeholder('—')->copyable(),
                TextColumn::make('players_count')->label('Atletas')->alignEnd()
                    ->url(fn (Agent $a) => PlayerResource::getUrl('index', ['filters' => ['agent_id' => ['value' => $a->id]]])),
                TextColumn::make('license_number')->label('Licença')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make()->iconButton()])
            ->emptyStateHeading('Nenhum empresário cadastrado');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAgents::route('/')];
    }
}
