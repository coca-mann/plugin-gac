<?php

// Regras antigas (só GOOGLE_OU) continuam valendo em qualquer workspace; regras com GOOGLE_WORKSPACE
// só no workspace delas (spec S26). Cria regras GACTEST, exercita o RuleRunner e apaga tudo.
require __DIR__ . '/boot.php';

use GlpiPlugin\Gac\Sso\OuPath;
use GlpiPlugin\Gac\Sso\RuleRunner;

check(isset((new RuleRight())->getAllCriteria()['GOOGLE_WORKSPACE']), 'critério GOOGLE_WORKSPACE registrado');

$ids = [];
try {
    // R1: regra antiga, só a OU -> perfil 1.
    $ids[] = make_rule('antiga', [['GOOGLE_OU', Rule::PATTERN_IS, '/gactest/x']],
        [['entities_id', 'assign', 0], ['profiles_id', 'assign', 1], ['is_recursive', 'assign', 0]], 9100);
    // R2: OU + workspace principal -> perfil 4.
    $ids[] = make_rule('so principal', [['GOOGLE_OU', Rule::PATTERN_IS, '/gactest/x'], ['GOOGLE_WORKSPACE', Rule::PATTERN_IS, 'principal']],
        [['entities_id', 'assign', 0], ['profiles_id', 'assign', 4], ['is_recursive', 'assign', 0]], 9101);

    $profiles = static function (string $workspaceKey): array {
        $result = RuleRunner::result('a@gactest.example', OuPath::ancestors('/gactest/x'), $workspaceKey);
        $found  = array_unique(array_map(static fn (array $g): int => $g['profiles_id'], $result->grants));
        sort($found);

        return $found;
    };

    check($profiles('principal') === [1, 4], 'workspace principal: casa a regra antiga e a nova (perfis 1 e 4)');
    check($profiles('metropolitana') === [1], 'workspace metropolitana: só a regra antiga (perfil 1)');
    check($profiles('') === [1], 'sem chave de workspace: só a regra antiga (perfil 1)');
} finally {
    array_map('drop_rule', $ids);
    echo 'regras temporárias removidas: ', count($ids), "\n";
}

finish();
