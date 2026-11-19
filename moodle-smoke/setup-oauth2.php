<?php
// Creates the OAuth 2 service for the provider exactly as an administrator
// would in Site administration > Server > OAuth 2 services > Custom, then
// enables the OAuth 2 authentication plugin.
define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');

\core\session\manager::set_user(get_admin());

$issuer = \core\oauth2\api::create_issuer((object) [
    'name' => 'Laravel OpenID Provider',
    'clientid' => getenv('SMOKE_CLIENT_ID'),
    'clientsecret' => getenv('SMOKE_CLIENT_SECRET'),
    'baseurl' => getenv('SMOKE_ISSUER'),
    'loginscopes' => 'openid profile email',
    'loginscopesoffline' => 'openid profile email',
    'loginparams' => '',
    'loginparamsoffline' => '',
    'showonloginpage' => \core\oauth2\issuer::EVERYWHERE,
    'basicauth' => 0,
    'requireconfirmation' => 0,
    'servicetype' => 'custom',
    'enabled' => 1,
]);

// Key Moodle accounts on the stable OpenID subject instead of the email.
\core\oauth2\api::create_user_field_mapping((object) [
    'issuerid' => $issuer->get('id'),
    'externalfield' => 'sub',
    'internalfield' => 'username',
]);

\core\plugininfo\auth::enable_plugin('oauth2', 1);

$endpoints = array_map(fn ($e) => $e->get('name') . '=' . $e->get('url'), \core\oauth2\api::get_endpoints($issuer));
$mappings = array_map(fn ($m) => $m->get('externalfield') . '->' . $m->get('internalfield'), \core\oauth2\api::get_user_field_mappings($issuer));

cli_writeln('issuer id: ' . $issuer->get('id'));
cli_writeln('endpoints: ' . implode(', ', $endpoints));
cli_writeln('field mappings: ' . implode(', ', $mappings));
