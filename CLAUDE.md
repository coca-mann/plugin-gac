# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

The `gac` GLPI plugin (GLPI 11.0.x, PHP >= 8.2), generated from the [pluginsGLPI/empty](https://github.com/pluginsGLPI/empty) template. The current version is `PLUGIN_GAC_VERSION` in `setup.php` (kept in sync with `gac.xml`; releases are tagged `v<version>`), namespace `GlpiPlugin\Gac`. The directory is named `plugin-gac` but the plugin key is `gac`.

It is an in-house plugin for the IT department of Grupo Aparício Carvalho (GAC, main education brand Fimca). **`gac` is an umbrella name** chosen because the scope is broad and company-specific; it is permanent in practice (folder, PHP functions, constants, namespace and DB table prefixes `glpi_plugin_gac_*`). Keep each feature as an isolated module inside the plugin so it does not become a grab bag.

## What exists today

One module is implemented: the **PRE (Protocolo de Reparo de Equipamento)**, in `src/Pre/`. It records equipment sent to a supplier for repair (a PRE has lines = ticket + asset), the per-line return (repaired, no fault found, unrepairable, quote rejected, lost), automatic closing, reopening with corrections, and a PDF of the protocol. Its design is in `docs/superpowers/specs/2026-09-24-pre-design.md` (decisions D1 to D18, the source of truth) and its implementation plan, executed task by task, in `docs/superpowers/plans/2026-09-24-pre-implementation.md`. `docs/pre-manual-tests.md` is the manual test script with the results of the last run and the send-timing measurements. **If the code diverges from the spec, one of them is wrong: fix it.**

The second module is the **LTBP (Laudo Técnico de Baixa Patrimonial)**, in `src/Ltbp/`. It prepares equipment for the write-off: the technician picks assets (candidates in the "Aguardando baixa" status, with their origin in the PRE, plus a free search), each with a reason from an editable catalog; the laudo is issued as a portrait PDF (frozen as a `Document`), signed on paper by the two directors, sent to the patrimony, the write-off is confirmed (assets go to the "baixado" status and are locked against edition), and the destination (disposal or donation) is completed with a beneficiary (a `Supplier`) and a proof. Returned PRE lines with destination Baixa are taken into a laudo by a batch button on the PRE items tab. Its design is in `docs/superpowers/specs/2026-09-26-ltbp-design.md` (decisions L1 to L25, the source of truth) and its plan in `docs/superpowers/plans/2026-09-26-ltbp-implementation.md`; `docs/ltbp-manual-tests.md` is the manual test script. **If the code diverges from the spec, one of them is wrong: fix it.**

The third module is the **Monitor** (painel de monitoramento de tickets), in `src/Monitor/`. It replaces the ticket-monitoring panel of the separate Django project (`morefunctionsforglpi`, `apps/panel` + `apps/dbcom`): one or more "Telas de Monitoramento" (`MonitorScreen`), each scoped to an entity (with an explicit recursive flag, never inherited from a session) and filtered by a shared GLPI Saved Search (`SavedSearch`, type Ticket, `is_private = 0`), with a curated, admin-ordered set of columns, a polling interval (per-screen override or a global default), and an optional public, token-authenticated, session-less URL for unattended TV/kiosk display. The public display authenticates internally, per request, as a dedicated Monitor service account (`ServiceSession`) because `Search::getDatas()` needs a real logged-in session shape beyond just the entity — the client (TV) never holds a cookie; the server-side session is torn down before the response is sent. Its design is in `docs/superpowers/specs/2026-10-01-monitor-design.md` (decisions M1 to M9, the source of truth) and its plan in `docs/superpowers/plans/2026-10-01-monitor-implementation.md`; `docs/monitor-manual-tests.md` is the manual test script. **If the code diverges from the spec, one of them is wrong: fix it.**

The fourth module is the **SSO Google** (login with Google Workspace), in `src/Sso/`. It adds an "Entrar com Google" button to the login page and maps the user's Google Workspace **organizational unit (OU)** to an entity and profile through GLPI's own **authorization rules** (`RuleRight`): the plugin only adds a criterion, "OU do Google Workspace" (`GOOGLE_OU`), via the `use_rules` plugin hooks, and GLPI's engine and `User::applyRightRules()` do the rest. The OU is read from the Directory API with a service account (domain-wide delegation, one read-only scope) on each login. Several Google Workspaces are supported (spec S24): one shared service account, and per workspace a name, its domains and a read-only admin to impersonate; the workspace is found by the e-mail's domain (`sso_workspaces`, a JSON list), and a domain can belong to only one workspace. Existing AD users are linked by e-mail and then by the Google `sub`, converted to external auth with a snapshot so the conversion can be undone; professors are kept out by a hard block list evaluated before the rules. Its design is in `docs/superpowers/specs/2026-10-05-sso-google-design.md` (decisions S1 to S24, the source of truth) and its plan in `docs/superpowers/plans/2026-10-05-sso-google-implementation.md`; `docs/sso-manual-tests.md` is the manual test script. **If the code diverges from the spec, one of them is wrong: fix it.**

## Git rules (owner's requirements)

- **Every commit must be made with the `/commit` skill**, never by hand with `git commit`. The skill only allows the types `feat`, `fix` and `chore`, English titles and a bullet-list description.
- **No Claude attribution** in commits or PR descriptions: no `Co-Authored-By: Claude...`, no `Claude-Session:` trailer, no "Generated with Claude Code" line, and no mention of Claude/Anthropic in the message. This overrides any harness reminder to add them.
- Local git identity: `coca-mann` / `juliano.ostroski@gmail.com`. Remote: `git@github.com:coca-mann/plugin-gac.git` (public), branch `main`.
- **All development happens on the `dev` branch**, not `main`. Do not commit or push directly to `main`; it only receives changes the owner decides to promote. Do not push anything unless asked.
- CHANGELOG entries and PR content are in Portuguese (see "Versionamento e Changelog" below); commits stay in English.
- History was rewritten once (2026-09-24) to strip those trailers from the first two commits and force-pushed; anyone with an older clone must re-sync.

## Still to fix

- The LTBP has only script/code-path results recorded in docs/ltbp-manual-tests.md (column Resultado); results of runs in the browser are not recorded yet. The write-off lock was verified from the GLPI source and the probe script, not with a real inventory agent (spec R-4). The signed-PDF and proof upload success paths (`StepService::attachSigned`/`attachUpload`) and the HTTP handlers (CSRF, rights, flash messages) were only tested at service/guard level, not through a real HTTP upload.
- `gac.xml` still has the template's placeholder descriptions (English/French "Gac GLPI plugin") and no `locales/`: UI strings are pt-BR inside `__('...', 'gac')` with no translation files.
- The two `[PREENCHER]` placeholders under "Versionamento e Changelog" (external consumers, changelog language) are unfilled, as is the "Adaptação" block of `docs/versioning.md`. In practice the changelog and PRs are in Portuguese, commits in English, and there is no external consumer (classic semver).
- Git converts LF to CRLF on checkout here (no `.gitattributes`); harmless so far (blobs are LF), consider a `.gitattributes` if it causes trouble.
- The Monitor module's manual tests (docs/monitor-manual-tests.md) were run with the GLPI demo account `tech`/`tech` as the public display's service account, not a dedicated one — before relying on this in production, create a real service account with read-only ticket rights, scoped to the entities the public Telas actually use. The alert sound itself (audio playback) was not tested — the plugin ships no bundled sound file (decision M8); it only works once `monitor_alert_sound_url` is configured.
- The SSO module (released in `0.7.0`) was tested with real accounts of two Google Workspaces on the local GLPI (`http://localhost:8080/`); the results of each run are recorded in `docs/sso-manual-tests.md`, which also lists what was not exercised yet (a real foreign-domain account, a real AD import, a real password login after unfolding the form, phone width, the "already logged in" part of turning the module off). It has **not** run in production: that needs the HTTPS redirect URI registered in the Google OAuth client, the same configuration done there, then the pilot mode with IT before opening it up. The local GLPI's SSO configuration and test users/rules are throwaway and must not be copied to production. A browser session opened before the module was installed must change profile (or log in again) to see the new right.

## Dev environment

- Local GLPI 11.0.8 (XAMPP) at `C:\Users\juliano\VSCode\glpi-xampp-dev-plugin`, served at `http://glpi11local.test/`. The plugin is tested through a directory junction `plugins\gac` -> this directory (the junction name must equal the plugin key). Its DB is on **`127.0.0.1:3307`** (not the default port); host, user, database and password are in its `config/config_db.php`.
- That GLPI is a **release package, not a dev checkout**: it lacks `PluginsMakefile.mk`, `PluginsRector.php`, `stubs/`, `tests/bootstrap.php` and the phpstan/phpunit vendor packages. So `make`, Rector, PHPStan and the GLPI-bound PHPUnit suite configured in this repo do **not** run there. Testing is **manual** in that GLPI, plus a standalone unit suite for the pure classes (below). A second GLPI cloned from `glpi-project/glpi` (branch 11.0/bugfixes) is the path if automated GLPI tests/static analysis are wanted.
- `php` is not on PATH: use `/c/xampp/php/php.exe` (PHP 8.2). `composer` and `rsync` are not on PATH either. Install/activate/list plugins with `bin/console` from the GLPI folder: `plugin:install gac --username=glpi`, `plugin:activate gac`, `plugin:list`.
- **Standalone unit tests** (pure classes only, no GLPI): `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`, harness in `tests/Unit/` (own autoloader, no GLPI). `var/` is git-ignored: fetch the tools there when missing, with `curl -sSL --ssl-no-revoke -o var/tools/phpunit.phar https://phar.phpunit.de/phpunit-11.phar` and `.../composer.phar` from `https://getcomposer.org/download/latest-stable/composer.phar` (`--ssl-no-revoke` is needed on this Windows for curl).
- **Gotchas of this GLPI** (all hit during development):
  - It runs in `production` mode and does **not** recompile Twig: after editing a `.twig`, run `bin/console cache:clear`.
  - `public/js/pre.js` is served as `pre.js?v=<hash>` cached for 30 days; the hash changes only with the plugin version, so force-refresh the file in the browser after JS edits.
  - A user's profile rights are loaded at login: a session opened before the plugin was installed gets 403 until `POST /Session/ChangeProfile` (or a new login).
  - Plugin pages live under `/plugins/gac/front/...`; AJAX under `/plugins/gac/ajax/...`.
- Test data created by a bare `POST` to a GLPI form skips the form's default values: a supplier created that way has `is_active = 0` (the column default) and **never appears in any supplier dropdown**, because GLPI lists only active suppliers. Send `is_active=1` (or create it through the UI) when seeding suppliers; check other flags the same way if a seeded record does not show up.
- Test data in that GLPI is throwaway (many `NB-LOAD-*` computers/tickets, "Marca A/B" entities, sample logo and CNPJ on the root entity).

## Tooling configured (most needs a GLPI dev checkout to run)

Configs reference GLPI by relative path (`../../vendor`, `../../src`, `../../PluginsMakefile.mk`, `../../PluginsRector.php`, `../../../tests/bootstrap.php`), so they only work with the plugin located at `<glpi>/plugins/gac/` inside a full GLPI checkout.

- `Makefile` is just `include ../../PluginsMakefile.mk`; targets come from GLPI.
- PHPStan (`phpstan.neon`, level max, glpi/deprecation/safe rules) and Psalm (`psalm.xml`, taint analysis) over `src`, `hook.php`, `setup.php`.
- PHP-CS-Fixer `@PER-CS` (`.php-cs-fixer.php`, cache in `var/`); Twig-CS-Fixer with `GlpiTwigRuleset` over `templates/*.html.twig`; Rector via GLPI's baseline over `src` and `tests`.
- `phpunit.xml` (GLPI-bound, `tests/bootstrap.php` throws if the plugin is not active in the test database) is **separate** from `phpunit.unit.xml` (standalone, the one that runs here).
- JS/CSS lint configs (eslint, stylelint) were intentionally not kept; re-add from the upstream template if needed.

## Plugin structure conventions

- `setup.php`: `PLUGIN_GAC_VERSION` and min (inclusive) / max (exclusive) GLPI version constants (11.0.0 to 11.0.99), `plugin_init_gac()` (own top-level sidebar menu named after the plugin (`GacMenu`, sector `gac`; modules add their entries there and their pages pass `GacMenu::SECTOR` + the entry key to `Html::header()`), config page, JS, one profile-rights tab), `plugin_version_gac()`, `plugin_gac_check_prerequisites()` (refuses to activate without `vendor/`), `plugin_gac_check_config()`.
- **Rights**: one rights row per feature, registered in `Features::all()` (`src/Features.php`) and shown together in the single profile tab (`ProfileRights`). Every feature's right includes the `Features::RIGHT_CONFIG` ("Configurar") bit, which gates that feature's `ConfigSection` (`canConfigure()`); the sidebar menu shows only entries the user has a right for. A new module adds its row to `Features::all()`, its section to `Config::sections()` and its entry to `GacMenu::getMenuContent()`.
- `hook.php`: `plugin_gac_install()` / `plugin_gac_uninstall()`, idempotent (every step checks current state). GLPI does **not** re-run install if the version is unchanged: bump `PLUGIN_GAC_VERSION` (and `gac.xml`) when install logic changes, or reinstall in dev.
- `gac.xml`: marketplace metadata; keep versions in sync with `PLUGIN_GAC_VERSION` (the release script fails if they differ).
- `tools/HEADER`: license header template; every new PHP file starts with it (copy the docblock from `setup.php`).
- Layout of the PRE module: pure, GLPI-free rules in `src/Pre/` (`StateMachine`, `PreSettings`, `ReturnActionResolver`, `ProtocolNumber`, `CostLabel`, `TicketObservationExtractor`, enums) with tests in `tests/Unit/`; GLPI-bound classes next to them (`RepairProtocol`, `RepairProtocolItem`, `RepairProtocolEvent`, the `*Service` classes, `PdfRenderer`). Twig in `templates/pre/` (referenced as `@gac/...`), pages in `front/pre/`, AJAX in `ajax/`, JS in `public/js/`. The plugin config page is `src/Config.php` with one `ConfigSection` per module (`PreConfigSection`); PRE settings live in `glpi_configs` under the `plugin:gac` context with `pre_*` keys.
- **Layout of the LTBP module and `Shared`:** pure rules in `src/Ltbp/` (`Status`, `Destination`, `StateMachine`, `LtbpNumber`, `LtbpSettings`, `EmissionValidator`, `LockPolicy`, `PlaceDate`, `TicketSolvePolicy`) with tests in `tests/Unit/`; GLPI-bound classes next to them (`Ltbp`, `LtbpItem`, `LtbpEvent`, `LtbpReason`, the `*Service` classes, `LtbpLinker`, `PdfRenderer`, `AssetUpdateGuard`). Twig in `templates/ltbp/`, pages in `front/ltbp/`, tables `glpi_plugin_gac_ltbp*`, settings `ltbp_*`. The pieces both modules use (`EntityChain`, `LogoFit`, `LogoLocator`, `EventMessage`, `ReportFormatter`, `ServiceResult`, `StateGuard`, `TicketOps`, `MpdfLoader`, `DocumentStore`) live in `src/Shared/`. The modules only read each other's tables (LTBP reads the PRE lines; the PRE reads `Ltbp::activeLaudoFor()` and `LtbpLinker::openDrafts()`); the LTBP never writes to the PRE.
- **Write-off lock:** `AssetUpdateGuard` is registered on `pre_item_update` for every asset class in `setup.php` and refuses updates to an asset in a laudo that is Baixado or Concluído (except comments), unless the profile has the "Editar ativo baixado" right. The module's own status writes run inside `LtbpGuard::run()`.
- **Layout of the Monitor module:** pure rules in `src/Monitor/` (`ColumnCatalog`, `MonitorSettings`, `PublicToken`, `ElapsedTimeLabel`, `TicketSortOrder`) with tests in `tests/Unit/`; GLPI-bound classes next to them (`MonitorScreen`, `MonitorLabels`, `MonitorMenu`, `MonitorConfig`, `MonitorConfigSection`, `ScreenQuery`, `ServiceSession`). Twig in `templates/monitor/`, authenticated pages in `front/monitor/`, the public (session-less) page and its AJAX endpoint are separate files (`public.php`, `public_data.php`) registered as stateless routes in `setup.php` — never add a token branch to the authenticated `display.php`/`data.php` instead, `registerPluginStatelessPath()` matches by path, not by query string. `ScreenQuery::run()` is the only place that calls `Search::getDatas('Ticket', ...)`; see the spec (R-1, R-3) before changing it — forcing just the entity in `$_SESSION` is not enough (also needs `glpishowallentities = 0`), and the public path needs a full logged-in session shape (`ServiceSession`), not just entity keys. Tables `glpi_plugin_gac_monitorscreens`, settings `monitor_*`.
- **Layout of the SSO module:** pure rules in `src/Sso/` (`OuPath`, `OuBlocklist`, `DomainPolicy`, `IdToken`, `IdentityMatch`/`IdentityMatcher`, `LoginDecision`, `RuleResult`, `SsoSettings`, `ServiceAccountJwt`, `Outcome`, `Workspace`/`WorkspaceRegistry`) with tests in `tests/Unit/`; GLPI-bound classes next to them (`SsoIdentity`, `SsoEvent`, `SsoConfig`, `RuleHooks`, `RuleRunner`, `GoogleClient`, `GooglePkceProvider`, `DirectoryClient`, `UserProvisioner`, `SessionStarter`, `LoginService`, `SsoLoginButton`, `SsoConfigSection`, `DryRun`, `SsoPages`). Pages in `front/sso/`, AJAX in `ajax/sso/`, tables `glpi_plugin_gac_ssoidentities`/`ssoevents`, settings `sso_*` (the client secret and the service account key are in `Hooks::SECURED_CONFIGS`). `start.php` and `callback.php` are registered with `Firewall::STRATEGY_NO_CHECK` and use a **real session** (the OAuth state lives in `$_SESSION['gac_sso']`, read and dropped before `Session::init()` replaces the session), unlike the Monitor's stateless public page. PKCE in `league/oauth2-client` is not a constructor option: it is enabled by overriding `getPkceMethod()` (see `GooglePkceProvider`). Authorization-rule criteria for the OU use only the condition "é" (the engine's "começa com" does not work with slashes). `SsoSettings::normalize()` fills every missing key with its default, so `SsoConfig::save()` merges over the current settings first.
- **Because classes live in the `Pre` sub-namespace**, GLPI derives wrong names: every data class overrides `getTable()`, every search column of the PRE table declares `'itemtype' => self::class`, and front files sit in `front/pre/` (GLPI turns the sub-namespace into that path). Do the same for any new sub-namespaced module.
- AJAX endpoints must **not** call `Session::checkCSRF()`: the GLPI 11 kernel validates the `X-Glpi-Csrf-Token` header of XHR requests before the script runs.
- Static assets go under `public/`; the `add_javascript` hook value has no `public/` prefix.
- User-facing strings are Portuguese (pt-BR), wrapped in `__('...', 'gac')`. The report PDF uses `dd/mm/aaaa`.
- Coverage is off by default; enable via `.glpi-coverage.json`.

## PDF and release

- The PDF is rendered with **mPDF** (`composer.json` requires `mpdf/mpdf`; `composer.lock` is committed). `vendor/` is git-ignored and only exists in dev after `composer install` and in the release package. `tools/render-report-sample.php` renders a sample report without GLPI.
- Release (owner's own workflow, not the template's): pushing a tag `v<version>` runs `.github/workflows/release.yml`, which calls `tools/build-release.sh` and publishes `dist/gac-<version>.zip` (top folder `gac/`, `vendor/` included, mPDF fonts reduced to DejaVu Sans, dev files excluded via `.release-exclude`, fails if the tag differs from `PLUGIN_GAC_VERSION` or `gac.xml`). Locally: `COMPOSER_BIN="/c/xampp/php/php.exe $(pwd)/var/tools/composer.phar" bash tools/build-release.sh` (the variable is `COMPOSER_BIN` because Composer itself reads `COMPOSER`). Creating the tag and publishing is the owner's decision; never do it unasked.
- The template's own workflows (CI matrix, auto-tag on `setup.php` change) stay **removed on purpose**; they can be restored from the first commit (`deec264`, as `.tpl` files). `.github/dependabot.yml` was also removed on purpose (it targeted `main`, had an `npm` block with no `package.json`, and a silent mPDF bump could break the PDF); it can be restored from the template's first commit. Upstream template changes can be pulled with [template-sync](https://github.com/coopTilleuls/template-sync) against `https://github.com/pluginsGLPI/empty`.

## GLPI developer documentation

`docs/glpi-developer-documentation.md` is the official Teclib' developer documentation (dated Aug 18, 2026, covers GLPI 11.0; converted from PDF, so headings and code listings are rough). It is ~11.8k lines (344 KB): **do not read it whole**; grep for the topic and read only that range. Consult it before writing plugin code instead of relying on memory of GLPI APIs. Approximate line ranges (verify with grep, they shift if the file is edited):

- Coding standards (ch. 2): ~423-690.
- Developer API (ch. 3): framework objects ~695, database/DBmysqlIterator ~877, search engine ~1724, controllers ~2439, translations ~4251, right management ~4350, automatic actions/cron ~4996, logging ~5159.
- **Plugins (ch. 5), the most relevant part:** guidelines ~5510, requirements (setup.php/hook.php contract) ~5661, database (install/migrations) ~5982, adding and managing objects (CommonDBTM, forms, search options, Twig) ~6132, hooks ~6343, controllers/routes ~7053, sudo mode ~7207, automatic actions ~7485, massive actions ~7505, tips & tricks ~7606, notifications ~7772, unit testing ~8183, full step-by-step tutorial ~8256, JS ~11156.
- Upgrade guide to GLPI 11.0: ~11569.
- Chapter 6 (packaging for distributions: Apache, SELinux, FHS) is not relevant to this plugin.

## Versionamento e Changelog

Ao analisar um diff/PR para decidir o bump de versão, siga estritamente os
critérios definidos em `docs/versioning.md`. Não infira a regra a cada vez —
consulte o documento.

Consumidor(es) externo(s) deste projeto: `[PREENCHER — ex. frontend
separado, app mobile, API pública, ou "nenhum"]`.

### Critérios de bump

- **MAJOR**: qualquer alteração em algo já consumido pelo(s) consumidor(es)
  externo(s) hoje (campo, endpoint, status code, comportamento de auth) —
  independente do tamanho da mudança. Exceção: se o PR indicar
  explicitamente deploy conjunto com o consumidor (checkbox correspondente
  marcado no template de PR), o bump pode ser MINOR ou PATCH conforme a
  natureza da mudança. Se este projeto não tiver consumidor externo, usar o
  critério clássico de semver: qualquer quebra de compatibilidade para quem
  usa o software diretamente.
- **MINOR**: funcionalidade nova, aditiva, que não altera nem remove
  contrato existente.
- **PATCH**: correção que não altera contrato existente.

Antes de decidir, verifique explicitamente: esta mudança altera algo que o
consumidor externo já usa? Se sim e não houver evidência de deploy conjunto,
o bump é MAJOR, mesmo que a mudança pareça pequena (ex: renomear um campo é
MAJOR, mesmo sendo uma linha). Se a resposta for ambígua, sinalize isso no
PR em vez de decidir sozinho.

### Geração da entrada de changelog

Ao decidir o bump, gere também a entrada de changelog correspondente, no
mesmo passo, categorizada segundo Keep a Changelog: `Added`, `Changed`,
`Fixed`, `Security` (usar apenas as categorias aplicáveis).

**Idioma**: `[PREENCHER — ex. "os commits deste projeto são em inglês, mas o
CHANGELOG.md e o conteúdo do PR são sempre em português"]`. Se idioma dos
commits for diferente do idioma do changelog, não copie nem traduza
literalmente a mensagem do commit para dentro da entrada — reescreva a
entrada no idioma correto, do ponto de vista do que muda para quem consome o
sistema, não do ponto de vista do código alterado.

Exemplo (ajustar idiomas conforme preenchido acima):
- Commit: `fix: correct null check in payment validation`
- Entrada correta: "Corrigido erro que permitia validação de pagamento com
  dado nulo."
- Entrada incorreta: "Fix: correct null check in payment validation."
  (cópia/tradução literal do commit)

### Preenchimento do PR

Ao abrir ou atualizar um PR, preencha a seção "Changelog" do template com
a entrada gerada, e marque o checkbox de tipo de mudança
(Fix/Feature/Breaking change) correspondente ao bump decidido. Preencha
também a seção "Impacto no contrato consumido externamente" — se não houver
impacto, declare isso explicitamente ("Nenhum"), não deixe em branco.
