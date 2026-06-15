<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        // ✅ SQL Server safe (no latest()+orderBy duplicates)
        $notifications = $request->user()
            ->notifications()
            ->paginate(20);   

        return view('notifications.index', compact('notifications'));
    }

    public function markAsRead(Request $request, string $id)
    {
        $notif = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notif->markAsRead();

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', 'All notifications marked as read.');
    }
}
