<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\CompanyController;
use App\Repositories\CompanyRepository;
use App\Services\CompanyService;
use CreditManager;
use SmsApp;

final class ControllerFactory
{
    public static function companies(): CompanyController
    {
        $repository = new CompanyRepository(new SmsApp(), new CreditManager());
        return new CompanyController(new CompanyService($repository));
    }
}
