import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { useTranslation } from 'react-i18next';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Mascot } from '@/components/Mascot';
import { Alert, Button } from '@/components/ui';
import { ToastProvider } from '@/components/toast';
import { LoginPage, SetupPage } from '@/features/auth/AuthScreens';
import { SessionProvider, useAppStatus, useMe, useSession } from '@/features/auth/session';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { ExpiryPage } from '@/features/products/ExpiryPage';
import { LabelsPage } from '@/features/products/LabelsPage';
import { ProductDetailPage } from '@/features/products/ProductDetailPage';
import { ProductFormPage } from '@/features/products/ProductFormPage';
import { ProductsPage } from '@/features/products/ProductsPage';
import { ReportPage, ReportsPage } from '@/features/reports/ReportsPages';
import { BillPage } from '@/features/sales/BillPage';
import { CreditNotePage } from '@/features/sales/CreditNotePage';
import { PosPage } from '@/features/sales/PosPage';
import { SalesHistoryPage } from '@/features/sales/SalesHistoryPage';
import { CustomerPage, UdharPage } from '@/features/sales/UdharPages';
import { RolesPage } from '@/features/settings/RolesPage';
import { ShopSettingsPage } from '@/features/settings/ShopSettingsPage';
import { UsersPage } from '@/features/settings/UsersPage';
import { AppLayout } from '@/layout/AppLayout';
import '@/lib/i18n';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: { refetchOnWindowFocus: false, retry: 1 },
    },
});

/** Sends users without the permission back to the dashboard. The server checks again regardless. */
function Guard({ permission, children }: { permission: string | string[]; children: React.ReactNode }) {
    const { can } = useSession();
    return [permission].flat().some(can) ? children : <Navigate to="/" replace />;
}

export function App() {
    const { t } = useTranslation();
    const status = useAppStatus();
    const me = useMe();

    if (status.isPending || me.isPending) {
        return (
            <main className="flex min-h-dvh flex-col items-center justify-center gap-3" role="status">
                <Mascot mood="hello" className="h-32 w-28" />
                <p className="text-ink-soft">{t('common.loading')}</p>
            </main>
        );
    }

    if (status.isError || me.isError) {
        return (
            <main className="mx-auto flex min-h-dvh max-w-lg flex-col items-center justify-center gap-4 p-6 text-center">
                <Mascot mood="sad" className="h-32 w-28" />
                <Alert>{t('app.no_connection_help')}</Alert>
                <Button
                    onClick={() => {
                        void status.refetch();
                        void me.refetch();
                    }}
                >
                    {t('common.retry')}
                </Button>
            </main>
        );
    }

    if (status.data.needs_setup) return <SetupPage />;
    if (me.data === null) return <LoginPage />;

    return (
        <SessionProvider user={me.data}>
            <Routes>
                <Route element={<AppLayout />}>
                    <Route index element={<DashboardPage />} />
                    <Route
                        path="sale"
                        element={
                            <Guard permission="sales.create">
                                <PosPage />
                            </Guard>
                        }
                    />
                    <Route
                        path="sales"
                        element={
                            <Guard permission="sales.create">
                                <SalesHistoryPage />
                            </Guard>
                        }
                    />
                    <Route
                        path="sales/:id"
                        element={
                            <Guard permission="sales.create">
                                <BillPage />
                            </Guard>
                        }
                    />
                    <Route
                        path="returns/:id"
                        element={
                            <Guard permission="sales.create">
                                <CreditNotePage />
                            </Guard>
                        }
                    />
                    <Route path="udhar" element={<UdharPage />} />
                    <Route path="customers/:id" element={<CustomerPage />} />
                    <Route path="products" element={<ProductsPage title={t('nav.products')} />} />
                    <Route
                        path="products/new"
                        element={
                            <Guard permission={['products.manage', 'medicines.manage']}>
                                <ProductFormPage />
                            </Guard>
                        }
                    />
                    <Route path="products/:id" element={<ProductDetailPage />} />
                    <Route
                        path="products/:id/edit"
                        element={
                            <Guard permission={['products.manage', 'medicines.manage']}>
                                <ProductFormPage />
                            </Guard>
                        }
                    />
                    <Route path="stock/medical" element={<ProductsPage type="medical" title={t('nav.medical_stock')} />} />
                    <Route path="stock/general" element={<ProductsPage type="general" title={t('nav.general_stock')} />} />
                    <Route path="expiry" element={<ExpiryPage />} />
                    <Route
                        path="labels"
                        element={
                            <Guard permission="products.manage">
                                <LabelsPage />
                            </Guard>
                        }
                    />
                    <Route
                        path="reports"
                        element={
                            <Guard permission="reports.view">
                                <ReportsPage />
                            </Guard>
                        }
                    />
                    <Route
                        path="reports/:key"
                        element={
                            <Guard permission="reports.view">
                                <ReportPage />
                            </Guard>
                        }
                    />
                    <Route path="settings/roles" element={me.data.roles.includes('owner') ? <RolesPage /> : <Navigate to="/" replace />} />
                    <Route path="settings/shop" element={<ShopSettingsPage />} />
                    <Route
                        path="settings/users"
                        element={
                            <Guard permission="users.manage">
                                <UsersPage />
                            </Guard>
                        }
                    />
                    <Route path="*" element={<Navigate to="/" replace />} />
                </Route>
            </Routes>
        </SessionProvider>
    );
}

const root = document.getElementById('root');
if (root) {
    createRoot(root).render(
        <StrictMode>
            <QueryClientProvider client={queryClient}>
                <ToastProvider>
                    <BrowserRouter>
                        <App />
                    </BrowserRouter>
                </ToastProvider>
            </QueryClientProvider>
        </StrictMode>,
    );
}
