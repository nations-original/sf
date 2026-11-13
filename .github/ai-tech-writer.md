# ai-tech-writer — sf (Simple Framework)

- This repo is the `nations-original/sf` composer package — the framework itself, not an app.
  Docs live in `nations-original/wiki` under `framework/`:
  - `framework/core` — lifecycle, dual kernel, routing, controllers, middleware, views, sessions, redirects.
  - `framework/data` — entities, repositories, validation, cache, fixtures, enums.
  - `framework/supporting` — helper functions, translation, events, CRUD controller.
  - `framework/dev` — PHPUnit, Codeception, dev mode.
- Source map: `src/` = `PHP_SF\System` (Kernel, Router and system core); `app/` = `PHP_SF\Framework`
  (controllers, middleware, HTTP); `functions/` = the global helpers (`r()`, `s()`, `_t()`, view/time
  helpers). Keep the core/data/supporting pages in sync with these.
- Boundary: the project skeleton (`App/`, `init.sh`, `config/`, docker, webpack, template engines) is
  documented from `nations-original/sf-template`, not here. `framework/core/dual-kernel` is shared —
  the SF kernel (`PHP_SF\System\Kernel`) lives here, the app's Symfony kernel in the template.
- Release semantics: composer package versioned by `vX.Y.Z` git tags, published to packagist as
  `nations-original/sf`; there is no `version` field in composer.json. Requires PHP `8.5.*`.
  The template pins this package (`^4.3`); a framework change that alters the public API should note
  the template impact.
