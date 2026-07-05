# CLAUDE.md — MageOS_WorkerMode

This module makes Magento/MageOS run correctly under FrankenPHP worker mode. Every class here exists to fix a specific category of state-leakage bug. Before touching anything, understand the three mechanisms below.

This module is written to sit on top of **stock `opengento/module-application`** with **no composer patch**. The corresponding upstream-fork fixes (the "ideal" that would let much of this module shrink) are tracked at the repo root in `OPENGENTO_UPSTREAM_CHANGES.md`; the living downstream plan (what we carry now, and when to remove it) is `OPENGENTO_DOWNSTREAM_PLAN.md`.

---

## Mechanism 1: ResetAfterRequestInterface

The `opengento/module-application` `Resetter` runs between every request. For any class that implements `Magento\Framework\ObjectManager\ResetAfterRequestInterface`, the Resetter calls `_resetState()` as a **direct method call**. This is the correct reset path.

For classes that do *not* implement the interface, the Resetter falls back to `ReflectionProperty::setValue()` guided by `etc/reset.json`. On PHP 8.4+, this silently fails for lazy ghosts (see Mechanism 2).

We use this interface two ways:
- **Subclass** a class we also need to change behaviourally (only `Model/View/Layout` — it overrides `isCacheable()`).
- **`Model/Reset/LazyGhostReset`** — a single `ResetAfterRequestInterface` helper that resets classes we do *not* want to subclass. It force-initialises each target lazy ghost, then reflection-resets specific properties on the declaring class. This is the no-patch stand-in for the upstream Resetter fix.

**Rule:** never rely on `reset.json` for a class whose reset actually matters on PHP 8.4 — route it through `LazyGhostReset` (or a subclass).

---

## Mechanism 2: PHP 8.4 lazy ghost reset failure

The DI container creates Interceptors as lazy ghosts (`ReflectionClass::newLazyGhost()`). The Resetter holds a `ReflectionProperty` sourced from the **parent class** (not the Interceptor). On an uninitialized ghost, `setValue()` from the parent RP writes into the parent scope. Running code reads the Interceptor scope (still uninitialized), triggering the lazy initialiser, which overwrites the reset.

**Symptom pattern:** a property appears to reset between requests in isolation but keeps its old value in production worker mode. If the old value was from a `reset.json` entry that appeared to work in PHP 8.3 but broke on 8.4, this is the cause.

**Fix:** initialise the ghost *first*, then write. `LazyGhostReset` does exactly this — `ReflectionClass::initializeLazyObject()` then `ReflectionProperty::setValue()` on the declaring class — for `Design`, `Page\Config`, `Area`, `AdminSessionsManager` and the admin `Widget\Context` ButtonList. `Layout` gets the same effect for free because its `_resetState()` is a real method call on the subclass.

(The upstream fix — patch the framework `Resetter` to initialise ghosts before reflecting — is `OPENGENTO_UPSTREAM_CHANGES.md` "Change 4". If it lands, `LazyGhostReset` can be deleted.)

---

## Mechanism 3: Area-scoped DI

Each area (`global`, `frontend`, `adminhtml`, `webapi_rest`) loads a separate DI graph in the `BootstrapPool`. Plugins registered in `etc/di.xml` apply globally across all area bootstraps. Plugins registered in `etc/frontend/di.xml` apply only to the frontend bootstrap, and so on.

**`SessionCommitPlugin` is deliberately registered in `adminhtml` and `webapi_rest` only.** Global registration alters the frontend session lifecycle and causes login failures and cart errors. See the `feedback_session_commit_area.md` memory for the full incident.

**Session start is now global and self-limiting.** `SessionStartPlugin` (on `Magento\Framework\Session\SessionManager`, `etc/di.xml`) starts a session lazily only when the request actually touches it. Combined with the `SessionRegistry` reset (which stops opengento pre-starting every prior session), `CustomerSession` never lazy-starts inside an admin request — so no frontend-cookie-config `start()` corrupts admin `regenerateId()`. This replaces the old per-area `CustomerSessionPlugin`, which had to be manually excluded from adminhtml.

---

## File map

```
etc/
  module.xml              — sequence: Opengento_Application, Magento_TwoFactorAuth
  reset.json              — reflection-reset entries for third-party singletons (fallback path only)
  di.xml                  — global: Layout <preference>, SessionRegistry <preference>,
                            isIsolated=false (Page/Layout result types), SessionStartPlugin,
                            ConfigPlugin (html.lang), FrontendConfig scopeType, and two App\Http
                            plugins (ObjectManagerContextPlugin + LazyGhostReset)
  adminhtml/di.xml        — AuthSessionProcessLoginPlugin, SessionCommitPlugin (admin 302 race fix)
  frontend/di.xml         — CustomerSession FrontendConfig injection, RegistrationPlugin,
                            DefaultConfigProviderPlugin, CheckoutSessionPlugin, PreserveOrderDataPlugin
  webapi_rest/di.xml      — CustomerSession + CheckoutSession FrontendConfig injection,
                            RestResponseFallbackPlugin, SessionCommitPlugin

App/Session/
  SessionRegistry.php     — clears the WeakMap in _resetState(); prevents cross-request/cross-area
                            session accumulation. Overrides opengento's SessionRegistry — this is the
                            one <preference> against an opengento class we intentionally keep.

Model/Reset/
  LazyGhostReset.php      — ResetAfterRequestInterface helper, registered as a no-op beforeLaunch
                            plugin on Opengento\Application\App\Http (so DI instantiates it and the
                            Resetter tracks it). _resetState() force-initialises each target lazy ghost
                            then reflection-resets: Design (_area/_theme), Page\Config
                            (pageLayout/elements/includes/metadata), Area (_loadedParts['design']),
                            AdminSessionsManager (currentSession), admin Widget\Context ButtonList
                            (_buttons). No-patch stand-in for the framework Resetter fix.

Model/View/
  Layout.php              — ResetAfterRequestInterface subclass. Kept a subclass (not folded into
                            LazyGhostReset) because it ALSO overrides isCacheable()/generateElements().
                            _resetState() clears _xml (root cause of the stale-layout / checkout-success
                            depersonalize bug), _blocks, readerContext (not in opengento's reset.json),
                            and other layout state.

Plugin/App/
  ObjectManagerContextPlugin.php  — restores ObjectManager::$_instance to the correct area OM before
                                    each request (the static is overwritten by every bootstrap)
  RestResponseFallbackPlugin.php  — fixes opengento's handleHttpResult() swallowing REST exceptions
  SessionCommitPlugin.php         — closeSessions() before sendResponse() (admin + REST only)

Plugin/Checkout/Block/
  RegistrationPlugin.php          — catches InputException from OrderRepository::get(0) when
                                    last_order_id is missing; returns '' so the block hides silently

Plugin/Checkout/Model/
  CheckoutSessionPlugin.php       — aroundGetQuote: retries getQuote() on LockWaitException (session
                                    start itself is handled by SessionStartPlugin)
  DefaultConfigProviderPlugin.php — last-resort: repairs CustomerSession + retries getConfig() on
                                    NoSuchEntityException from getCustomerId()=null

Plugin/Checkout/Model/Session/
  PreserveOrderDataPlugin.php     — safety net: saves/restores last_real_order_id across clearStorage()
                                    on checkout_onepage_success only

Plugin/Session/
  SessionStartPlugin.php          — THE session-lifecycle plugin (global, on Magento\Framework\Session\
                                    SessionManager). Lazily starts any session on first access this
                                    request — magic __call, explicit getData, the Customer/Auth entry
                                    methods that read storage directly (isLoggedIn/getCustomerId), and
                                    the TFA grant methods. afterGetCustomerId repairs a null id from a
                                    mid-request reference break. Consolidates the former six per-session
                                    start() plugins.
  AuthSessionProcessLoginPlugin.php — admin-login backstop: re-establishes the session reference before
                                    regenerateId() when start() failed silently before setUser()

Plugin/View/Page/
  ConfigPlugin.php                — re-adds html.lang after reset clears elements=[]; prevents
                                    Intl.NumberFormat breakage (ElasticSuite price slider)

Session/
  FrontendConfig.php              — stores session.name='PHPSESSID' so initIniOptions() always undoes
                                    'admin' ini contamination left by a prior admin request on the worker
```

Hyvä-specific classes live in the companion module `MageOS_WorkerModeHyva` (see below).

---

## reset.json entries (third-party singletons)

The `etc/reset.json` in this module covers stateful third-party classes reset via the reflection fallback:

| Class | Properties reset | Why |
|---|---|---|
| `ScheduledStructure\Helper` | `counter` | Accumulates between requests |
| `CspNonceProvider` | `nonce` | Nonce must be fresh per request |
| `DynamicCollector` | `added` | CSP directives accumulate |
| `Magento\Theme\Model\View\Design` | `_area`, `_theme` | Fallback only — see note below |
| `GroupedCollection` | `assets`, `groups` | Asset collection carries previous page's assets |
| `FlyweightFactory` | `themes`, `themesByPath` | Theme cache grows unbounded; has only a Proxy (not a lazy ghost), so reflection reset works |
| `Rest\InputParamsResolver` | `route` | Stale route from prior REST request |
| `Template\File\Resolver` | `_templateFilesMap` | Template resolution cache |
| `Page\Layout\Reader` | `pageLayoutMerge` | Stale merged page layout |
| `ScheduledStructure` | all fields | Layout build artifacts |

**Note on the `Design` entry:** on PHP 8.4 lazy ghosts the reflection path silently no-ops, so `LazyGhostReset` is what actually resets `Design._area`/`_theme`. The `reset.json` entry is harmless and kept only as defence-in-depth for non-lazy / older-PHP paths. Do not treat it as the working reset.

---

## Companion module: MageOS_WorkerModeHyva

Hyvä-specific resets live in `mage-os/module-worker-mode-hyva` (`WorkerModeHyva/` sibling directory). That module sequences after both `MageOS_WorkerMode` and `Hyva_Theme`, so it only compiles on Hyvä stores. A Luma store installs only the base module.

What the companion module owns:

| File | Purpose |
| --- | --- |
| `Model/Reset/HyvaCspReset.php` | `ResetAfterRequestInterface` helper, registered as a no-op beforeLaunch plugin on `App\Http`. Force-inits the `Hyva\Theme\ViewModel\HyvaCsp` lazy ghost then reflection-clears `memoizedPolicies` + `memoizedAreaCode` (private parent props) |
| `etc/di.xml` | registers `HyvaCspReset` as the `App\Http` plugin (no `<preference>`) |
| `etc/reset.json` | `\Hyva\GraphqlTokens\CustomerData\CartPlugin` — stale quote reference |

**Note on ProductListItem (`OutOfBoundsException`):** this base module's `isIsolated=false` on `Page`/`Layout` result types already prevents the shared-singleton/isolated-layout mismatch that causes this exception. No `ProductListItem` ViewModel override is needed.

---

## Common mistakes to avoid

**Do not** move worker-mode fixes out of `MageOS_WorkerMode` into other modules. This module owns all opengento/FrankenPHP concerns.

**Do not** register `SessionCommitPlugin` globally in `etc/di.xml`. It must be area-scoped (`adminhtml` and `webapi_rest` only). Global registration runs `closeSessions()` after every frontend response, closing the frontend session before Magento's own session lifecycle has finished.

**Do not** add a new stateful singleton to `reset.json` if its reset matters on PHP 8.4 — the reflection path silently fails on lazy ghosts. Route it through `LazyGhostReset` (or subclass it).

**Do not** set `isIsolated=true` on `Page` or `Layout` result types. The reset mechanism already resets layout state between requests. Isolation creates a private layout instance per request, breaking Hyvä's `ProductListItem` view model which holds the shared `LayoutInterface` singleton.

**Do not** re-introduce per-session start plugins (`CustomerSessionPlugin`, `AdminAuthSessionPlugin`, a `SuccessValidator`/`QuoteManagement` start plugin, etc.). `SessionStartPlugin` is the single global entry point; add a new `before<Method>` there if a code path reads session storage without first triggering a start. Do NOT add a generalized `after__call` re-bind — that regressed and was fully reverted (see the `project_worker_session_redesign` memory).

**When a class needs resetting between requests:**
1. If you also need to change its behaviour → subclass it, implement `ResetAfterRequestInterface`, add a `<preference>` (like `Layout`).
2. Otherwise → add it to `LazyGhostReset::_resetState()` (force-init the ghost, then reflection-reset the declaring class's properties).
3. Do NOT also add a `reset.json` entry for the same class — the two mechanisms compete.

---

## Interaction with opengento/module-application

The opengento package provides:
- `BootstrapPool` — creates and caches one `AppBootstrap` (and OM) per area code
- `SessionRegistry` — `WeakMap` of sessions started in the current request (our subclass clears it)
- `Resetter` — iterates `reset.json` entries + calls `_resetState()` on `ResetAfterRequestInterface` implementations
- `App\Http` — the request handler that calls `launch()`, then `resetState()` in a `finally` block

This module intercepts `App\Http` via plugins:
- `ObjectManagerContextPlugin` (global, sortOrder=1) — restores `$_instance` before the area bootstrap runs
- `LazyGhostReset` (global, no-op `beforeLaunch`) — exists so the Resetter tracks it; does the lazy-ghost resets in `_resetState()`
- `RestResponseFallbackPlugin` (webapi_rest, sortOrder=100) — fixes empty REST responses
- `SessionCommitPlugin` (adminhtml + webapi_rest, sortOrder=10000) — commits sessions before sendResponse

sortOrder=10000 on `SessionCommitPlugin` ensures it runs last among `afterLaunch` plugins, so all request processing (including session writes) has completed before `closeSessions()` is called.
