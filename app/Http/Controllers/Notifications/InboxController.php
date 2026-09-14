<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class InboxController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Notifications/Index', [
            'notifications' => $request->user()->notifications()->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))->latest()->paginate(20)->withQueryString(),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
            'unreadOnly' => $request->boolean('unread'),
        ]);
    }

    public function update(Request $request, string $notification)
    {
        $data = $request->validate(['read' => ['required', 'boolean']]);
        $owned = $request->user()->notifications()->findOrFail($notification);
        $owned->forceFill(['read_at' => $data['read'] ? now() : null])->save();

        return back();
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
