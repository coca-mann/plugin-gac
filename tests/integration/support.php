<?php

// Asserções mínimas dos scripts de integração.
$GLOBALS['gac_checks'] = ['ok' => 0, 'failed' => 0];

function check(bool $ok, string $label): void
{
    $GLOBALS['gac_checks'][$ok ? 'ok' : 'failed']++;
    echo ($ok ? 'OK     ' : 'FALHOU '), $label, "\n";
}

function finish(): never
{
    $c = $GLOBALS['gac_checks'];
    echo "\n", $c['ok'], ' ok, ', $c['failed'], " falharam\n";
    exit($c['failed'] === 0 ? 0 : 1);
}

/** Cria uma regra temporária com critérios e ações; devolve o id. */
function make_rule(string $name, array $criteria, array $actions, int $rank, string $class = 'RuleRight', bool $active = true): int
{
    global $DB;
    $rule = new $class();
    $id   = $rule->add([
        'name' => 'GACTEST ' . $name, 'sub_type' => $class, 'match' => 'AND',
        'is_active' => $active ? 1 : 0, 'entities_id' => 0, 'is_recursive' => 1, 'ranking' => $rank,
    ]);
    $DB->update('glpi_rules', ['ranking' => $rank], ['id' => $id]);
    foreach ($criteria as [$field, $cond, $pattern]) {
        (new RuleCriteria())->add(['rules_id' => $id, 'criteria' => $field, 'condition' => $cond, 'pattern' => $pattern]);
    }
    foreach ($actions as [$field, $type, $value]) {
        (new RuleAction())->add(['rules_id' => $id, 'action_type' => $type, 'field' => $field, 'value' => $value]);
    }

    return $id;
}

function drop_rule(int $id): void
{
    global $DB;
    $DB->delete('glpi_ruleactions', ['rules_id' => $id]);
    $DB->delete('glpi_rulecriterias', ['rules_id' => $id]);
    $DB->delete('glpi_rules', ['id' => $id]);
}
