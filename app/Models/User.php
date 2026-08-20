<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * DRAXIS employee record. Portal login uses username + password + Sanctum.
 *
 * @property int $id
 * @property string|null $username
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $name
 * @property string|null $email
 * @property string|null $password
 * @property string|null $phone
 * @property string|null $shift
 * @property string|null $department
 * @property string|null $position
 * @property string|null $company
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $fillable = [
        'username',
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'shift',
        'department',
        'position',
        'company',
        'birth_date',
        'joined_at',
        'salary',
        'grade',
        'status',
        'address',
        'city',
        'state',
        'country',
        'gender',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'salary',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'birth_date' => 'date',
            'joined_at' => 'date',
            'salary' => 'decimal:2',
        ];
    }

    public function hasPortalAccess(): bool
    {
        return filled($this->username) && filled($this->getAuthPassword());
    }
}
