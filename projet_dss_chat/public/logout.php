<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
session_start();

if (isset($_SESSION['user_id'])) {
    $xml = simplexml_load_file(USERS_XML);
    foreach ($xml->user as $user) {
        if ($user['id'] == $_SESSION['user_id']) {
            $user->status = 'offline';
            $xml->asXML(USERS_XML);
            break;
        }
    }
    session_destroy();
}
header('Location: login.php');
exit;
?>