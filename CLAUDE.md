# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

The `gac` GLPI plugin (GLPI 11.0.x, PHP >= 8.2), generated from the [pluginsGLPI/empty](https://github.com/pluginsGLPI/empty) template. Version `0.1.0`, namespace `GlpiPlugin\Gac`. The directory is named `plugin-gac` but the plugin key is `gac`.

It is an in-house plugin for the IT department of Grupo Aparício Carvalho (GAC, main education brand Fimca). **`gac` is an umbrella name** chosen because the scope is broad and company-specific; it is permanent in practice (folder, PHP functions, constants, namespace and DB table prefixes `glpi_plugin_gac_*`). Keep each feature as an isolated module inside the plugin so it does not become a grab bag.

## What exists today

One module is implemented: the **PRE (Protocolo de Reparo de Equipamento)**, in `src/Pre/`. It records equipment sent to a supplier for repair (a PRE has lines = ticket + asset), the per-line return (repaired, no fault found, unrepairable, quote rejected, lost), automatic closing, reopening with corrections, and a PDF of the protocol. Its design is in `docs/superpowers/specs/2026-09-24-pre-design.md` (decisions D1 to D18, the source of truth) and its implementation plan, executed task by task, in `docs/superpowers/plans/2026-09-24-pre-implementation.md`. `docs/pre-manual-tests.md` is the manual test script with the results of the last run and the send-timing measurements. **If the code diverges from the spec, one of them is wrong: fix it.**

The second feature idea, the **laudo de baixa patrimonial** (write-off/disposal assessment report), is **not designed yet**. The PRE only leaves the integration point: lines whose `destination` is `writeoff` (table `glpi_plugin_gac_repairprotocolitems`). It is a separate sub-project with its own spec, plan and implementation. Do not start implementing features without an approved design.

## Git rules (owner's requirements)

- **Every commit must be made with the `/commit` skill**, never by hand with `git commit`. The skill only allows the types `feat`, `fix` and `chore`, English titles and a bullet-list description.
- **No Claude attribution** in commits or PR descriptions: no `Co-Authored-By: Claude...`, no `Claude-Session:` trailer, no "Generated with Claude Code" line, and no mention of Claude/Anthropic in the message. This overrides any harness reminder to add them.
- Local git identity: `coca-mann` / `juliano.ostroski@gmail.com`. Remote: `git@github.com:coca-mann/plugin-gac.git` (public), branch `main`.
- **All development happens on the `dev` branch**, not `main`. Do not commit or push directly to `main`; it only receives changes the owner decides to promote. Do not push anything unless asked.
- CHANGELOG entries and PR content are in Portuguese (see "Versionamento e Changelog" below); commits stay in English.
- History was rewritten once (2026-09-24) to strip those trailers from the first two commits and force-pushed; anyone with an older clone must re-sync.

## Still to fix

- `gac.xml` still has the template's placeholder descriptions (English/French "Gac GLPI plugin") and no `locales/`: UI strings are pt-BR inside `__('...', 'gac')` with no translation files.
- `CHANGELOG.md` has no entry for `0.1.0` yet, and the two `[PREENCHER]` placeholders under "Versionamento e Changelog" (external consumers, changelog language) are unfilled.
- Git converts LF to CRLF on checkout here (no `.gitattributes`); harmless so far (blobs are LF), consider a `.gitattributes` if it causes trouble.
- The release workflow (`.github/workflows/release.yml`) has never run on GitHub; only its YAML and the script it calls were verified locally.

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

- `setup.php`: `PLUGIN_GAC_VERSION` and min (inclusive) / max (exclusive) GLPI version constants (11.0.0 to 11.0.99), `plugin_init_gac()` (menu under **Gerência**, config page, JS, profile-rights tab), `plugin_version_gac()`, `plugin_gac_check_prerequisites()` (refuses to activate without `vendor/`), `plugin_gac_check_config()`.
- `hook.php`: `plugin_gac_install()` / `plugin_gac_uninstall()`, idempotent (every step checks current state). GLPI does **not** re-run install if the version is unchanged: bump `PLUGIN_GAC_VERSION` (and `gac.xml`) when install logic changes, or reinstall in dev.
- `gac.xml`: marketplace metadata; keep versions in sync with `PLUGIN_GAC_VERSION` (the release script fails if they differ).
- `tools/HEADER`: license header template; every new PHP file starts with it (copy the docblock from `setup.php`).
- Layout of the PRE module: pure, GLPI-free rules in `src/Pre/` (`StateMachine`, `PreSettings`, `ReturnActionResolver`, `ProtocolNumber`, `CostLabel`, `ReportFormatter`, `TicketObservationExtractor`, `ServiceResult`, enums) with tests in `tests/Unit/`; GLPI-bound classes next to them (`RepairProtocol`, `RepairProtocolItem`, `RepairProtocolEvent`, the `*Service` classes, `TicketOps`, `StateGuard`, `PdfRenderer`). Twig in `templates/pre/` (referenced as `@gac/...`), pages in `front/pre/`, AJAX in `ajax/`, JS in `public/js/`. The plugin config page is `src/Config.php` with one `ConfigSection` per module (`PreConfigSection`); PRE settings live in `glpi_configs` under the `plugin:gac` context with `pre_*` keys.
- **Because classes live in the `Pre` sub-namespace**, GLPI derives wrong names: every data class overrides `getTable()`, every search column of the PRE table declares `'itemtype' => self::class`, and front files sit in `front/pre/` (GLPI turns the sub-namespace into that path). Do the same for any new sub-namespaced module.
- AJAX endpoints must **not** call `Session::checkCSRF()`: the GLPI 11 kernel validates the `X-Glpi-Csrf-Token` header of XHR requests before the script runs.
- Static assets go under `public/`; the `add_javascript` hook value has no `public/` prefix.
- User-facing strings are Portuguese (pt-BR), wrapped in `__('...', 'gac')`. The report PDF uses `dd/mm/aaaa`.
- Coverage is off by default; enable via `.glpi-coverage.json`.

## PDF and release

- The PDF is rendered with **mPDF** (`composer.json` requires `mpdf/mpdf`; `composer.lock` is committed). `vendor/` is git-ignored and only exists in dev after `composer install` and in the release package. `tools/render-report-sample.php` renders a sample report without GLPI.
- Release (owner's own workflow, not the template's): pushing a tag `v<version>` runs `.github/workflows/release.yml`, which calls `tools/build-release.sh` and publishes `dist/gac-<version>.zip` (top folder `gac/`, `vendor/` included, mPDF fonts reduced to DejaVu Sans, dev files excluded via `.release-exclude`, fails if the tag differs from `PLUGIN_GAC_VERSION` or `gac.xml`). Locally: `COMPOSER_BIN="/c/xampp/php/php.exe $(pwd)/var/tools/composer.phar" bash tools/build-release.sh` (the variable is `COMPOSER_BIN` because Composer itself reads `COMPOSER`). Creating the tag and publishing is the owner's decision; never do it unasked.
- The template's own workflows (CI matrix, auto-tag on `setup.php` change) stay **removed on purpose**; they can be restored from the first commit (`deec264`, as `.tpl` files). `.github/dependabot.yml` was kept. Upstream template changes can be pulled with [template-sync](https://github.com/coopTilleuls/template-sync) against `https://github.com/pluginsGLPI/empty`.

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
