import { Head, Link, router } from '@inertiajs/react';
import { useEffect } from 'react';
import AppLayout from '../../layouts/AppLayout';

type Notice = { id: string; data: { message?: string }; read_at: string | null; created_at: string };
type Props = { notifications: { data: Notice[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null }; unreadCount: number; unreadOnly: boolean };

export default function Inbox({ notifications, unreadCount, unreadOnly }: Props) {
    useEffect(() => { const timer = window.setInterval(() => { if (!document.hidden) router.reload({ only: ['notifications', 'unreadCount'] }); }, 30000); return () => window.clearInterval(timer); }, []);
    return <AppLayout title="Notifications"><Head title="Notifications" />
        <div className="mb-5 flex flex-wrap items-center gap-4"><p>{unreadCount} unread</p><Link className="text-cyan-300" href={unreadOnly ? '/notifications' : '/notifications?unread=1'}>{unreadOnly ? 'Show all' : 'Unread only'}</Link><button disabled={unreadCount === 0} onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true })} className="text-cyan-300 disabled:opacity-40">Mark all as read</button></div>
        <div className="space-y-3">{notifications.data.map((notice) => <article key={notice.id} className={`rounded-xl border p-5 ${notice.read_at ? 'border-white/10' : 'border-cyan-400/40 bg-cyan-400/5'}`}><p>{notice.data.message ?? 'Account notification'}</p><p className="mt-2 text-xs text-slate-400">{new Date(notice.created_at).toLocaleString()}</p><button className="mt-3 text-sm text-cyan-300" onClick={() => router.patch(`/notifications/${notice.id}`, { read: !notice.read_at }, { preserveScroll: true })}>{notice.read_at ? 'Mark unread' : 'Mark read'}</button></article>)}</div>
        {notifications.data.length === 0 && <p className="rounded-xl border border-white/10 p-8 text-slate-400">{unreadOnly ? 'You are all caught up.' : 'No notifications yet.'}</p>}
        <nav aria-label="Notification pages" className="mt-5 flex gap-5">{notifications.prev_page_url && <Link href={notifications.prev_page_url}>Previous</Link>}<span>Page {notifications.current_page} of {notifications.last_page}</span>{notifications.next_page_url && <Link href={notifications.next_page_url}>Next</Link>}</nav>
    </AppLayout>;
}
