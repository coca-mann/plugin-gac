<?php

// Identidade Google de um usuário purgado (spec S10, S11): sai da tabela na purga, uma identidade
// órfã é descartada no login e a limpeza do install remove as que sobraram. Usa só dados GACTEST e
// confere que as identidades reais do banco não são tocadas.
require __DIR__ . '/boot.php';

use GlpiPlugin\Gac\Sso\Outcome;
use GlpiPlugin\Gac\Sso\SsoEvent;
use GlpiPlugin\Gac\Sso\SsoIdentity;

global $DB;

const GACTEST_EMAIL = 'gactest@gactest.example';

$userIds = [];

$makeUser = static function (string $name) use ($DB, &$userIds): int {
    $DB->insert('glpi_users', ['name' => $name, 'authtype' => 4, 'is_active' => 1, 'is_deleted' => 0]);
    $id        = (int) $DB->insertId();
    $userIds[] = $id;

    return $id;
};
$identityOf = static fn (string $sub): ?array => SsoIdentity::findBySub($sub);
$eventsFor  = static fn (int $usersId): array => iterator_to_array($DB->request([
    'FROM'  => SsoEvent::getTable(),
    'WHERE' => ['users_id' => $usersId, 'outcome' => Outcome::UNDONE],
]), false);

$realBefore = countElementsInTable(SsoIdentity::getTable(), ['NOT' => ['google_sub' => ['LIKE', 'gactest-%']]]);

try {
    // 1. Purga: a identidade sai da tabela e um evento explica.
    $purged = $makeUser('gactest-purged');
    SsoIdentity::link($purged, 'gactest-sub-purged', GACTEST_EMAIL, 1, 0, []);
    check($identityOf('gactest-sub-purged') !== null, 'identidade criada para o usuário de teste');

    $user = new User();
    $user->getFromDB($purged);
    $user->delete(['id' => $purged], true);
    check(countElementsInTable('glpi_users', ['id' => $purged]) === 0, 'usuário purgado de verdade');
    check($identityOf('gactest-sub-purged') === null, 'purga: a identidade saiu da tabela (hook item_purge)');
    $events = $eventsFor($purged);
    check(count($events) === 1 && str_contains((string) $events[0]['detail'], 'user purged'), 'purga: um evento "undone" explica o motivo');

    // 2. Lixeira (is_deleted): a identidade fica e o login continua tratando como inativo.
    $trashed = $makeUser('gactest-trashed');
    SsoIdentity::link($trashed, 'gactest-sub-trashed', GACTEST_EMAIL, 1, 0, []);
    $user = new User();
    $user->getFromDB($trashed);
    $user->delete(['id' => $trashed], false);
    check((int) $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $trashed]])->current()['is_deleted'] === 1, 'usuário foi só para a lixeira');
    check($identityOf('gactest-sub-trashed') !== null, 'lixeira: a identidade continua na tabela');
    check(SsoIdentity::linkedUserId($identityOf('gactest-sub-trashed')) === $trashed, 'lixeira: o login ainda enxerga o usuário vinculado');

    // 3. Identidade órfã no login: descartada, e o login segue como "sem vínculo".
    $ghost = 99999990;
    SsoIdentity::link($ghost, 'gactest-sub-ghost', GACTEST_EMAIL, 1, 0, []);
    check(SsoIdentity::linkedUserId($identityOf('gactest-sub-ghost')) === null, 'órfã: o login a trata como sem vínculo (null)');
    check($identityOf('gactest-sub-ghost') === null, 'órfã: a identidade foi descartada na hora');
    check(count($eventsFor($ghost)) === 1, 'órfã: um evento "undone" registra o descarte');
    check(SsoIdentity::linkedUserId(null) === null, 'sem identidade: null');

    // 4. Limpeza do install: remove as órfãs, e só elas.
    SsoIdentity::link(99999991, 'gactest-sub-orphan-1', GACTEST_EMAIL, 1, 0, []);
    SsoIdentity::link(99999992, 'gactest-sub-orphan-2', GACTEST_EMAIL, 1, 0, []);
    $removed = SsoIdentity::purgeOrphans();
    check($removed === 2, "purgeOrphans removeu as 2 órfãs de teste (removeu $removed)");
    check($identityOf('gactest-sub-orphan-1') === null && $identityOf('gactest-sub-orphan-2') === null, 'purgeOrphans: as órfãs saíram');
    check($identityOf('gactest-sub-trashed') !== null, 'purgeOrphans: a identidade do usuário na lixeira ficou');
    check(SsoIdentity::purgeOrphans() === 0, 'purgeOrphans é idempotente');

    // 5. As identidades reais do banco não foram tocadas.
    $realAfter = countElementsInTable(SsoIdentity::getTable(), ['NOT' => ['google_sub' => ['LIKE', 'gactest-%']]]);
    check($realBefore === $realAfter, "identidades reais intactas ($realBefore antes, $realAfter depois)");
} finally {
    $DB->delete(SsoIdentity::getTable(), ['google_sub' => ['LIKE', 'gactest-%']]);
    $DB->delete(SsoEvent::getTable(), ['email' => GACTEST_EMAIL]);
    foreach ($userIds as $id) {
        $DB->delete('glpi_users', ['id' => $id]);
    }
    echo "dados GACTEST removidos\n";
}

finish();
