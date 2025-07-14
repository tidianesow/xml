Projet DSS - XML : Plateforme de Messagerie Instantanée
Description
Une application de messagerie instantanée utilisant XML pour stocker les données (utilisateurs, groupes, messages). Développée avec PHP (SimpleXML) et une interface web simple.
Prérequis

XAMPP (ou WAMP/LAMP) avec PHP et Apache.
Permissions d’écriture sur le dossier data/.

Installation

Copiez le dossier projet_dss_chat dans htdocs de XAMPP.
Assurez-vous que les fichiers XML dans data/ sont accessibles en écriture.
Lancez XAMPP et accédez à http://localhost/projet_dss_chat/public/.

Utilisation

Créez un compte via register.php.
Connectez-vous via login.php.
Utilisez le tableau de bord (dashboard.php) pour voir les messages.
Envoyez des messages ou gérez les groupes via contacts.php et groups.php.

Structure

data/ : Fichiers XML (users.xml, groups.xml, messages.xml).
dtd/ : Définitions DTD pour valider les XML.
includes/ : Scripts PHP pour la logique.
public/ : Pages web pour l’interface.

Auteur
[Votre Nom] - Master 1 DIC2/DGI, ESP UCAD