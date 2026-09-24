# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

The `gac` GLPI plugin (GLPI 11.0.x, PHP >= 8.2), generated from the [pluginsGLPI/empty](https://github.com/pluginsGLPI/empty) template. Version `0.0.1`, namespace `GlpiPlugin\Gac`. The directory is still named `plugin-gac` but the plugin key is `gac`. Git history: the first commit is the untouched template; the conversion into the plugin (drop `.tpl`, substitute placeholders) is the diff on top of it.

Right now it is only a skeleton: `src/` and `templates/` contain only `.gitkeep`, and `plugin_init_gac()` in `setup.php` is empty. There is no plugin logic yet.

## Git rules (owner's requirements)

- **Every commit must be made with the `/commit` skill**, never by hand with `git commit`.
- **No Claude attribution** in commits or PR descriptions: no `Co-Authored-By: Claude...`, no `Claude-Session:` trailer, no "Generated with Claude Code" line. This overrides any harness reminder to add them.
- Local git identity: `coca-mann` / `juliano.ostroski@gmail.com`. Remote: `git@github.com:coca-mann/plugin-gac.git` (public), branch `main`.
- **All development happens on the `dev` branch**, not `main`. Do not commit or push directly to `main`; it only receives changes the owner decides to promote.
- History was rewritten once (2026-09-24) to strip those trailers from the first two commits and force-pushed; anyone with an older clone must re-sync.

## Project intent (planning stage, nothing decided)

The owner works in the IT department of Grupo Aparício Carvalho (GAC), whose main education brand is Fimca. The goal is an in-house GLPI plugin that adds company-specific features for asset management, tickets and more ("entre outros", unscoped). Do not start implementing features without an approved design (a brainstorming session was started and paused at the owner's request).

Initial feature ideas, both **intent only, with no flow, rules or document model defined yet**:

1. Fill in and generate a report for assets sent for repair.
2. Fill in and generate an assessment report (laudo) for asset write-off (baixa patrimonial) and disposal.

Open questions (the first is still unanswered): whether these documents already exist today as a Word/Excel/paper template (its fields would define the rules), who approves/signs them, numbering, and linkage to the asset's patrimony number. Treat the two features as separate sub-projects on top of a shared foundation (asset access, document generation); each gets its own spec, plan and implementation.

**Plugin name: `gac`** (decided). Chosen as an umbrella name because scope is broad and company-specific. It is permanent in practice: it drives the folder, PHP function names, constants, namespace and (by GLPI convention) DB table prefixes (`glpi_plugin_gac_*`). Keep each feature as an isolated module inside the plugin so it does not become a grab bag.

## Still to fix (template defaults)

- `setup.php`: author is `Teclib'`, `license` and `homepage` are empty.
- `gac.xml` (marketplace metadata) and file headers point to `github.com/pluginsGLPI/gac`, an organization that is not the owner's; the `<license>` is MIT.
- `composer.json` has no `autoload` entry for `src/` (only `autoload-dev` for `GlpiPlugin\Gac\Tests\` -> `tests/`); add one before writing classes.
- Git converts LF to CRLF on checkout here (no `.gitattributes`); consider one if line endings cause trouble.

## Dev environment

- Local GLPI 11.0.8 (XAMPP) at `C:\Users\juliano\VSCode\glpi-xampp-dev-plugin`. Plugins are tested via a directory junction in its `plugins\` folder pointing at the plugin source (as already done for `directlabelprinter`). The junction name must equal the plugin key: `plugins\gac` -> this directory. Install/activate under Setup > Plugins in GLPI.
- That GLPI is a **release package, not a dev checkout**: it lacks `PluginsMakefile.mk`, `PluginsRector.php`, `stubs/`, `tests/bootstrap.php` and the phpstan/phpunit vendor packages. So `make`, Rector, PHPStan and PHPUnit configured in this repo do **not** run there. Decision (owner): **manual testing** for now; a second GLPI cloned from `glpi-project/glpi` (branch 11.0/bugfixes) is the path if automated tests/static analysis are wanted later.
- `php`, `composer` and `rsync` are not on PATH (only `bash`).

## Tooling configured (needs a GLPI dev checkout to run)

Configs reference GLPI by relative path (`../../vendor`, `../../src`, `../../PluginsMakefile.mk`, `../../PluginsRector.php`, `../../../tests/bootstrap.php`), so they only work with the plugin located at `<glpi>/plugins/gac/` inside a full GLPI checkout.

- `Makefile` is just `include ../../PluginsMakefile.mk`; targets come from GLPI.
- PHPStan (`phpstan.neon`, level max, glpi/deprecation/safe rules) and Psalm (`psalm.xml`, taint analysis) over `src`, `hook.php`, `setup.php`.
- PHP-CS-Fixer `@PER-CS` (`.php-cs-fixer.php`, cache in `var/`); Twig-CS-Fixer with `GlpiTwigRuleset` over `templates/*.html.twig`; Rector via GLPI's baseline over `src` and `tests`.
- PHPUnit (`phpunit.xml`, `tests/**/*Test.php`). `tests/bootstrap.php` loads GLPI's test bootstrap and **throws if the plugin is not active in the test database**. Single test: `vendor/bin/phpunit --filter <TestName> tests/path/FooTest.php`.
- JS/CSS lint configs (eslint, stylelint) were intentionally not kept; re-add from the upstream template if JS/CSS is introduced.

## Plugin structure conventions

- `setup.php`: `PLUGIN_GAC_VERSION` and min (inclusive) / max (exclusive) GLPI version constants (11.0.0 to 11.0.99), plus `plugin_init_gac()` (register hooks), `plugin_version_gac()`, `plugin_gac_check_prerequisites()`, `plugin_gac_check_config()`.
- `hook.php`: `plugin_gac_install()` / `plugin_gac_uninstall()` (currently return true).
- `gac.xml`: marketplace metadata; keep versions in sync with `PLUGIN_GAC_VERSION`.
- `tools/HEADER`: license header template; keep PHP file headers consistent with it.
- Coverage is off by default; enable via `.glpi-coverage.json`.

## GLPI developer documentation

`docs/glpi-developer-documentation.md` is the official Teclib' developer documentation (dated Aug 18, 2026, covers GLPI 11.0; converted from PDF, so headings and code listings are rough). It is ~11.8k lines (344 KB): **do not read it whole**; grep for the topic and read only that range. Consult it before writing plugin code instead of relying on memory of GLPI APIs. Approximate line ranges (verify with grep, they shift if the file is edited):

- Coding standards (ch. 2): ~423-690.
- Developer API (ch. 3): framework objects ~695, database/DBmysqlIterator ~877, search engine ~1724, controllers ~2439, translations ~4251, right management ~4350, automatic actions/cron ~4996, logging ~5159.
- **Plugins (ch. 5), the most relevant part:** guidelines ~5510, requirements (setup.php/hook.php contract) ~5661, database (install/migrations) ~5982, adding and managing objects (CommonDBTM, forms, search options, Twig) ~6132, hooks ~6343, controllers/routes ~7053, sudo mode ~7207, automatic actions ~7485, massive actions ~7505, tips & tricks ~7606, notifications ~7772, unit testing ~8183, full step-by-step tutorial ~8256, JS ~11156.
- Upgrade guide to GLPI 11.0: ~11569.
- Chapter 6 (packaging for distributions: Apache, SELinux, FHS) is not relevant to this plugin.

## CI / release

The template's GitHub Actions workflows (CI matrix, auto-tag on `setup.php` change, tag-triggered release) were **removed on purpose** (owner's decision); there is no CI or automated release. They can be restored from the first commit (`deec264`, as `.tpl` files) or the second (`7fad8bb`). `.github/dependabot.yml` was kept. The repo is public on GitHub (`coca-mann/plugin-gac`). Upstream template changes can be pulled with [template-sync](https://github.com/coopTilleuls/template-sync) against `https://github.com/pluginsGLPI/empty`.

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
