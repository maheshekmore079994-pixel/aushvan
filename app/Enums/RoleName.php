<?php

namespace App\Enums;

enum RoleName: string
{
    case Owner = 'owner';
    case Pharmacist = 'pharmacist';
    case StoreManager = 'store_manager';
    case Cashier = 'cashier';

    /**
     * Default permissions for a fresh installation. The Owner is not listed:
     * owners pass every permission check (see AppServiceProvider).
     *
     * @return list<string>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Owner => [],
            self::Pharmacist => [
                Permission::SALES_CREATE, Permission::SALES_RETURN, Permission::CUSTOMERS_MANAGE,
                Permission::CREDIT_RECEIVE, Permission::MEDICINES_MANAGE, Permission::PRESCRIPTIONS_VIEW,
                Permission::PHARMACIST_VERIFY, Permission::RETURNS_RESTOCK_MEDICINE, Permission::REPORTS_VIEW,
                Permission::DAY_CLOSE,
            ],
            self::StoreManager => [
                Permission::PRODUCTS_MANAGE, Permission::MEDICINES_MANAGE, Permission::STOCK_ADJUST,
                Permission::STOCK_VIEW_COST, Permission::PURCHASES_MANAGE, Permission::SUPPLIERS_MANAGE,
                Permission::CUSTOMERS_MANAGE, Permission::EXPENSES_MANAGE, Permission::REPORTS_VIEW,
                Permission::DAY_CLOSE,
            ],
            self::Cashier => [
                Permission::SALES_CREATE, Permission::SALES_RETURN, Permission::CUSTOMERS_MANAGE,
                Permission::CREDIT_RECEIVE, Permission::DAY_CLOSE,
            ],
        };
    }
}
