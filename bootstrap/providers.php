<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TenancyServiceProvider::class,
];
