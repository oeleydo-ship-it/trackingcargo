<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Manifest;
use App\Models\User;

final class ManifestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('manifests.view');
    }

    public function view(User $user, Manifest $manifest): bool
    {
        return $user->company_id === $manifest->company_id && $user->hasPermission('manifests.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('manifests.manage');
    }
}
