<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Core\Providers\CoreServiceProvider::class,
    App\Modules\UserAdmin\Providers\UserAdminServiceProvider::class,
    App\Modules\Workshop\Providers\WorkshopServiceProvider::class,
    App\Modules\Billing\Providers\BillingServiceProvider::class,
];
