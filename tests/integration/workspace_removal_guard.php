<?php

// Varredura das regras que usam um workspace e recusa do salvamento (spec S27).
// Cria regras GACTEST, guarda e restaura a configuração dos workspaces e apaga tudo no fim.
require __DIR__ . '/boot.php';

use GlpiPlugin\Gac\Sso\SsoConfig;
use GlpiPlugin\Gac\Sso\SsoConfigSection;
use GlpiPlugin\Gac\Sso\SsoSettings;
use GlpiPlugin\Gac\Sso\WorkspaceRuleUsage;

$ids      = [];
$original = SsoConfig::load()['sso_workspaces'];

try {
    $crit = static fn (string $key, int $cond = Rule::PATTERN_IS): array => [['GOOGLE_WORKSPACE', $cond, $key]];
    $act  = [['entities_id', 'assign', 0], ['profiles_id', 'assign', 1]];

    $active   = make_rule('ativa', $crit('gac-a'), $act, 9200);
    $inactive = make_rule('desativada', $crit('gac-a'), $act, 9201, 'RuleRight', false);
    $isNot    = make_rule('nao e', $crit('gac-a', Rule::PATTERN_IS_NOT), $act, 9202);
    $other    = make_rule('outra chave', $crit('gac-b'), $act, 9203);
    $ticket   = make_rule('outro tipo', $crit('gac-a'), [], 9204, 'RuleTicket');
    $deleted  = make_rule('apagada', $crit('gac-a'), $act, 9205);
    $ids      = [$active, $inactive, $isNot, $other, $ticket, $deleted];
    drop_rule($deleted);

    $idsFor = static fn (array $keys): array => array_values(array_unique(array_map(
        static fn (array $r): int => $r['rule_id'],
        WorkspaceRuleUsage::rulesUsing($keys)
    )));
    $sorted = static function (array $list): array {
        sort($list);

        return $list;
    };

    check($sorted($idsFor(['gac-a'])) === $sorted([$active, $inactive, $isNot]), 'gac-a: bloqueiam a ativa, a desativada e a de outra condição');
    check($idsFor(['gac-b']) === [$other], 'gac-b: só a regra da outra chave');
    check($idsFor(['gac-zzz']) === [], 'chave sem regra: nada');
    check(WorkspaceRuleUsage::rulesUsing([]) === [], 'lista vazia: nada');
    check(WorkspaceRuleUsage::workspaceKeyOfRule($active) === 'gac-a', 'workspaceKeyOfRule devolve a chave do critério');
    check(WorkspaceRuleUsage::workspaceKeyOfRule(0) === null, 'workspaceKeyOfRule(0) é null');

    // O salvamento: dois workspaces gravados, os dois usados por regras.
    $json = static fn (array $rows): string => json_encode($rows, JSON_UNESCAPED_UNICODE);
    $ws   = static fn (string $key): array => ['key' => $key, 'name' => 'T ' . $key, 'domains' => [$key . '.gactest.example'], 'admin_subject' => 'a@' . $key . '.gactest.example', 'is_active' => true];
    SsoConfig::save(['sso_workspaces' => $json([$ws('gac-a'), $ws('gac-b')])]);

    $post = static fn (array $keys): array => [
        'ws_key'     => $keys,
        'ws_name'    => array_map(static fn (string $k): string => 'T ' . $k, $keys),
        'ws_domains' => array_map(static fn (string $k): string => $k . '.gactest.example', $keys),
        'ws_admin'   => array_map(static fn (string $k): string => 'a@' . $k . '.gactest.example', $keys),
        'ws_active'  => array_fill(0, count($keys), '1'),
    ];

    $before = SsoConfig::load()['sso_workspaces'];
    (new SsoConfigSection())->handlePost($post(['gac-b']));       // remove gac-a, que tem regras
    check(SsoConfig::load()['sso_workspaces'] === $before, 'remoção de workspace em uso: nada foi gravado');

    (new SsoConfigSection())->handlePost($post(['gac-a']));       // remove gac-b, usado pela regra "outra chave"
    check(SsoConfig::load()['sso_workspaces'] === $before, 'remoção de gac-b (usado por uma regra): nada foi gravado');

    foreach ([$active, $inactive, $isNot, $other, $ticket] as $id) {
        drop_rule($id);
    }
    $ids = [];
    (new SsoConfigSection())->handlePost($post(['gac-b']));       // agora nenhuma regra usa gac-a
    check(array_map(static fn ($w) => $w->key, SsoSettings::workspaces(SsoConfig::load())->all()) === ['gac-b'], 'sem regras: a remoção é gravada');
} finally {
    foreach ($ids as $id) {
        drop_rule($id);
    }
    SsoConfig::save(['sso_workspaces' => $original]);
    echo "configuração dos workspaces restaurada\n";
}

finish();
