<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/** Acesso fechado por convite: o administrador cria a conta e entrega a senha temporária por canal seguro. */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'usuarios';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'Usuários';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nome')->required(),
            TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true),
            Select::make('role')->label('Perfil de acesso')->options(UserRole::class)->required()->default('analista')
                ->helperText('Perfil de acesso ao sistema — não confere poder institucional de aprovação.'),
            Toggle::make('can_view_financial')->label('Pode ver valores financeiros')->helperText('Admin e coordenador sempre veem.'),
            Toggle::make('is_active')->label('Ativo')->default(true),
            TextInput::make('password')->label('Senha temporária')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')->minLength(12)
                ->dehydrated(fn ($state) => filled($state))->helperText('Mínimo 12 caracteres. Em branco na edição = mantém a atual.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable(),
                TextColumn::make('email')->label('E-mail'),
                TextColumn::make('role')->label('Perfil')->badge(),
                IconColumn::make('can_view_financial')->label('Financeiro')->boolean(),
                IconColumn::make('mfa')->label('MFA')->boolean()->state(fn (User $u) => filled($u->app_authentication_secret)),
                IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                Action::make('resetPassword')->label('Gerar nova senha')->icon(Heroicon::OutlinedKey)->iconButton()->requiresConfirmation()
                    ->action(function (User $u) {
                        $pwd = Str::password(16);
                        $u->update(['password' => $pwd]);
                        Notification::make()->success()->persistent()->title('Nova senha temporária')
                            ->body("Entregue por canal seguro e peça a troca no perfil: {$pwd}")->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUsers::route('/')];
    }
}
