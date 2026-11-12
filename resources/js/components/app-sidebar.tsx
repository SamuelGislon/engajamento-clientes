import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    ArrowRightLeft,
    Building2,
    ChartNoAxesCombined,
    CircleDollarSign,
    Gauge,
    LayoutGrid,
    ListTodo,
    Route,
    Settings2,
    UsersRound,
} from 'lucide-react';
import { index as companiesIndex } from '@/actions/App/Http/Controllers/CompanyController';
import { index as contactsIndex } from '@/actions/App/Http/Controllers/ContactController';
import OrganizationSwitchController from '@/actions/App/Http/Controllers/OrganizationSwitchController';
import { index as tasksIndex } from '@/actions/App/Http/Controllers/TaskController';
import { index as journeysIndex } from '@/actions/App/Http/Controllers/JourneyTemplateController';
import { index as notificationsIndex } from '@/actions/App/Http/Controllers/NotificationController';
import { index as salesIndex } from '@/actions/App/Http/Controllers/SaleController';
import { edit as commercialSettings } from '@/actions/App/Http/Controllers/CommercialSettingsController';
import { index as classificationIndex } from '@/actions/App/Http/Controllers/CustomerScoreController';
import { index as teamsIndex } from '@/actions/App/Http/Controllers/TeamController';
import { index as organizationUsersIndex } from '@/actions/App/Http/Controllers/OrganizationInvitationController';
import { index as companyPortfolioIndex } from '@/actions/App/Http/Controllers/CompanyPortfolioController';
import CommercialReportController from '@/actions/App/Http/Controllers/CommercialReportController';
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
    { title: 'Vendas', href: salesIndex.url(), icon: CircleDollarSign },
    {
        title: 'Classificação comercial',
        href: classificationIndex.url(),
        icon: Gauge,
    },
    { title: 'Atividades', href: tasksIndex.url(), icon: ListTodo },
    { title: 'Jornadas', href: journeysIndex.url(), icon: Route },
    { title: 'Notificações', href: notificationsIndex.url(), icon: Bell },
    {
        title: 'Relatórios',
        href: CommercialReportController.url(),
        icon: ChartNoAxesCombined,
    },
];

export function AppSidebar() {
    const { activeOrganization, organizations } = usePage().props;
    const canManageOrganization = ['owner', 'admin', 'manager'].includes(
        activeOrganization?.role ?? '',
    );
    const canManageMembers = ['owner', 'admin'].includes(
        activeOrganization?.role ?? '',
    );
    const navItems = canManageOrganization
        ? [
              ...mainNavItems,
              {
                  title: 'Configurações comerciais',
                  href: commercialSettings.url(),
                  icon: Settings2,
              },
              { title: 'Equipes', href: teamsIndex.url(), icon: UsersRound },
              ...(canManageMembers
                  ? [
                        {
                            title: 'Usuários',
                            href: organizationUsersIndex.url(),
                            icon: UsersRound,
                        },
                    ]
                  : []),
              ...(canManageMembers
                  ? [
                        {
                            title: 'Carteiras',
                            href: companyPortfolioIndex.url(),
                            icon: ArrowRightLeft,
                        },
                    ]
                  : []),
          ]
        : mainNavItems;

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
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
