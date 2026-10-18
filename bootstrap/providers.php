<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Core\Providers\CoreServiceProvider::class,
    App\Modules\UserAdmin\Providers\UserAdminServiceProvider::class,
    App\Modules\Workshop\Providers\WorkshopServiceProvider::class,
    App\Modules\Billing\Providers\BillingServiceProvider::class,
    App\Modules\Hr\Providers\HrServiceProvider::class,
    App\Modules\Accounts\Providers\AccountsServiceProvider::class,
    App\Modules\Crm\Providers\CrmServiceProvider::class,
    App\Modules\CustomerApp\Providers\CustomerAppServiceProvider::class,
];
