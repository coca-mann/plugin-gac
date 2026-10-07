<?php

// Lista de OUs de cada workspace usável: leitura real no Google (spec S28), cache e falha isolada.
require __DIR__ . '/boot.php';

use GlpiPlugin\Gac\Sso\OrgUnitDirectory;
use GlpiPlugin\Gac\Sso\OrgUnitList;
use GlpiPlugin\Gac\Sso\SsoConfig;
use GlpiPlugin\Gac\Sso\SsoSettings;
use GlpiPlugin\Gac\Sso\Workspace;

global $GLPI_CACHE;

$settings = SsoConfig::load();
foreach (SsoSettings::workspaces($settings)->usable() as $workspace) {
    $GLPI_CACHE->delete('gac_sso_orgunits_' . $workspace->key);

    $t0    = microtime(true);
    $first = OrgUnitDirectory::forWorkspace($settings, $workspace, false);
    $ms    = (int) ((microtime(true) - $t0) * 1000);

    if ($first['paths'] === null) {
        // Escopo não delegado ou papel sem leitura de OUs naquele workspace: avisa, não derruba.
        echo "AVISO  {$workspace->key}: {$first['error']}\n";
        continue;
    }

    check($first['paths'] !== [], "{$workspace->key}: " . count($first['paths']) . " OUs lidas em {$ms} ms");
    check(!in_array('/', $first['paths'], true) && $first['paths'] === array_values(array_unique($first['paths'])), "{$workspace->key}: sem raiz e sem repetidas");

    $t0     = microtime(true);
    $second = OrgUnitDirectory::forWorkspace($settings, $workspace, false);
    $cached = (int) ((microtime(true) - $t0) * 1000);
    check($second['paths'] === $first['paths'] && $cached < 100, "{$workspace->key}: segunda leitura veio do cache ({$cached} ms)");
    check(OrgUnitList::unpack($GLPI_CACHE->get('gac_sso_orgunits_' . $workspace->key)) !== null, "{$workspace->key}: envelope do cache válido");

    $forced = OrgUnitDirectory::forWorkspace($settings, $workspace, true);
    check($forced['paths'] === $first['paths'], "{$workspace->key}: \"atualizar\" ignora o cache e devolve a mesma lista");
}

// Um workspace com administrador inexistente falha sozinho, sem exceção.
$bogus  = new Workspace('gac-bogus', 'Bogus', ['bogus.example'], 'ninguem@bogus.example', true);
$result = OrgUnitDirectory::forWorkspace($settings, $bogus, true);
check($result['paths'] === null && $result['error'] !== '', 'administrador inexistente: erro legível, sem exceção (' . $result['error'] . ')');
check($GLPI_CACHE->get('gac_sso_orgunits_gac-bogus') === null, 'falha não é guardada no cache');
check(!str_contains($result['error'], 'PRIVATE KEY') && !str_contains($result['error'], 'access_token'), 'a mensagem de erro não vaza chave nem token');

finish();
