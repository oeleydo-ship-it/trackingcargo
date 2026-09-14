<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MasterMode;
use App\Models\Master;
use App\Models\User;

final class MasterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('flights.view') || $user->hasPermission('vessels.view');
    }

    public function view(User $user, Master $master): bool
    {
        return $user->company_id === $master->company_id && $user->hasPermission($this->viewPermission($master));
    }

    public function create(User $user, ?string $mode = null): bool
    {
        return match ($mode) {
            MasterMode::Air->value => $user->hasPermission('flights.manage'),
            MasterMode::Sea->value => $user->hasPermission('vessels.manage'),
            default => $user->hasPermission('flights.manage') || $user->hasPermission('vessels.manage'),
        };
    }

    public function update(User $user, Master $master): bool
    {
        return $user->company_id === $master->company_id && $user->hasPermission($this->managePermission($master));
    }

    public function transition(User $user, Master $master): bool
    {
        return $this->update($user, $master);
    }

    private function viewPermission(Master $master): string
    {
        return $master->mode === MasterMode::Air ? 'flights.view' : 'vessels.view';
    }

    private function managePermission(Master $master): string
    {
        return $master->mode === MasterMode::Air ? 'flights.manage' : 'vessels.manage';
    }
}
