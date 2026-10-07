<?php

// Foto do Google no usuário do GLPI (spec S33): lê a foto real de uma conta de teste do Workspace
// Principal e confere que ela é gravada, não é gravada de novo, nunca substitui uma foto escolhida à
// mão e é trocada quando o Google muda. Usa só usuários GACTEST e confere que as identidades reais
// não são tocadas.
require __DIR__ . '/boot.php';

use GlpiPlugin\Gac\Sso\DirectoryClient;
use GlpiPlugin\Gac\Sso\SsoConfig;
use GlpiPlugin\Gac\Sso\SsoIdentity;
use GlpiPlugin\Gac\Sso\SsoSettings;
use GlpiPlugin\Gac\Sso\UserPhoto;

global $DB;

const PHOTO_ACCOUNT    = 'sara.castro@fimca.com.br';   // conta de teste do Principal que tem foto
const NO_PHOTO_ACCOUNT = 'glpiteste@grupoapariciocarvalho.com.br';

$settings = SsoConfig::load();
$workspace = SsoSettings::workspaces($settings)->forEmail(PHOTO_ACCOUNT);
if ($workspace === null) {
    echo "O workspace de " . PHOTO_ACCOUNT . " não está configurado neste GLPI.\n";
    exit(1);
}
$directory = new DirectoryClient($settings, $workspace->adminSubject);

$userIds = [];
$written = [];   // fotos gravadas, para apagar no fim

$makeUser = static function (string $login, string $picture = '') use ($DB, &$userIds): int {
    $DB->insert('glpi_users', ['name' => $login, 'authtype' => 4, 'is_active' => 1, 'is_deleted' => 0, 'picture' => $picture === '' ? null : $picture]);
    $id        = (int) $DB->insertId();
    $userIds[] = $id;

    return $id;
};
$load = static function (int $id): User {
    $user = new User();
    $user->getFromDB($id);

    return $user;
};
$pictureOf = static fn (int $id): string => (string) ($DB->request(['SELECT' => 'picture', 'FROM' => 'glpi_users', 'WHERE' => ['id' => $id]])->current()['picture'] ?? '');
$identityFor = static function (int $usersId, string $sub): array {
    SsoIdentity::link($usersId, $sub, PHOTO_ACCOUNT, 1, 0, []);

    return SsoIdentity::findBySub($sub);
};
$thumbOf = static fn (string $path): string => GLPI_PICTURE_DIR . '/' . preg_replace('/\.(jpg|png)$/', '_min.$1', $path);

$realBefore = countElementsInTable(SsoIdentity::getTable(), ['NOT' => ['google_sub' => ['LIKE', 'gactest-%']]]);

try {
    $lookup = $directory->lookup(PHOTO_ACCOUNT);
    check($lookup['photoEtag'] !== '', 'a conta de teste tem foto no Google (etag presente)');

    // 1. Usuário sem foto: a foto do Google é gravada.
    $a    = $makeUser('gactest-photo-a');
    $ida  = $identityFor($a, 'gactest-photo-a');
    $user = $load($a);
    $done = UserPhoto::sync($user, $ida, $directory, PHOTO_ACCOUNT, $lookup['photoEtag']);
    $path = $pictureOf($a);
    $written[] = $path;
    check($done === true, 'primeira cópia: a foto foi gravada');
    check($path !== '' && is_file(GLPI_PICTURE_DIR . '/' . $path), 'o arquivo existe em GLPI_PICTURE_DIR: ' . $path);
    check($path !== '' && is_file($thumbOf($path)), 'a miniatura (_min) foi criada');
    check($path !== '' && @getimagesize(GLPI_PICTURE_DIR . '/' . $path) !== false, 'o arquivo gravado é uma imagem válida');
    check(Toolbox::getPictureUrl($path) !== null, 'o GLPI monta a URL da foto');
    $ident = SsoIdentity::findBySub('gactest-photo-a');
    check($ident['photo_etag'] === $lookup['photoEtag'] && $ident['photo_path'] === $path, 'a identidade guarda o etag e o caminho');
    check($user->fields['picture'] === $path, 'o objeto do usuário já traz a foto');

    // 2. Mesmo etag: nada é baixado nem regravado.
    $again = UserPhoto::sync($load($a), $ident, $directory, PHOTO_ACCOUNT, $lookup['photoEtag']);
    check($again === false && $pictureOf($a) === $path, 'mesmo etag: a foto não é gravada de novo');

    // 3. O usuário tirou a foto à mão: a mesma foto do Google não volta.
    $DB->update('glpi_users', ['picture' => null], ['id' => $a]);
    $back = UserPhoto::sync($load($a), $ident, $directory, PHOTO_ACCOUNT, $lookup['photoEtag']);
    check($back === false && $pictureOf($a) === '', 'foto removida pelo usuário: não volta com o mesmo etag');
    $DB->update('glpi_users', ['picture' => $path], ['id' => $a]);

    // 4. O Google mudou a foto (etag diferente): a foto do módulo é trocada e a antiga apagada.
    $DB->update(SsoIdentity::getTable(), ['photo_etag' => 'etag-antigo'], ['google_sub' => 'gactest-photo-a']);
    $changed = UserPhoto::sync($load($a), SsoIdentity::findBySub('gactest-photo-a'), $directory, PHOTO_ACCOUNT, $lookup['photoEtag']);
    $newPath = $pictureOf($a);
    $written[] = $newPath;
    check($changed === true && $newPath !== $path, 'etag mudou: a foto foi trocada por uma nova');
    check(!is_file(GLPI_PICTURE_DIR . '/' . $path) && !is_file($thumbOf($path)), 'a foto antiga e a miniatura foram apagadas');
    check(is_file(GLPI_PICTURE_DIR . '/' . $newPath), 'a foto nova existe');

    // 5. Foto escolhida à mão: nunca é substituída.
    $b    = $makeUser('gactest-photo-b', 'zz/manual_foto.png');
    $idb  = $identityFor($b, 'gactest-photo-b');
    $kept = UserPhoto::sync($load($b), $idb, $directory, PHOTO_ACCOUNT, $lookup['photoEtag']);
    check($kept === false && $pictureOf($b) === 'zz/manual_foto.png', 'foto escolhida à mão: nunca é substituída');
    check(SsoIdentity::findBySub('gactest-photo-b')['photo_etag'] === '', 'e a identidade não registra uma cópia que não houve');

    // 6. Conta sem foto no Google: nada é feito.
    $noPhoto = $directory->lookup(NO_PHOTO_ACCOUNT);
    $c       = $makeUser('gactest-photo-c');
    $idc     = $identityFor($c, 'gactest-photo-c');
    check($noPhoto['photoEtag'] === '', 'a conta sem foto vem com etag vazio');
    check(UserPhoto::sync($load($c), $idc, $directory, NO_PHOTO_ACCOUNT, $noPhoto['photoEtag']) === false && $pictureOf($c) === '', 'sem foto no Google: o usuário continua sem foto');
    check($directory->photo(NO_PHOTO_ACCOUNT) === null, 'photos.get de quem não tem foto devolve null (404 tratado)');

    // 7. As identidades reais do banco não foram tocadas.
    $realAfter = countElementsInTable(SsoIdentity::getTable(), ['NOT' => ['google_sub' => ['LIKE', 'gactest-%']]]);
    check($realBefore === $realAfter, "identidades reais intactas ($realBefore antes, $realAfter depois)");
} finally {
    foreach (array_filter($written) as $path) {
        User::dropPictureFiles($path);
    }
    $DB->delete(SsoIdentity::getTable(), ['google_sub' => ['LIKE', 'gactest-%']]);
    foreach ($userIds as $id) {
        $DB->delete('glpi_users', ['id' => $id]);
    }
    echo "dados GACTEST removidos\n";
}

finish();
