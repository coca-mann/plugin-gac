<?php

// E-mail que mudou no Google (spec S32): atualiza o e-mail do usuário do GLPI, a identidade e, só
// quando o login era o e-mail antigo, o login. Usa só dados GACTEST e confere que as identidades
// reais do banco não são tocadas.
require __DIR__ . '/boot.php';

use GlpiPlugin\Gac\Sso\SsoIdentity;
use GlpiPlugin\Gac\Sso\UserProvisioner;

global $DB;

$userIds = [];

/** Cria um usuário de teste com o login e os e-mails dados (o primeiro e-mail é o padrão). */
$makeUser = static function (string $login, array $emails) use ($DB, &$userIds): int {
    $DB->insert('glpi_users', ['name' => $login, 'authtype' => 4, 'is_active' => 1, 'is_deleted' => 0]);
    $id        = (int) $DB->insertId();
    $userIds[] = $id;
    foreach (array_values($emails) as $i => $email) {
        $DB->insert('glpi_useremails', ['users_id' => $id, 'email' => $email, 'is_default' => $i === 0 ? 1 : 0, 'is_dynamic' => 0]);
    }

    return $id;
};
$emailsOf = static function (int $id) use ($DB): array {
    $out = [];
    foreach ($DB->request(['FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => $id], 'ORDER' => ['id']]) as $row) {
        $out[] = $row['email'] . ((int) $row['is_default'] === 1 ? '*' : '');
    }

    return $out;
};
$loginOf = static fn (int $id): string => (string) $DB->request(['SELECT' => 'name', 'FROM' => 'glpi_users', 'WHERE' => ['id' => $id]])->current()['name'];
$identityFor = static function (int $usersId, string $email, string $sub): array {
    SsoIdentity::link($usersId, $sub, $email, 1, 0, []);

    return SsoIdentity::findBySub($sub);
};
$load = static function (int $id): User {
    $user = new User();
    $user->getFromDB($id);

    return $user;
};

$realBefore = countElementsInTable(SsoIdentity::getTable(), ['NOT' => ['google_sub' => ['LIKE', 'gactest-%']]]);

try {
    // 1. Usuário criado pelo Google: o login era o e-mail, então acompanha.
    $a   = $makeUser('ana@gactest.example', ['ana@gactest.example']);
    $ida = $identityFor($a, 'ana@gactest.example', 'gactest-sub-a');
    $user = $load($a);
    $detail = UserProvisioner::syncEmail($user, $ida, 'ana.silva@gactest.example');
    check($loginOf($a) === 'ana.silva@gactest.example', 'login que era o e-mail acompanhou o novo e-mail');
    check($emailsOf($a) === ['ana.silva@gactest.example*'], 'e-mail do usuário atualizado e continua o padrão: ' . json_encode($emailsOf($a)));
    check(SsoIdentity::findBySub('gactest-sub-a')['email_at_link'] === 'ana.silva@gactest.example', 'identidade guarda o novo e-mail');
    check($detail === 'email changed: ana@gactest.example -> ana.silva@gactest.example; login renamed', 'detalhe do evento: ' . $detail);
    check($user->fields['name'] === 'ana.silva@gactest.example', 'o objeto do usuário foi recarregado com o novo login (a sessão usa ele)');

    // 2. Sem mudança: nada é tocado.
    $same = UserProvisioner::syncEmail($load($a), SsoIdentity::findBySub('gactest-sub-a'), 'ANA.SILVA@gactest.example');
    check($same === '' && $emailsOf($a) === ['ana.silva@gactest.example*'], 'mesmo e-mail (em outra caixa): nada muda');

    // 3. Usuário convertido do AD: o login do AD fica, o e-mail é atualizado.
    $b   = $makeUser('bia.ad', ['bia@gactest.example']);
    $idb = $identityFor($b, 'bia@gactest.example', 'gactest-sub-b');
    $detail = UserProvisioner::syncEmail($load($b), $idb, 'bia.nova@gactest.example');
    check($loginOf($b) === 'bia.ad', 'login do AD não é renomeado');
    check($emailsOf($b) === ['bia.nova@gactest.example*'], 'e-mail do usuário do AD atualizado');
    check($detail === 'email changed: bia@gactest.example -> bia.nova@gactest.example; login kept', 'detalhe: ' . $detail);

    // 4. Login novo já usado por outro usuário: o login fica, o e-mail muda, o evento explica.
    $c    = $makeUser('caio@gactest.example', ['caio@gactest.example']);
    $other = $makeUser('caio.novo@gactest.example', ['outro@gactest.example']);
    $idc  = $identityFor($c, 'caio@gactest.example', 'gactest-sub-c');
    $detail = UserProvisioner::syncEmail($load($c), $idc, 'caio.novo@gactest.example');
    check($loginOf($c) === 'caio@gactest.example', 'login já usado por outro usuário: não é renomeado');
    check($loginOf($other) === 'caio.novo@gactest.example', 'o outro usuário não foi tocado');
    check(str_contains($detail, 'already used by another user'), 'detalhe explica o motivo: ' . $detail);

    // 5. O e-mail novo já era um dos e-mails do usuário: o antigo sai e o padrão passa para o novo.
    $d   = $makeUser('dani@gactest.example', ['dani@gactest.example', 'dani.nova@gactest.example']);
    $idd = $identityFor($d, 'dani@gactest.example', 'gactest-sub-d');
    UserProvisioner::syncEmail($load($d), $idd, 'dani.nova@gactest.example');
    check($emailsOf($d) === ['dani.nova@gactest.example*'], 'novo e-mail já existente: o antigo saiu e o novo é o padrão: ' . json_encode($emailsOf($d)));

    // 6. Usuário sem nenhum e-mail cadastrado: o novo entra como padrão.
    $e   = $makeUser('edu@gactest.example', []);
    $ide = $identityFor($e, 'edu@gactest.example', 'gactest-sub-e');
    UserProvisioner::syncEmail($load($e), $ide, 'edu.novo@gactest.example');
    check($emailsOf($e) === ['edu.novo@gactest.example*'], 'sem e-mails: o novo entra como padrão: ' . json_encode($emailsOf($e)));

    // 7. As identidades reais do banco não foram tocadas.
    $realAfter = countElementsInTable(SsoIdentity::getTable(), ['NOT' => ['google_sub' => ['LIKE', 'gactest-%']]]);
    check($realBefore === $realAfter, "identidades reais intactas ($realBefore antes, $realAfter depois)");
} finally {
    $DB->delete(SsoIdentity::getTable(), ['google_sub' => ['LIKE', 'gactest-%']]);
    foreach ($userIds as $id) {
        $DB->delete('glpi_useremails', ['users_id' => $id]);
        $DB->delete('glpi_users', ['id' => $id]);
    }
    echo "dados GACTEST removidos\n";
}

finish();
