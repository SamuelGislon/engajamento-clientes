import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save, UsersRound } from 'lucide-react';
import {
    index as teamsIndex,
    syncMembers,
    update as updateTeam,
} from '@/actions/App/Http/Controllers/TeamController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Member = {
    id: number;
    name: string;
    email: string;
    pivot: { role: string; is_active: boolean };
};

type OrganizationMember = {
    id: number;
    name: string;
    email: string;
    role: 'manager' | 'salesperson';
};

type Team = {
    id: number;
    name: string;
    description: string | null;
    status: 'active' | 'inactive';
    companies_count: number;
    users: Member[];
};

export default function TeamShow({
    team,
    canManage,
    organizationMembers,
}: {
    team: Team;
    canManage: boolean;
    organizationMembers: OrganizationMember[];
}) {
    const details = useForm({
        name: team.name,
        description: team.description ?? '',
        status: team.status,
    });
    const membership = useForm({
        user_ids: team.users.map((user) => user.id),
    });

    function toggleMember(id: number, checked: boolean) {
        membership.setData(
            'user_ids',
            checked
                ? [...membership.data.user_ids, id]
                : membership.data.user_ids.filter((userId) => userId !== id),
        );
    }

    return (
        <>
            <Head title={team.name} />
            <main className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-5 py-8 md:px-8">
                <Link
                    href={teamsIndex.url()}
                    className="inline-flex w-fit items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Voltar para equipes
                </Link>
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="flex items-center gap-2 text-sm font-medium text-primary">
                            <UsersRound className="size-4" /> Gestão comercial
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            {team.name}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {team.description || 'Equipe sem descrição.'}
                        </p>
                    </div>
                    <span className="rounded-full bg-muted px-3 py-1 text-sm">
                        {team.status === 'active' ? 'Ativa' : 'Inativa'}
                    </span>
                </header>

                <section className="grid gap-4 sm:grid-cols-2">
                    <article className="rounded-2xl border border-border/70 bg-card p-5">
                        <p className="text-sm text-muted-foreground">
                            Integrantes ativos
                        </p>
                        <p className="mt-2 text-3xl font-semibold">
                            {team.users.length}
                        </p>
                    </article>
                    <article className="rounded-2xl border border-border/70 bg-card p-5">
                        <p className="text-sm text-muted-foreground">
                            Empresas na carteira
                        </p>
                        <p className="mt-2 text-3xl font-semibold">
                            {team.companies_count}
                        </p>
                    </article>
                </section>

                {canManage && (
                    <section className="rounded-2xl border border-border/70 bg-card p-5">
                        <h2 className="font-semibold">
                            Configurações da equipe
                        </h2>
                        <form
                            className="mt-4 grid gap-4 md:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                details.patch(updateTeam.url(team.id));
                            }}
                        >
                            <div className="space-y-2">
                                <Label htmlFor="team-name">Nome</Label>
                                <Input
                                    id="team-name"
                                    value={details.data.name}
                                    onChange={(event) =>
                                        details.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                {details.errors.name && (
                                    <p className="text-sm text-destructive">
                                        {details.errors.name}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="team-description">
                                    Descrição
                                </Label>
                                <Input
                                    id="team-description"
                                    value={details.data.description}
                                    onChange={(event) =>
                                        details.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="team-status">Situação</Label>
                                <select
                                    id="team-status"
                                    value={details.data.status}
                                    onChange={(event) =>
                                        details.setData(
                                            'status',
                                            event.target
                                                .value as Team['status'],
                                        )
                                    }
                                    className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="active">Ativa</option>
                                    <option value="inactive">Inativa</option>
                                </select>
                                {details.errors.status && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {details.errors.status}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-end">
                                <Button
                                    type="submit"
                                    disabled={details.processing}
                                >
                                    <Save className="mr-2 size-4" /> Salvar
                                    equipe
                                </Button>
                            </div>
                        </form>
                    </section>
                )}

                <section className="rounded-2xl border border-border/70 bg-card p-5">
                    <div>
                        <h2 className="font-semibold">Gestores e vendedores</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            A equipe organiza o escopo do gestor. Cada vendedor
                            continua vendo apenas a própria carteira.
                        </p>
                    </div>
                    {canManage && team.status === 'active' && (
                        <form
                            className="mt-5 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                membership.put(syncMembers.url(team.id));
                            }}
                        >
                            {membership.errors.user_ids && (
                                <p className="text-sm text-destructive">
                                    {membership.errors.user_ids}
                                </p>
                            )}
                            <div className="grid gap-2 md:grid-cols-2">
                                {organizationMembers.map((member) => (
                                    <label
                                        key={member.id}
                                        className="flex items-center gap-3 rounded-lg border border-border/70 p-3 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={membership.data.user_ids.includes(
                                                member.id,
                                            )}
                                            onChange={(event) =>
                                                toggleMember(
                                                    member.id,
                                                    event.target.checked,
                                                )
                                            }
                                            className="size-4 rounded border-input accent-primary"
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">
                                                {member.name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {member.email}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {member.role === 'manager'
                                                ? 'Gestor'
                                                : 'Vendedor'}
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <Button
                                type="submit"
                                disabled={membership.processing}
                            >
                                Salvar integrantes
                            </Button>
                        </form>
                    )}
                    {!canManage && team.users.length > 0 && (
                        <ul className="mt-5 divide-y divide-border/60">
                            {team.users.map((user) => (
                                <li
                                    key={user.id}
                                    className="flex items-center justify-between gap-4 py-3 text-sm"
                                >
                                    <span className="font-medium">
                                        {user.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {user.pivot.role === 'manager'
                                            ? 'Gestor'
                                            : 'Vendedor'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </main>
        </>
    );
}
