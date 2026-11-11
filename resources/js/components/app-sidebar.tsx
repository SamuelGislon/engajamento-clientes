import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Building2,
    LayoutGrid,
    ListTodo,
    Route,
    UsersRound,
} from 'lucide-react';
import { index as companiesIndex } from '@/actions/App/Http/Controllers/CompanyController';
import { index as contactsIndex } from '@/actions/App/Http/Controllers/ContactController';
import OrganizationSwitchController from '@/actions/App/Http/Controllers/OrganizationSwitchController';
import { index as tasksIndex } from '@/actions/App/Http/Controllers/TaskController';
import { index as journeysIndex } from '@/actions/App/Http/Controllers/JourneyTemplateController';
import { index as notificationsIndex } from '@/actions/App/Http/Controllers/NotificationController';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Empresas',
        href: companiesIndex.url(),
        icon: Building2,
    },
    {
        title: 'Contatos',
        href: contactsIndex.url(),
        icon: UsersRound,
    },
    { title: 'Atividades', href: tasksIndex.url(), icon: ListTodo },
    { title: 'Jornadas', href: journeysIndex.url(), icon: Route },
    { title: 'Notificações', href: notificationsIndex.url(), icon: Bell },
];

export function AppSidebar() {
    const { activeOrganization, organizations } = usePage().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {organizations.length > 1 && (
                    <div className="px-3 pb-3 group-data-[collapsible=icon]:hidden">
                        <label
                            htmlFor="active-organization"
                            className="mb-1.5 block text-[11px] font-medium tracking-[0.12em] text-sidebar-foreground/55 uppercase"
                        >
                            Workspace
                        </label>
                        <select
                            id="active-organization"
                            value={activeOrganization?.id ?? ''}
                            onChange={(event) =>
                                router.post(
                                    OrganizationSwitchController.url(),
                                    {
                                        organization_id: Number(
                                            event.target.value,
                                        ),
                                    },
                                )
                            }
                            className="h-10 w-full rounded-md border border-sidebar-border bg-sidebar px-3 text-sm font-medium text-sidebar-foreground outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                        >
                            {organizations.map((organization) => (
                                <option
                                    key={organization.id}
                                    value={organization.id}
                                >
                                    {organization.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
