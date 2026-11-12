import { Head, Link, useForm } from '@inertiajs/react';
import { accept as acceptInvitation } from '@/actions/App/Http/Controllers/OrganizationInvitationController';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { notice as verificationPage } from '@/routes/verification';

type Invitation = {
    email: string;
    role: 'manager' | 'salesperson';
    team: string | null;
    organization: string;
    status: 'pending' | 'expired' | 'accepted' | 'revoked';
    token: string;
};

export default function InvitationShow({
    invitation,
    isAuthenticated,
    isVerified,
    emailMatches,
}: {
    invitation: Invitation;
    isAuthenticated: boolean;
    isVerified: boolean;
    emailMatches: boolean;
}) {
    const form = useForm<{ invitation: string }>({ invitation: '' });
    const statusLabel = {
        pending: 'Aguardando aceite',
        expired: 'Expirado',
        accepted: 'Já utilizado',
        revoked: 'Revogado',
    }[invitation.status];

    return (
        <>
            <Head title="Convite para organização" />
            <main className="mx-auto flex min-h-screen w-full max-w-xl items-center px-5 py-10">
                <section className="w-full rounded-2xl border border-border/70 bg-card p-7 shadow-sm">
                    <p className="text-sm font-medium text-primary">
                        Convite para participar
                    </p>
                    <h1 className="mt-2 text-2xl font-semibold">
                        {invitation.organization}
                    </h1>
                    <p className="mt-4 text-sm text-muted-foreground">
                        Você foi convidado como{' '}
                        <strong className="text-foreground">
                            {invitation.role === 'manager'
                                ? 'gestor'
                                : 'vendedor'}
                        </strong>
                        {invitation.team ? ` da equipe ${invitation.team}` : ''}
                        . O convite foi enviado para{' '}
                        <strong className="text-foreground">
                            {invitation.email}
                        </strong>
                        .
                    </p>

                    {invitation.status !== 'pending' ? (
                        <p className="mt-6 rounded-lg bg-muted p-4 text-sm">
                            Situação: {statusLabel}.
                        </p>
                    ) : isAuthenticated && !isVerified ? (
                        <div className="mt-6 space-y-3">
                            <p className="rounded-lg bg-muted p-4 text-sm">
                                Verifique o e-mail da sua conta para continuar.
                            </p>
                            <Link
                                href={verificationPage()}
                                className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                            >
                                Ir para verificação de e-mail
                            </Link>
                        </div>
                    ) : isAuthenticated && !emailMatches ? (
                        <p className="mt-6 rounded-lg bg-destructive/10 p-4 text-sm text-destructive">
                            Entre com a conta de {invitation.email} para aceitar
                            este convite.
                        </p>
                    ) : isAuthenticated ? (
                        <form
                            className="mt-6"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(
                                    acceptInvitation.url(invitation.token),
                                );
                            }}
                        >
                            <Button type="submit" disabled={form.processing}>
                                Aceitar convite
                            </Button>
                            {form.errors.invitation && (
                                <p className="mt-3 text-sm text-destructive">
                                    {form.errors.invitation}
                                </p>
                            )}
                        </form>
                    ) : (
                        <div className="mt-6 flex flex-wrap gap-3">
                            <Button asChild>
                                <Link href={login()}>Entrar para aceitar</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={register()}>Criar conta</Link>
                            </Button>
                        </div>
                    )}
                </section>
            </main>
        </>
    );
}
