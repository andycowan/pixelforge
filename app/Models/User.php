<?php

namespace App\Models;

use App\Exceptions\AccountHasGenerations;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = ['is_admin' => false];

    /** @return HasMany<Generation, $this> */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /** @return HasMany<CreditLedgerEntry, $this> */
    public function creditLedgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    public function assertCanDelete(): void
    {
        if ($this->exists && ($this->generations()->exists() || $this->creditLedgerEntries()->exists())) {
            throw new AccountHasGenerations;
        }
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return DB::transaction(function (): ?bool {
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->first();
            $locked?->assertCanDelete();

            return parent::delete();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
            /* @chisel-2fa */
            'two_factor_confirmed_at' => 'datetime',
            /* @end-chisel-2fa */
        ];
    }
}
