<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\AcceptInvitationRequest;
use App\Models\User;
use App\Services\Identity\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class InvitationController extends Controller
{
    public function show(User $user): Response
    {
        abort_unless($user->status === UserStatus::Invited, HttpResponse::HTTP_GONE, 'This invitation is no longer valid.');

        return Inertia::render('Auth/AcceptInvitation', [
            'user' => ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function store(AcceptInvitationRequest $request, User $user, UserInvitationService $invitations): RedirectResponse
    {
        abort_unless($user->status === UserStatus::Invited, HttpResponse::HTTP_GONE, 'This invitation is no longer valid.');

        $invitations->accept($user, (string) $request->validated('password'));

        return to_route('login')->with('success', 'Your account is active. Sign in to continue.');
    }
}
