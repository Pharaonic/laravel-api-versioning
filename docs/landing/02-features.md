---
view: components.packages.features
variant: compact
badge: Key Features
title: Everything an evolving API needs
subtitle: Add a version list to the config and a constraint to your routes.
items:
  - icon: code-brackets
    title: Native Route API
    text: "`Route::version('v2')->middleware(...)->group(...)` and `->sinceVersion('v2')` on any route, resource or group."
  - icon: switch
    title: Header, Query or Path
    text: Resolve `X-API-Version`, `?version=`, an `api/{version}` prefix or your own resolver; conflicts are rejected.
  - icon: grid
    title: Controller per Version
    text: One route per URI runs `UserV2Controller` for v2, with its middleware, bindings and policies.
  - icon: document
    title: Versioned Resources
    text: "`whenVersion('>=v2', ...)` removes fields entirely and never runs the closures of removed fields."
  - icon: clock
    title: Deprecation and Sunset
    text: RFC 9745 `Deprecation`, RFC 8594 `Sunset` and `Link` headers per version, and retired versions return 410.
  - icon: bolt
    title: Cache and Octane Safe
    text: Works with `route:cache` and `config:cache`, and keeps the version on the request only.
  - icon: alert-circle
    title: Predictable Errors
    text: "`ApiVersionException` carries an error code per case and configurable status codes, and never repeats the version the client sent."
  - icon: shield-check
    title: Checked at Registration
    text: Unknown versions, invalid controller maps and duplicate routes or names fail when routes load, not on a request.
  - icon: bot
    title: AI Ready
    text: "Ships a Laravel Boost guideline and an `api-versioning-development` skill, so `php artisan boost:install` teaches Claude Code, Cursor, Codex or Copilot the whole API."
---
