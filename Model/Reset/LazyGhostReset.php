<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Model\Reset;

use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Context as WidgetContext;
use Magento\Framework\App\Area;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Security\Model\AdminSessionsManager;
use Magento\Theme\Model\View\Design;
use Opengento\Application\App\Http;

/**
 * Solution 2 (no patch, no framework <preference>) lazy-ghost state reset for FrankenPHP worker mode.
 *
 * The framework Resetter's reflection path (resetStateWithReflectionByClassName) silently fails on
 * PHP 8.4 lazy-ghost Interceptors: it writes reset.json values into the parent scope of an
 * UNINITIALIZED ghost, and the ghost's later lazy initializer overwrites them. The reliable path is
 * the `_resetState()` method call (the Resetter initializes the ghost before invoking it) — which is
 * why this module previously subclassed each target and implemented `_resetState()` (a <preference>).
 *
 * This ONE class implements ResetAfterRequestInterface (so the Resetter calls its `_resetState()`
 * each request) and, for each target framework singleton, force-initializes the lazy ghost and then
 * applies exactly the reset its former subclass did — replacing four subclass-preferences with a
 * single non-preference helper, and needing no framework patch.
 *
 * It is also wired as a no-op `beforeLaunch` plugin on Opengento\Application\App\Http purely so the
 * DI factory instantiates it (and the Resetter starts tracking it) once per worker.
 *
 * The correct upstream fix is to make the framework Resetter do the ghost-init itself — see
 * OPENGENTO_UPSTREAM_CHANGES.md "Change 4". This helper is deleted once that lands.
 *
 * NOTE: Layout is deliberately NOT handled here — its subclass also overrides isCacheable() /
 * generateElements() (behaviour, not just state), which a reflection helper cannot replicate, so it
 * remains a subclass-preference.
 */
class LazyGhostReset implements ResetAfterRequestInterface
{
    public function __construct(private readonly ObjectManagerInterface $objectManager)
    {
    }

    /**
     * No-op: exists only so this plugin (and therefore this ResetAfterRequest instance) is created.
     *
     * @param Http $subject
     * @return void
     */
    public function beforeLaunch(Http $subject): void
    {
    }

    /**
     * @return void
     */
    public function _resetState(): void
    {
        // Design — mirrors the former MageOS\WorkerMode\Model\View\Design::_resetState().
        $this->reset(Design::class, static function (object $i, callable $set): void {
            $set($i, Design::class, '_area', null);
            $set($i, Design::class, '_theme', null);
        });

        // Page\Config — mirrors the former MageOS\WorkerMode\View\Page\Config::_resetState().
        $this->reset(PageConfig::class, static function (object $i, callable $set): void {
            $set($i, PageConfig::class, 'pageLayout', null);
            $set($i, PageConfig::class, 'elements', []);
            $set($i, PageConfig::class, 'includes', null);
            $set($i, PageConfig::class, 'metadata', [
                PageConfig::META_CHARSET      => null,
                PageConfig::META_MEDIA_TYPE   => null,
                PageConfig::META_CONTENT_TYPE => null,
                PageConfig::META_TITLE        => null,
                PageConfig::META_DESCRIPTION  => null,
                PageConfig::META_KEYWORDS     => null,
                PageConfig::META_ROBOTS       => null,
            ]);
        });

        // Area — mirrors the former MageOS\WorkerMode\Model\App\Area::_resetState() (clears only 'design').
        $this->reset(Area::class, static function (object $i) {
            $property = new \ReflectionProperty(Area::class, '_loadedParts');
            $parts = $property->getValue($i);
            if (is_array($parts)) {
                unset($parts['design']);
                $property->setValue($i, $parts);
            }
        });

        // AdminSessionsManager — mirrors the former MageOS\WorkerMode\Model\Security\AdminSessionsManager.
        $this->reset(AdminSessionsManager::class, static function (object $i, callable $set): void {
            $set($i, AdminSessionsManager::class, 'currentSession', null);
        });

        // Widget\Context — mirrors the former Block/Backend/Widget/Context (admin ButtonList _buttons).
        // Admin-only; on the frontend the get()/reset is harmless (reset() swallows any Throwable).
        $this->reset(WidgetContext::class, static function (object $i): void {
            $buttonList = (new \ReflectionProperty(WidgetContext::class, 'buttonList'))->getValue($i);
            if ($buttonList !== null) {
                (new \ReflectionProperty(ButtonList::class, '_buttons'))
                    ->setValue($buttonList, [-1 => [], 0 => [], 1 => []]);
            }
        });
    }

    /**
     * Get the shared target, force-initialize its lazy ghost, and run the reset callback.
     *
     * @param string $className
     * @param callable $callback function(object $instance, callable $set): void
     * @return void
     */
    private function reset(string $className, callable $callback): void
    {
        try {
            $instance = $this->objectManager->get($className);
            $this->initializeGhost($instance);
            $callback($instance, [$this, 'setProperty']);
        } catch (\Throwable) {
            // A single target's reset must never abort the whole reset cycle.
        }
    }

    /**
     * Initialize a PHP 8.4 lazy-ghost Interceptor so a subsequent reflection write lands in the live slot.
     *
     * @param object $instance
     * @return void
     */
    private function initializeGhost(object $instance): void
    {
        $reflection = new \ReflectionClass($instance);
        if (method_exists($reflection, 'isUninitializedLazyObject')
            && $reflection->isUninitializedLazyObject($instance)
        ) {
            $reflection->initializeLazyObject($instance);
        }
    }

    /**
     * Set a (protected/private) property declared on $declaringClass on the interceptor $instance.
     *
     * @param object $instance
     * @param string $declaringClass
     * @param string $property
     * @param mixed $value
     * @return void
     */
    public function setProperty(object $instance, string $declaringClass, string $property, mixed $value): void
    {
        (new \ReflectionProperty($declaringClass, $property))->setValue($instance, $value);
    }
}
