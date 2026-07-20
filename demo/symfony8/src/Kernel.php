<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Symfony MicroKernel for the BeaconBundle FrankenPHP demo.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
