<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEndpoint;

final class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('webhooks.view');
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->company_id === $endpoint->company_id && $user->hasPermission('webhooks.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('webhooks.manage');
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->company_id === $endpoint->company_id && $user->hasPermission('webhooks.manage');
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->update($user, $endpoint);
    }
}
