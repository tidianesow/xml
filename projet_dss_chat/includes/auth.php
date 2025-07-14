<?php
require_once 'config.php';

function addUser($username, $email, $password) {
    if (!file_exists(USERS_XML)) {
        die("Erreur : Le fichier users.xml n'existe pas.");
    }

    $xml = simplexml_load_file(USERS_XML);
    if ($xml === false) {
        die("Erreur : Impossible de charger users.xml.");
    }
    
    // Vérifier si le username ou email existe déjà
    foreach ($xml->user as $user) {
        if ($user->username == $username || $user->email == $email) {
            return false; // Utilisateur ou email déjà pris
        }
    }
    
    $user = $xml->addChild('user');
    $user->addAttribute('id', 'u' . uniqid());
    $user->addChild('username', htmlspecialchars($username));
    $user->addChild('password', password_hash($password, PASSWORD_DEFAULT));
    $user->addChild('email', htmlspecialchars($email));
    $user->addChild('status', 'offline');
    $user->addChild('last_login', '');
    return $xml->asXML(USERS_XML);
}

function loginUser($username, $password) {
    if (!file_exists(USERS_XML)) {
        die("Erreur : Le fichier users.xml n'existe pas.");
    }

    $xml = simplexml_load_file(USERS_XML);
    if ($xml === false) {
        die("Erreur : Impossible de charger users.xml.");
    }
    
    foreach ($xml->user as $user) {
        if ($user->username == $username && password_verify($password, $user->password)) {
            $user->status = 'online';
            $user->last_login = date('c');
            $xml->asXML(USERS_XML);
            return (string)$user['id'];
        }
    }
    return false;
}
?>