<?php
// Moodle configuration for the throwaway smoke-test stack only.
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'db';
$CFG->dbname = 'moodle';
$CFG->dbuser = 'moodle';
$CFG->dbpass = 'moodle';
$CFG->prefix = 'mdl_';
$CFG->dboptions = ['dbpersist' => 0, 'dbport' => 5432];

$CFG->wwwroot = 'http://moodle';
$CFG->dataroot = '/var/www/moodledata';
$CFG->admin = 'admin';
$CFG->directorypermissions = 02777;

// The provider runs on a private Docker network address and port 8000,
// which Moodle's cURL security settings block by default.
$CFG->curlsecurityblockedhosts = '';
$CFG->curlsecurityallowedport = '';

require_once(__DIR__ . '/lib/setup.php');
