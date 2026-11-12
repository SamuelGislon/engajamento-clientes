import { Head, Link, useForm } from '@inertiajs/react';
import { UsersRound } from 'lucide-react';
import { store as storeTeam } from '@/actions/App/Http/Controllers/TeamController';
import { show as showTeam } from '@/actions/App/Http/Controllers/TeamController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Team = {
    id: number;
    name: string;
    description: string | null;
    status: 'active' | 'inactive';
    members_count: number;
    users: {
        id: number;
        name: string;
        email: string;
        pivot: { role: string };
    }[];
};

export default function TeamsIndex({
    teams,
    canManage,
}: {
    teams: Team[];
    canManage: boolean;
}) {
    const form = useForm({ name: '', description: '' });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(storeTeam.url(), { onSuccess: () => form.reset() });
    }

    return (
        <>
            <Head title="Equipes comerciais" />
            <main className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-5 py-8 md:px-8">
                <header>
                    <p className="flex items-center gap-2 text-sm font-medium text-primary">
                        <UsersRound className="size-4" /> Gestão comercial
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        Equipes
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Organize gestores e vendedores por carteira de atuação.
                    </p>
                </header>

                {canManage && (
                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm">
                        <h2 className="font-semibold">Criar equipe</h2>
                        <form
                            onSubmit={submit}
                            className="mt-4 grid gap-4 md:grid-cols-2"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="team-name">Nome</Label>
                                <Input
                                    id="team-name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    maxLength={120}
                                    required
                                />
                                {form.errors.name && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.name}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="team-description">
                                    Descrição{' '}
                                    <span className="text-muted-foreground">
                                        (opcional)
                                    </span>
                                </Label>
                                <Input
                                    id="team-description"
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={2000}
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    Criar equipe
                                </Button>
                            </div>
                        </form>
                    </section>
                )}

                {teams.length === 0 ? (
                    <section className="rounded-2xl border border-dashed border-border p-10 text-center">
                        <h2 className="font-semibold">
                            Nenhuma equipe no seu escopo
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Quando uma equipe for criada e você fizer parte
                            dela, ela aparecerá aqui.
                        </p>
                    </section>
                ) : (
                    <section className="grid gap-4 md:grid-cols-2">
                        {teams.map((team) => (
                            <article
                                key={team.id}
                                className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <h2 className="font-semibold">
                                            <Link
                                                href={showTeam.url(team.id)}
                                                className="hover:text-primary"
                                            >
                                                {team.name}
                                            </Link>
                                        </h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {team.description ||
                                                'Sem descrição.'}
                                        </p>
                                    </div>
                                    <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200">
                                        {team.status === 'active'
                                            ? 'Ativa'
                                            : 'Inativa'}
                                    </span>
                                </div>
                                <p className="mt-5 text-sm text-muted-foreground">
                                    {team.members_count} integrantes
                                </p>
                                {team.users.length > 0 && (
                                    <ul className="mt-3 space-y-2 border-t border-border/60 pt-3">
                                        {team.users.map((user) => (
                                            <li
                                                key={user.id}
                                                className="flex items-center justify-between gap-4 text-sm"
                                            >
                                                <span className="truncate font-medium">
                                                    {user.name}
                                                </span>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {user.pivot.role ===
                                                    'manager'
                                                        ? 'Gestor'
                                                        : 'Vendedor'}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </article>
                        ))}
                    </section>
                )}
            </main>
        </>
    );
}
