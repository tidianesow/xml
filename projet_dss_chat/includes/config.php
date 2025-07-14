<?php
define('DATA_DIR', __DIR__ . '/../data/');
define('DTD_DIR', __DIR__ . '/../dtd/');
define('USERS_XML', DATA_DIR . 'users.xml');
define('GROUPS_XML', DATA_DIR . 'groups.xml');
define('MESSAGES_XML', DATA_DIR . 'messages.xml');
define('USERS_DTD', DTD_DIR . 'users.dtd');
define('GROUPS_DTD', DTD_DIR . 'groups.dtd');
define('MESSAGES_DTD', DTD_DIR . 'messages.dtd');

// Activer les erreurs pour le débogage (à désactiver en production)
ini_set('display_errors', 1);
error_reporting(E_ALL);
?>