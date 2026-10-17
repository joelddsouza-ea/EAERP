<?php

namespace Tests\Feature;

use App\Core\Support\WorkshopSettings;

/**
 * The LifecycleAndBilling tests again with Accounts, CRM and Attendance & payroll switched off:
 * the core workshop flow must not depend on the optional modules.
 */
class LifecycleAndBillingModulesOffTest extends LifecycleAndBillingTest
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['accounts', 'crm', 'hr'] as $module) {
            WorkshopSettings::set("modules.{$module}", false);
        }
    }
}
