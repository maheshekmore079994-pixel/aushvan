<?php

namespace App\Enums;

/** Every permission the application checks. Roles hold these as data and can be edited. */
final class Permission
{
    public const SALES_CREATE = 'sales.create';

    public const SALES_CANCEL = 'sales.cancel';

    public const SALES_RETURN = 'sales.return';

    public const SALES_DISCOUNT_OVERRIDE = 'sales.discount_override';

    public const SALES_PRICE_OVERRIDE = 'sales.price_override';

    public const PRODUCTS_MANAGE = 'products.manage';

    public const MEDICINES_MANAGE = 'medicines.manage';

    public const STOCK_ADJUST = 'stock.adjust';

    public const STOCK_VIEW_COST = 'stock.view_cost';

    public const PURCHASES_MANAGE = 'purchases.manage';

    public const SUPPLIERS_MANAGE = 'suppliers.manage';

    public const CUSTOMERS_MANAGE = 'customers.manage';

    public const CREDIT_RECEIVE = 'credit.receive';

    public const CREDIT_REVERSE = 'credit.reverse';

    public const PRESCRIPTIONS_VIEW = 'prescriptions.view';

    public const PHARMACIST_VERIFY = 'pharmacist.verify';

    public const RETURNS_RESTOCK_MEDICINE = 'returns.restock_medicine';

    public const EXPENSES_MANAGE = 'expenses.manage';

    public const DAY_CLOSE = 'day.close';

    public const DAY_REOPEN = 'day.reopen';

    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_FINANCIAL = 'reports.financial';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const USERS_MANAGE = 'users.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const BACKUP_MANAGE = 'backup.manage';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
