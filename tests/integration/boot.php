<?php

// Sobe o kernel do GLPI local para os scripts de integração (só desenvolvimento).
// Uso: /c/xampp/php/php.exe tests/integration/<script>.php
$glpi = getenv('GLPI_DIR') ?: 'C:/Users/juliano/VSCode/glpi-xampp-dev-plugin';
chdir($glpi);
require 'vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel(null);
$kernel->boot();

global $DB;
$_SESSION['glpiactive_entity']           = 0;
$_SESSION['glpiactive_entity_recursive'] = 1;
$_SESSION['glpiactiveentities']          = [0];
$_SESSION['glpiactiveentities_string']   = '0';
$_SESSION['glpicronuserrunning']         = 'gac-integration';
$_SESSION['glpiname']                    = 'gac-integration';
$_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s');

require_once __DIR__ . '/support.php';
