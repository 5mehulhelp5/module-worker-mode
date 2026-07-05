<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\Session;

use Magento\Framework\Exception\SessionException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Session\SessionManager;
use WeakMap;

/**
 * Lazily (re)start any session on first access this request, in FrankenPHP worker mode.
 *
 * A SessionManager singleton is constructed once per worker; its constructor calls start() a single
 * time for the whole worker lifetime. On every subsequent request the object is reused without
 * re-construction, so start() is never called again and storage->_data stays bound to a previous
 * request's $_SESSION (the reference break). start()'s `storage->init($_SESSION)` is the only thing
 * that re-binds storage to the current request.
 *
 * This single plugin replaces the per-session start() plugins by ensuring start() runs before the
 * first access this request, through the access points that actually read session state:
 *  - before__call / beforeGetData — magic getters/setters and explicit getData() on any session
 *    (checkout getQuoteId, message data, admin form-key/TFA, the Sellers generic admin session…);
 *  - beforeIsLoggedIn / beforeGetCustomerId — Customer/Auth entry methods whose inner reads hit
 *    $this->storage->getData() DIRECTLY (bypassing __call/getData), so start must run before them.
 *
 * It starts ONLY the sessions a request actually touches, with the current area's config, so it
 * never re-starts an unrelated or cross-area session the way a persistent SessionRegistry would.
 * Wired globally on Magento\Framework\Session\SessionManager, so every session subclass is covered.
 */
class SessionStartPlugin implements ResetAfterRequestInterface
{
    /** @var WeakMap<SessionManager, true> Sessions already started this request. */
    private WeakMap $started;

    /** @var bool Re-entry guard for the afterGetCustomerId repair. */
    private bool $repairing = false;

    public function __construct()
    {
        $this->started = new WeakMap();
    }

    /**
     * Magic get/set/uns/has (getQuoteId, setLastOrderId, …) route through __call.
     *
     * @param SessionManager $subject
     * @param string $method
     * @param array $args
     * @return void
     */
    public function before__call(SessionManager $subject, $method, $args = []): void
    {
        $this->ensureStarted($subject);
    }

    /**
     * getData() is an explicit method, so it does not route through __call.
     *
     * @param SessionManager $subject
     * @param string $key
     * @param bool $clear
     * @return void
     */
    public function beforeGetData(SessionManager $subject, $key = '', $clear = false): void
    {
        $this->ensureStarted($subject);
    }

    /**
     * Customer/Auth isLoggedIn() reads storage directly and must be started first.
     *
     * @param SessionManager $subject
     * @return void
     */
    public function beforeIsLoggedIn(SessionManager $subject): void
    {
        $this->ensureStarted($subject);
    }

    /**
     * Customer\Session::getCustomerId() reads $this->storage->getData() directly (bypasses __call).
     *
     * @param SessionManager $subject
     * @return void
     */
    public function beforeGetCustomerId(SessionManager $subject): void
    {
        $this->ensureStarted($subject);
    }

    /**
     * Repair a null CustomerSession id in worker mode: if getCustomerId() returns empty, the
     * storage->_data reference may have broken mid-request after the once-per-request start() ran, so
     * re-run start() (bypassing the guard) and retry — otherwise account pages call
     * customerRepository->getById('') → NoSuchEntityException. (Retired CustomerSessionPlugin repair.)
     *
     * @param SessionManager $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetCustomerId(SessionManager $subject, $result)
    {
        if (!empty($result) || $this->repairing) {
            return $result;
        }
        $this->repairing = true;
        try {
            $subject->start();
            return $subject->getCustomerId();
        } catch (\Throwable) {
            return $result;
        } finally {
            $this->repairing = false;
        }
    }

    /**
     * TfaSession (a SessionManager) reads/writes tfa_passed and skipped-provider config via
     * $this->storage->{get,set}Data() DIRECTLY — bypassing __call/getData — so start must run before
     * these. Otherwise grantAccess()'s write lands on an unstarted session and admin login loops on
     * the TFA screen. (Replaces the eager TfaSession start the retired AdminAuthSessionPlugin did.)
     *
     * @param SessionManager $subject
     * @return void
     */
    public function beforeGrantAccess(SessionManager $subject): void
    {
        $this->ensureStarted($subject);
    }

    /**
     * @param SessionManager $subject
     * @return void
     */
    public function beforeIsGranted(SessionManager $subject): void
    {
        $this->ensureStarted($subject);
    }

    /**
     * @param SessionManager $subject
     * @param array $config
     * @return void
     */
    public function beforeSetSkippedProviderConfig(SessionManager $subject, array $config): void
    {
        $this->ensureStarted($subject);
    }

    /**
     * Start the session once per request, (re)binding its storage to the current $_SESSION.
     *
     * @param SessionManager $subject
     * @return void
     */
    private function ensureStarted(SessionManager $subject): void
    {
        if (isset($this->started[$subject])) {
            return;
        }
        // Set the flag BEFORE start(): start() runs validator->validate($this), which reads session
        // state and re-enters this plugin. The pre-set flag breaks that recursion.
        $this->started[$subject] = true;
        try {
            $subject->start();
        } catch (SessionException) {
            // Area code momentarily unavailable, or the validator rejected a stale cookie. Leave the
            // flag set so one failed attempt does not become a per-access retry storm; a later
            // targeted path (e.g. login) re-establishes the session.
        }
    }

    /**
     * @return void
     */
    public function _resetState(): void
    {
        $this->started = new WeakMap();
        $this->repairing = false;
    }
}
