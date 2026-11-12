import { Head, router, useForm } from '@inertiajs/react';
import { MailPlus, UsersRound } from 'lucide-react';
import {
    store as storeInvitation,
    resend as resendInvitation,
    revoke as revokeInvitation,
} from '@/actions/App/Http/Controllers/OrganizationInvitationController';
import { update as updateMember } from '@/actions/App/Http/Controllers/OrganizationMemberController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Team = { id: number; name: string };
type Member = {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'admin' | 'manager' | 'salesperson';
    is_active: boolean;
    teams: Team[];
};
type Invitation = {
    id: number;
    email: string;
    role: 'manager' | 'salesperson';
    team: string | null;
    status: 'pending' | 'expired' | 'accepted' | 'revoked';
    expires_at: string;
    created_at: string;
};

export default function OrganizationUsersIndex({
    members,
    invitations,
    teams,
    currentRole,
}: {
    members: { data: Member[] };
    invitations: { data: Invitation[] };
    teams: Team[];
    currentRole: 'owner' | 'admin';
}) {
    const form = useForm({ email: '', role: 'salesperson', team_id: '' });

    return (
        <>
            <Head title="Usuários e convites" />
            <main className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-5 py-8 md:px-8">
                <header>
                    <p className="flex items-center gap-2 text-sm font-medium text-primary">
                        <UsersRound className="size-4" /> Gestão comercial
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        Usuários e convites
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Convide colaboradores e organize seus papéis e equipes.
                    </p>
                </header>

                <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <MailPlus className="size-4 text-primary" /> Convidar
                        colaborador
                    </h2>
                    <form
                        className="mt-4 grid gap-4 md:grid-cols-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(storeInvitation.url(), {
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <div className="space-y-2">
                            <Label htmlFor="invite-email">E-mail</Label>
                            <Input
                                id="invite-email"
                                type="email"
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                required
                            />
                            {form.errors.email && (
                                <p className="text-sm text-destructive">
                                    {form.errors.email}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="invite-role">Papel</Label>
                            <select
                                id="invite-role"
                                value={form.data.role}
                                onChange={(event) =>
                                    form.setData(
                                        'role',
                                        event.target.value as Member['role'],
                                    )
                                }
                                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="salesperson">Vendedor</option>
                                <option value="manager">Gestor</option>
                            </select>
                            {form.errors.role && (
                                <p className="text-sm text-destructive">
                                    {form.errors.role}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="invite-team">Equipe</Label>
                            <select
                                id="invite-team"
                                value={form.data.team_id}
                                onChange={(event) =>
                                    form.setData('team_id', event.target.value)
                                }
                                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">Sem equipe inicial</option>
                                {teams.map((team) => (
                                    <option key={team.id} value={team.id}>
                                        {team.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.team_id && (
                                <p className="text-sm text-destructive">
                                    {form.errors.team_id}
                                </p>
                            )}
                        </div>
                        <div className="md:col-span-3">
                            <Button type="submit" disabled={form.processing}>
                                Enviar convite
                            </Button>
                        </div>
                    </form>
                </section>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="border-b border-border/70 px-5 py-4">
                        <h2 className="font-semibold">Integrantes</h2>
                    </div>
                    <div className="divide-y divide-border/60">
                        {members.data.map((member) => (
                            <MemberRow
                                key={member.id}
                                member={member}
                                teams={teams}
                                currentRole={currentRole}
                            />
                        ))}
                        {members.data.length === 0 && (
                            <p className="p-5 text-sm text-muted-foreground">
                                Nenhum integrante encontrado.
                            </p>
                        )}
                    </div>
                </section>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="border-b border-border/70 px-5 py-4">
                        <h2 className="font-semibold">Convites recentes</h2>
                    </div>
                    <div className="divide-y divide-border/60">
                        {invitations.data.map((invitation) => (
                            <article
                                key={invitation.id}
                                className="flex flex-wrap items-center justify-between gap-4 p-4"
                            >
                                <div>
                                    <p className="font-medium">
                                        {invitation.email}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {invitation.role === 'manager'
                                            ? 'Gestor'
                                            : 'Vendedor'}
                                        {invitation.team
                                            ? ` · ${invitation.team}`
                                            : ''}
                                        {' · '}Expira em{' '}
                                        {new Intl.DateTimeFormat('pt-BR', {
                                            dateStyle: 'medium',
                                        }).format(
                                            new Date(invitation.expires_at),
                                        )}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="rounded-full bg-muted px-2.5 py-1 text-xs">
                                        {statusLabel(invitation.status)}
                                    </span>
                                    {invitation.status === 'pending' ||
                                    invitation.status === 'expired' ? (
                                        <>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        resendInvitation.url(
                                                            invitation.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                Reenviar
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        revokeInvitation.url(
                                                            invitation.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                Revogar
                                            </Button>
                                        </>
                                    ) : null}
                                </div>
                            </article>
                        ))}
                        {invitations.data.length === 0 && (
                            <p className="p-5 text-sm text-muted-foreground">
                                Ainda não há convites.
                            </p>
                        )}
                    </div>
                </section>
            </main>
        </>
    );
}

function MemberRow({
    member,
    teams,
    currentRole,
}: {
    member: Member;
    teams: Team[];
    currentRole: 'owner' | 'admin';
}) {
    const form = useForm({
        role: member.role,
        is_active: member.is_active,
        team_ids: member.teams.map((team) => team.id),
    });
    const canEditMember =
        currentRole === 'owner' ||
        (currentRole === 'admin' &&
            ['manager', 'salesperson'].includes(member.role));
    const availableRoles =
        currentRole === 'owner'
            ? ['owner', 'admin', 'manager', 'salesperson']
            : ['manager', 'salesperson'];
    const showTeamSelection = ['manager', 'salesperson'].includes(
        form.data.role,
    );

    function toggleTeam(teamId: number, checked: boolean) {
        form.setData(
            'team_ids',
            checked
                ? [...form.data.team_ids, teamId]
                : form.data.team_ids.filter((id) => id !== teamId),
        );
    }

    return (
        <form
            className="grid gap-4 p-4 lg:grid-cols-[minmax(180px,1fr)_180px_180px_minmax(220px,1.2fr)_auto] lg:items-center"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(updateMember.url(member.id));
            }}
        >
            <div className="min-w-0">
                <p className="truncate font-medium">{member.name}</p>
                <p className="truncate text-sm text-muted-foreground">
                    {member.email}
                </p>
            </div>
            <select
                aria-label={`Papel de ${member.name}`}
                value={form.data.role}
                onChange={(event) =>
                    form.setData('role', event.target.value as Member['role'])
                }
                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                disabled={!canEditMember}
            >
                {availableRoles.map((role) => (
                    <option key={role} value={role}>
                        {roleLabel(role)}
                    </option>
                ))}
            </select>
            <select
                aria-label={`Situação de ${member.name}`}
                value={form.data.is_active ? 'active' : 'inactive'}
                onChange={(event) =>
                    form.setData('is_active', event.target.value === 'active')
                }
                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                disabled={!canEditMember}
            >
                <option value="active">Ativo</option>
                <option value="inactive">Inativo</option>
            </select>
            <div className="flex flex-wrap gap-x-3 gap-y-2">
                {showTeamSelection &&
                    teams.map((team) => (
                        <label
                            key={team.id}
                            className="inline-flex items-center gap-1.5 text-xs"
                        >
                            <input
                                type="checkbox"
                                checked={form.data.team_ids.includes(team.id)}
                                onChange={(event) =>
                                    toggleTeam(team.id, event.target.checked)
                                }
                            />
                            {team.name}
                        </label>
                    ))}
            </div>
            <div className="flex flex-col items-start gap-1">
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={form.processing}
                >
                    Salvar
                </Button>
                {(form.errors.role ||
                    form.errors.team_ids ||
                    form.errors.is_active) && (
                    <p className="max-w-56 text-xs text-destructive">
                        {form.errors.role ||
                            form.errors.team_ids ||
                            form.errors.is_active}
                    </p>
                )}
            </div>
        </form>
    );
}

function roleLabel(role: string): string {
    return (
        {
            owner: 'Proprietário',
            admin: 'Administrador',
            manager: 'Gestor',
            salesperson: 'Vendedor',
        }[role] ?? role
    );
}

function statusLabel(status: Invitation['status']): string {
    return {
        pending: 'Pendente',
        expired: 'Expirado',
        accepted: 'Aceito',
        revoked: 'Revogado',
    }[status];
}
