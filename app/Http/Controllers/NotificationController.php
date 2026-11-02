<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    public function markAsRead(Request $request, string $id)
    {
        $notif = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notif->markAsRead();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok'      => true,
                'read_at' => optional($notif->read_at)->timezone('Asia/Kolkata')->format('d-m-Y h:i A'),
            ]);
        }

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', 'All notifications marked as read.');
    }

    public function destroy(Request $request, string $id)
    {
        $notif = $this->findNotification($request, $id);
        abort_unless($notif, 404);
        $notif->delete();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('notifications.index')->with('status', 'Notification removed.');
    }

    public function show(Request $request, string $id)
    {
        $notif = $this->findNotification($request, $id);
        abort_unless($notif, 404);
        $notif->markAsRead();

        $data = is_array($notif->data ?? null) ? $notif->data : [];
        if (! is_array($data)) {
            $data = json_decode((string) $notif->data, true) ?: [];
        }

        $reportId = (int) ($data['report_id'] ?? 0);
        $displayCode = trim((string) ($data['display_code'] ?? ''));
        $report = $reportId > 0 ? Report::query()->find($reportId) : null;
        if ($displayCode === '' && $report) {
            $displayCode = $report->displayCode();
        }
        if ($displayCode !== '') {
            $data['display_code'] = $displayCode;
        }
        if (! empty($data['message'])) {
            $data['message'] = Report::rewriteIdsInText(
                (string) $data['message'],
                $report,
                $displayCode !== '' ? $displayCode : null,
                $reportId > 0 ? $reportId : null
            );
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'id' => $notif->id,
                'data' => $data,
                'created_at' => optional($notif->created_at)->timezone('Asia/Kolkata')->format('d-m-Y h:i A'),
                'read_at' => optional($notif->read_at)->timezone('Asia/Kolkata')->format('d-m-Y h:i A'),
            ]);
        }

        return redirect()->route('notifications.index');
    }

    public function destroyAll(Request $request)
    {
        $request->user()->notifications()->delete();

        return redirect()->route('notifications.index')->with('status', 'All notifications removed.');
    }

    private function findNotification(Request $request, string $id)
    {
        $notif = $request->user()->notifications()->where('id', $id)->first();
        if ($notif) {
            return $notif;
        }

        return $request->user()->notifications->first(
            fn ($notification) => strcasecmp((string) $notification->id, $id) === 0
        );
    }

    public static function markSeenForReport($user, int $reportId): void
    {
        if (! $user || $reportId < 1) {
            return;
        }

        $user->unreadNotifications
            ->filter(function ($notification) use ($reportId) {
                $data = is_array($notification->data ?? null) ? $notification->data : [];
                if ((int) ($data['report_id'] ?? 0) === $reportId) {
                    return true;
                }

                $url = (string) ($data['url'] ?? '');

                return str_contains($url, '/reports/' . $reportId);
            })
            ->each(function ($notification) {
                $notification->markAsRead();
            });
    }
}
