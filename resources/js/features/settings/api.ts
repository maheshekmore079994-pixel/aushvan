import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type GstMode = 'unregistered' | 'composition' | 'regular';

export type ShopDetails = {
    name: string;
    legal_name: string | null;
    address: string | null;
    village: string | null;
    taluka: string | null;
    district: string | null;
    state_code: string;
    pincode: string | null;
    phone: string | null;
    gst_mode: GstMode;
    gstin: string | null;
    settings: {
        drug_licence_20: string | null;
        drug_licence_21: string | null;
        drug_licence_20f: string | null;
        licence_retention_due: string | null;
        bill_footer: string | null;
        counter_prefix: string | null;
    };
};

/** The roles every shop starts with. The owner can add more on the Roles screen. */
export const ROLES = ['owner', 'pharmacist', 'store_manager', 'cashier'] as const;
export type RoleName = string;

export type StaffUser = {
    id: number;
    name: string;
    username: string;
    mobile: string | null;
    locale: 'en' | 'mr' | 'hi';
    is_pharmacist: boolean;
    pharmacist_reg_no: string | null;
    is_active: boolean;
    has_pin: boolean;
    roles: RoleName[];
    last_login_at: string | null;
};

export const shopKey = ['store'] as const;
export const usersKey = ['users'] as const;

export const useShop = () => useQuery({ queryKey: shopKey, queryFn: () => api<ShopDetails>('/store') });

type UsersAnswer = { data: StaffUser[]; roles?: string[] };
const fetchUsers = () => api<UsersAnswer>('/users');

export const useUsers = (enabled = true) => useQuery({ queryKey: usersKey, queryFn: fetchUsers, select: (r) => r.data, enabled });

/** Every role that can be given to a user, including roles the owner has added. */
export const useRoleNames = () => useQuery({ queryKey: usersKey, queryFn: fetchUsers, select: (r) => r.roles ?? [...ROLES] });
