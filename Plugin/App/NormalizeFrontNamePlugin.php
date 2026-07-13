<?php
/**
 * Copyright © Rees Solutions. All rights reserved.
 */
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\App;

use Magento\Framework\App\AreaList;

/**
 * Normalises the front name passed to AreaList::getCodeByFrontName() to a string.
 *
 * In FrankenPHP worker mode, opengento's BootstrapPool::resolveAreaCode() resolves the
 * request's area with getCodeByFrontName(strtok(trim($_SERVER['REQUEST_URI'], '/'), '/')).
 * For the storefront root path "/", strtok('', '/') returns bool false rather than a string.
 *
 * AreaList::getCodeByFrontName() then compares that value with strict === against each area's
 * front name. The adminhtml area has no static front name; it is resolved lazily via
 * Magento\Backend\App\Area\FrontNameResolver::getFrontName(true), which returns bool false
 * whenever the request host is not the configured backend host (internal/health-probe requests,
 * or any request whose host differs from the admin base URL). So false === false matches, and
 * the storefront homepage is bootstrapped into the adminhtml area. The admin router then reads
 * a null default path (web/default/admin is unset) and calls explode('/', null) → 500, which
 * Varnish surfaces as a site-wide 503.
 *
 * Casting the front name to string turns the root path's false into '' — which correctly
 * resolves to the frontend area — and can no longer collide with the admin resolver's false.
 * All legitimate callers already pass string front names, for which this is a no-op.
 *
 * This is a downstream guard for an upstream opengento bug in BootstrapPool::resolveAreaCode();
 * remove it once opengento casts the strtok() result itself.
 *
 * @see \Magento\Framework\App\AreaList::getCodeByFrontName()
 * @see \Magento\Backend\App\Area\FrontNameResolver::getFrontName()
 */
class NormalizeFrontNamePlugin
{
    /**
     * Force the front name to a string before the strict === lookup.
     *
     * @param AreaList $subject
     * @param mixed $frontName
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeGetCodeByFrontName(AreaList $subject, $frontName): array
    {
        return [(string)$frontName];
    }
}
