import { Head, router } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { open } from '@/actions/App/Http/Controllers/NotificationController';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';

type NotificationItem = {
    id: string;
    data: { title: string; message: string; event: string };
    read_at: string | null;
    created_at: string;
};

type Page<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Notificações', href: '/notifications' },
];

export default function NotificationsIndex({
    notifications,
    unreadCount,
}: {
    notifications: Page<NotificationItem>;
    unreadCount: number;
}) {
    return (
        <>
            <Head title="Notificações" />
            <main className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-5 py-8 md:px-8">
                <header>
                    <p className="flex items-center gap-2 text-sm font-medium text-primary">
                        <Bell className="size-4" /> Central de avisos
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        Notificações
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {unreadCount} não lidas nesta organização.
                    </p>
                </header>
                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    {notifications.data.length === 0 ? (
                        <div className="px-6 py-12 text-center text-sm text-muted-foreground">
                            Você está em dia. Os avisos de tarefas e jornadas
                            aparecerão aqui.
                        </div>
                    ) : (
                        <div className="divide-y divide-border/60">
                            {notifications.data.map((notification) => (
                                <button
                                    key={notification.id}
                                    type="button"
                                    onClick={() =>
                                        router.post(open.url(notification.id))
                                    }
                                    className="flex w-full items-start gap-4 px-5 py-5 text-left transition-colors hover:bg-muted/40"
                                >
                                    <span
                                        className={`mt-1 size-2 shrink-0 rounded-full ${notification.read_at ? 'bg-muted' : 'bg-primary'}`}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-medium">
                                                {notification.data.title}
                                            </span>
                                            <time className="text-xs text-muted-foreground">
                                                {new Intl.DateTimeFormat(
                                                    'pt-BR',
                                                    {
                                                        dateStyle: 'medium',
                                                        timeStyle: 'short',
                                                    },
                                                ).format(
                                                    new Date(
                                                        notification.created_at,
                                                    ),
                                                )}
                                            </time>
                                        </span>
                                        <span className="mt-1 block text-sm text-muted-foreground">
                                            {notification.data.message}
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}
                    {notifications.links.length > 3 && (
                        <nav
                            aria-label="Paginação de notificações"
                            className="flex flex-wrap gap-2 border-t border-border/70 p-4"
                        >
                            {notifications.links.map((link, index) => (
                                <Button
                                    key={`${link.label}-${index}`}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url && router.get(link.url)
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </nav>
                    )}
                </section>
                {unreadCount > 0 && (
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <CheckCheck className="size-4" /> Abrir um aviso marca
                        como lido e verifica sua autorização.
                    </p>
                )}
            </main>
        </>
    );
}

NotificationsIndex.layout = { breadcrumbs };
