<?php

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'status'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function hasActiveBranchAccess(): bool
    {
        return $this->is_platform_admin || $this->branch_id === null
            || Branch::withoutGlobalScopes()->whereKey($this->branch_id)
                ->where('company_id', $this->company_id)->where('status', 'active')->whereNull('deleted_at')->exists();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot(['company_id', 'assigned_by'])
            ->withTimestamps();
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    /**
     * Non-null only for a customer portal login (see CustomerPortalService::invite())
     * — the reverse of Customer::portalUser(). Policies for
     * customer-visible resources (Shipment, Invoice) must check this and
     * restrict to the customer's own records; company/branch matching
     * alone is not enough, since a portal user's branch_id is always null.
     */
    public function customerProfile(): HasOne
    {
        return $this->hasOne(Customer::class, 'portal_user_id');
    }

    /**
     * Reuses the existing `user.{userId}` private channel from
     * routes/channels.php for notification broadcasts, instead of Laravel's
     * default `App.Models.User.{id}` channel name.
     */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'user.'.$this->getKey();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        return $this->roles()
            ->where('roles.company_id', $this->company_id)
            ->whereHas('permissions', fn ($query) => $query->where('slug', $permission)->where('platform_only', false))
            ->exists();
    }

    /** @return list<string> */
    public function permissionSlugs(): array
    {
        if ($this->is_platform_admin) {
            return ['*'];
        }

        return $this->roles()
            ->where('roles.company_id', $this->company_id)
            ->with('permissions:id,slug')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
