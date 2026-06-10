<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Request> $assignedRequests
 */
#[Fillable(['name', 'email', 'password', 'current_team_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /**
     * HasRoles is spatie/laravel-permission's entry point: it gives the user
     * `assignRole()`, `hasRole()`, and `can()` checks backed by the roles /
     * permissions tables. These spatie roles power *helpdesk* authorization
     * (Administrator vs. Help Desk Staff) — the starter kit's TeamRole enum
     * continues to govern team-settings screens independently.
     *
     * Trait collision lesson: both HasTeams (starter kit) and HasRoles
     * (spatie, with its own optional teams feature) define a `teams()`
     * method. PHP refuses to guess, so we resolve it explicitly below:
     * `insteadof` keeps the starter's relation as `teams()`, and spatie's
     * is aliased to `permissionTeams()` (unused — spatie teams are
     * disabled in config/permission.php, so it returns no rows).
     *
     * @use HasFactory<UserFactory>
     */
    use HasFactory, HasRoles, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable {
        HasTeams::teams insteadof HasRoles;
        HasRoles::teams as permissionTeams;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Get the helpdesk requests assigned to this staff member.
     *
     * `Request` here is App\Models\Request (same namespace) — the column is
     * `assigned_to` rather than `user_id`, so it's passed explicitly.
     *
     * @return HasMany<Request, $this>
     */
    public function assignedRequests(): HasMany
    {
        return $this->hasMany(Request::class, 'assigned_to');
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
