<?php
require_once '../includes/config.php';
require_once '../includes/messages.php';
require_once '../includes/groups.php';
session_start();

// Vérification de la session
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Utilisateur';

// Charger les utilisateurs (contacts)
$xmlUsers = simplexml_load_file(USERS_XML);
$contacts = [];
foreach ($xmlUsers->user as $user) {
    if ((string)$user['id'] != $userId) {
        $contacts[(string)$user['id']] = (string)$user->username;
    }
}

// Charger les messages pour les conversations individuelles
$messages = getMessages($userId);

// Charger les groupes
$groups = getGroups($userId);

// Charger les messages de groupe depuis XML
$xmlMessages = simplexml_load_file(MESSAGES_XML);
$groupMessages = [];
foreach ($xmlMessages->message as $msg) {
    if ((string)$msg['receiver_type'] === 'group' && in_array((string)$msg['receiver'], array_column($groups, 'id'))) {
        $groupMessages[] = [
            'sender' => (string)$msg['sender'],
            'receiver' => (string)$msg['receiver'],
            'receiver_type' => (string)$msg['receiver_type'],
            'content' => (string)$msg->content,
            'timestamp' => (string)$msg->timestamp,
            'read' => (string)$msg['read'] === 'true'
        ];
    }
}

// Fonctions utilitaires
function escape($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

function formatTime($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    if ($diff < 60) return "À l'instant";
    if ($diff < 3600) return floor($diff/60) . ' min';
    if ($diff < 86400) return date('H:i', $time);
    if ($diff < 172800) return 'Hier';
    return date('d/m/Y', $time);
}

function getInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    return substr($initials, 0, 2) ?: 'U';
}

function truncateMessage($message, $length = 50) {
    return strlen($message) > $length ? substr($message, 0, $length) . '...' : $message;
}

// Organiser les conversations (individuelles et groupes)
function organizeConversations($messages, $groupMessages, $contacts, $groups, $currentUserId) {
    $conversations = [];
    // Conversations individuelles
    foreach ($messages as $message) {
        $partnerId = ($message['sender'] == $currentUserId) ? $message['receiver'] : $message['sender'];
        $partnerName = $contacts[$partnerId] ?? $partnerId;
        if (!isset($conversations[$partnerId])) {
            $conversations[$partnerId] = [
                'id' => $partnerId,
                'name' => $partnerName,
                'type' => 'user',
                'messages' => [],
                'last_message' => '',
                'last_timestamp' => '',
                'unread_count' => 0
            ];
        }
        $conversations[$partnerId]['messages'][] = $message;
        $conversations[$partnerId]['last_message'] = $message['content'];
        $conversations[$partnerId]['last_timestamp'] = $message['timestamp'];
        if ($message['sender'] != $currentUserId && !$message['is_read']) {
            $conversations[$partnerId]['unread_count']++;
        }
    }
    // Conversations de groupe
    foreach ($groups as $group) {
        $groupId = $group['id'];
        $conversations[$groupId] = [
            'id' => $groupId,
            'name' => $group['name'],
            'type' => 'group',
            'messages' => [],
            'last_message' => '',
            'last_timestamp' => '',
            'unread_count' => 0,
            'members' => $group['members'] ?? [],
            'admin' => $group['admin'] ?? $currentUserId
        ];
    }
    foreach ($groupMessages as $message) {
        $groupId = $message['receiver'];
        if (isset($conversations[$groupId])) {
            $conversations[$groupId]['messages'][] = $message;
            $conversations[$groupId]['last_message'] = $message['content'];
            $conversations[$groupId]['last_timestamp'] = $message['timestamp'];
            if ($message['sender'] != $currentUserId && !$message['read']) {
                $conversations[$groupId]['unread_count']++;
            }
        }
    }
    uasort($conversations, fn($a, $b) => strtotime($b['last_timestamp']) - strtotime($a['last_timestamp']));
    return $conversations;
}

$conversations = organizeConversations($messages, $groupMessages, $contacts, $groups, $userId);
$activeConversation = $_GET['conversation'] ?? array_key_first($conversations) ?? null;

// Gestion des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['send_message'])) {
            $receiverId = trim(filter_var($_POST['receiver_id'], FILTER_SANITIZE_STRING));
            $content = trim(filter_var($_POST['message_content'], FILTER_SANITIZE_STRING));
            $receiverType = $_POST['receiver_type'] ?? 'user';
            if (!empty($content) && !empty($receiverId)) {
                if ($receiverType === 'group') {
                    $group = array_filter($groups, fn($g) => $g['id'] === $receiverId);
                    if (!isset($group[$receiverId]) || !in_array($userId, $group[$receiverId]['members'] ?? [])) {
                        throw new Exception('Vous n\'êtes pas membre de ce groupe.');
                    }
                }
                sendMessage($userId, $receiverId, $receiverType, $content);
                header('Location: contacts.php?conversation=' . urlencode($receiverId));
                exit;
            } else {
                throw new Exception('Le message ou le destinataire est vide.');
            }
        } elseif (isset($_POST['add_contact'])) {
            $newContactId = trim(filter_var($_POST['new_contact_id'], FILTER_SANITIZE_STRING));
            $newContactName = trim(filter_var($_POST['new_contact_name'], FILTER_SANITIZE_STRING));
            if (!empty($newContactId) && !empty($newContactName)) {
                $xmlUsers = simplexml_load_file(USERS_XML);
                $user = $xmlUsers->addChild('user');
                $user->addAttribute('id', $newContactId);
                $user->addChild('username', $newContactName);
                $xmlUsers->asXML(USERS_XML);
                header('Location: contacts.php');
                exit;
            } else {
                throw new Exception('L\'ID ou le nom du contact est requis.');
            }
        } elseif (isset($_POST['invite_user'])) {
            $groupId = trim(filter_var($_POST['group_id'], FILTER_SANITIZE_STRING));
            $invitedUserId = trim(filter_var($_POST['invited_user_id'], FILTER_SANITIZE_STRING));
            $group = array_filter($groups, fn($g) => $g['id'] === $groupId);
            if (!empty($groupId) && !empty($invitedUserId) && !in_array($invitedUserId, $group[$groupId]['members'] ?? [])) {
                $xmlGroups = simplexml_load_file(GROUPS_XML);
                $group = $xmlGroups->xpath("//group[id='$groupId']")[0];
                if ($group && $group['admin'] == $userId) {
                    $invitation = $group->addChild('invitation');
                    $invitation->addAttribute('user_id', $invitedUserId);
                    $invitation->addAttribute('status', 'pending');
                    $invitation->addAttribute('invited_by', $userId);
                    $invitation->addChild('timestamp', date('Y-m-d H:i:s'));
                    $xmlGroups->asXML(GROUPS_XML);
                }
                header('Location: contacts.php?conversation=' . urlencode($groupId));
                exit;
            } else {
                throw new Exception('Impossible d\'inviter cet utilisateur.');
            }
        }
    } catch (Exception $e) {
        $error = "Erreur : " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111b21">
    <title>Gérer les Contacts - Mon Application</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #111b21;
            --secondary-bg: #202c33;
            --bubble-sent: #005c4b;
            --bubble-received: #202c33;
            --text-primary: #e9edef;
            --text-secondary: #8696a0;
            --accent: #25d366;
            --accent-hover: #128c7e;
            --border: #313d45;
            --error: #dc3545;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--primary-bg);
            height: 100vh;
            overflow: hidden;
            color: var(--text-primary);
        }

        .app-container {
            display: flex;
            height: 100vh;
            max-width: 1400px;
            margin: 0 auto;
            background: var(--primary-bg);
        }

        .navbar {
            background: var(--secondary-bg);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .navbar .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-weight: bold;
            font-size: 1.1rem;
        }

        .sidebar {
            width: 400px;
            background: var(--primary-bg);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .header-actions a, .header-actions button {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .header-actions a:hover, .header-actions button:hover {
            background: var(--border);
            color: var(--text-primary);
        }

        .search-bar {
            padding: 10px 15px;
            border-bottom: 1px solid var(--border);
        }

        .search-input {
            width: 100%;
            background: var(--border);
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .search-input::placeholder {
            color: var(--text-secondary);
        }

        .search-input:focus {
            outline: 2px solid var(--accent);
            background: var(--secondary-bg);
        }

        .contacts-list {
            flex: 1;
            overflow-y: auto;
        }

        .contact-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            cursor: pointer;
            transition: background 0.2s ease;
            border-bottom: 1px solid var(--border);
        }

        .contact-item:hover, .contact-item.active {
            background: var(--secondary-bg);
        }

        .contact-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-weight: bold;
            font-size: 1.1rem;
            margin-right: 15px;
        }

        .contact-info {
            flex: 1;
            min-width: 0;
        }

        .contact-name {
            color: var(--text-primary);
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 2px;
        }

        .contact-preview {
            color: var(--text-secondary);
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .contact-meta {
            text-align: right;
            flex-shrink: 0;
            margin-left: 10px;
        }

        .contact-time {
            color: var(--text-secondary);
            font-size: 0.8rem;
            margin-bottom: 5px;
        }

        .unread-count {
            background: var(--accent);
            color: var(--text-primary);
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.8rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--primary-bg);
        }

        .chat-header {
            background: var(--secondary-bg);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 900;
        }

        .chat-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-weight: bold;
            font-size: 1.1rem;
            position: relative;
        }

        .chat-status {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: var(--primary-bg);
            background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.1) 1px, transparent 0);
            background-size: 30px 30px;
        }

        .message {
            display: flex;
            margin-bottom: 15px;
            animation: fadeIn 0.3s ease-out;
        }

        .message.sent {
            justify-content: flex-end;
        }

        .message-bubble {
            max-width: 60%;
            padding: 10px 15px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .message.received .message-bubble {
            background: var(--bubble-received);
            color: var(--text-primary);
            border-bottom-left-radius: 4px;
        }

        .message.sent .message-bubble {
            background: var(--bubble-sent);
            color: var(--text-primary);
            border-bottom-right-radius: 4px;
        }

        .message-content {
            margin-bottom: 5px;
            line-height: 1.4;
            word-break: break-word;
        }

        .message-time {
            font-size: 0.7rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 5px;
            justify-content: flex-end;
        }

        .chat-input-container {
            background: var(--secondary-bg);
            padding: 15px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            position: sticky;
            bottom: 0;
            z-index: 900;
        }

        .chat-input-form {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chat-input {
            flex: 1;
            background: var(--border);
            border: none;
            border-radius: 20px;
            padding: 12px 20px;
            color: var(--text-primary);
            font-size: 0.9rem;
            resize: none;
            max-height: 100px;
        }

        .chat-input::placeholder {
            color: var(--text-secondary);
        }

        .chat-input:focus {
            outline: 2px solid var(--accent);
            background: var(--secondary-bg);
        }

        .send-button {
            background: var(--accent);
            border: none;
            border-radius: 50%;
            width: 45px;
            height: 45px;
            color: var(--text-primary);
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .send-button:hover {
            background: var(--accent-hover);
            transform: scale(1.05);
        }

        .welcome-screen {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--primary-bg);
            text-align: center;
            padding: 40px;
        }

        .welcome-icon {
            font-size: 8rem;
            color: var(--text-secondary);
            margin-bottom: 30px;
        }

        .welcome-screen h2 {
            font-size: 2rem;
            margin-bottom: 15px;
            font-weight: 300;
        }

        .welcome-screen p {
            color: var(--text-secondary);
            font-size: 1rem;
            line-height: 1.6;
            max-width: 500px;
        }

        .welcome-form {
            margin-top: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            width: 100%;
            max-width: 400px;
        }

        .welcome-form input, .welcome-form select {
            background: var(--border);
            border: none;
            border-radius: 20px;
            padding: 10px 15px;
            color: var(--text-primary);
            font-size: 0.9rem;
            flex: 1;
        }

        .welcome-form button {
            background: var(--accent);
            border: none;
            border-radius: 20px;
            padding: 10px 20px;
            color: var(--text-primary);
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .welcome-form button:hover {
            background: var(--accent-hover);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
        }

        .modal-content {
            background: var(--secondary-bg);
            margin: 5% auto;
            padding: 20px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            color: var(--text-primary);
            font-size: 1.2rem;
            font-weight: 500;
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            color: var(--text-primary);
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .form-group input, .form-group select {
            width: 100%;
            background: var(--border);
            border: none;
            border-radius: 8px;
            padding: 12px;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .alert {
            position: fixed;
            top: 70px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--error);
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 5px;
            z-index: 1001;
            display: none;
        }

        .alert.show {
            display: block;
            animation: fadeInOut 3s ease forwards;
        }

        @keyframes fadeInOut {
            0% { opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { opacity: 0; }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: absolute;
                z-index: 10;
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                width: 100%;
            }

            .toggle-btn {
                position: absolute;
                top: 10px;
                left: 10px;
                z-index: 1001;
                background: var(--accent);
                border: none;
                color: var(--text-primary);
                padding: 5px 10px;
                border-radius: 50%;
                font-size: 1.2rem;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .toggle-btn:hover {
                background: var(--accent-hover);
                transform: scale(1.05);
            }

            .welcome-form {
                flex-direction: column;
                align-items: stretch;
            }

            .welcome-form select, .welcome-form input, .welcome-form button {
                width: 100%;
                margin: 5px 0;
            }
        }

        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--secondary-bg);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--text-secondary);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-primary);
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <button class="toggle-btn d-md-none" id="toggleSidebar" aria-label="Ouvrir le menu des contacts"><i class="fas fa-bars"></i></button>
        <div class="user-profile">
            <div class="user-avatar" aria-hidden="true"><?php echo getInitials($username); ?></div>
            <span class="user-name"><?php echo escape($username); ?></span>
        </div>
        <div class="header-actions">
            <a href="dashboard.php" title="Retour au tableau de bord" aria-label="Retour au tableau de bord"><i class="fas fa-arrow-left"></i></a>
            <a href="groups.php" title="Gérer les groupes" aria-label="Gérer les groupes"><i class="fas fa-users"></i></a>
        </div>
    </nav>

    <div class="app-container" role="main">
        <aside class="sidebar" id="sidebar" aria-label="Liste des contacts et conversations">
            <div class="sidebar-header">
                <div class="user-profile">
                    <div class="user-avatar" aria-hidden="true"><?php echo getInitials($username); ?></div>
                    <div class="user-name"><?php echo escape($username); ?></div>
                </div>
                <div class="header-actions">
                    <button onclick="openModal('addContactModal')" title="Ajouter un contact" aria-label="Ajouter un contact"><i class="fas fa-user-plus"></i></button>
                    <a href="dashboard.php" title="Retour au tableau de bord" aria-label="Retour au tableau de bord"><i class="fas fa-arrow-left"></i></a>
                </div>
            </div>
            <div class="search-bar">
                <input type="text" class="search-input" placeholder="Rechercher un contact..." id="searchInput" aria-label="Rechercher un contact ou une conversation">
            </div>
            <div class="contacts-list" id="contactsList">
                <?php if (empty($conversations)): ?>
                    <div class="empty-contacts" role="alert">
                        <i class="fas fa-address-book" aria-hidden="true"></i>
                        <h3>Aucun contact</h3>
                        <p>Ajoutez un contact pour commencer une conversation.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $convId => $conv): ?>
                        <div class="contact-item <?php echo $convId === $activeConversation ? 'active' : ''; ?>" 
                             data-conversation-id="<?php echo escape($convId); ?>" 
                             data-conversation-type="<?php echo $conv['type']; ?>"
                             role="listitem" tabindex="0">
                            <div class="contact-avatar" aria-hidden="true">
                                <?php echo getInitials($conv['name']); ?>
                                <?php if ($conv['type'] === 'group'): ?><i class="fas fa-users" style="font-size: 0.8rem; position: absolute; bottom: 0; left: 0;"></i><?php endif; ?>
                            </div>
                            <div class="contact-info">
                                <div class="contact-name"><?php echo escape($conv['name']); ?></div>
                                <div class="contact-preview">
                                    <?php echo escape(truncateMessage($conv['last_message'] ?: ($conv['type'] === 'group' ? 'Nouveau groupe' : 'Nouvelle conversation'))); ?>
                                </div>
                            </div>
                            <div class="contact-meta">
                                <div class="contact-time"><?php echo $conv['last_timestamp'] ? formatTime($conv['last_timestamp']) : ''; ?></div>
                                <?php if ($conv['unread_count'] > 0): ?>
                                    <div class="unread-count" aria-label="<?php echo $conv['unread_count']; ?> messages non lus"><?php echo $conv['unread_count']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <section class="main-content" aria-label="Zone de gestion des contacts">
            <?php if ($activeConversation && isset($conversations[$activeConversation])): ?>
                <header class="chat-header">
                    <div class="chat-info">
                        <div class="chat-avatar" aria-hidden="true">
                            <?php echo getInitials($conversations[$activeConversation]['name']); ?>
                            <?php if ($conversations[$activeConversation]['type'] === 'group'): ?><i class="fas fa-users" style="font-size: 0.8rem; position: absolute; bottom: 0; left: 0;"></i><?php endif; ?>
                        </div>
                        <div class="chat-details">
                            <h3><?php echo escape($conversations[$activeConversation]['name']); ?></h3>
                            <div class="chat-status"><?php echo $conversations[$activeConversation]['type'] === 'group' ? 'Groupe actif' : 'En ligne'; ?></div>
                        </div>
                    </div>
                </header>
                <div class="chat-messages" id="chatMessages" role="log" aria-live="polite">
                    <?php 
                    $group = array_filter($groups, fn($g) => $g['id'] === $activeConversation);
                    $isMember = $conversations[$activeConversation]['type'] === 'user' || (isset($group[$activeConversation]) && in_array($userId, $group[$activeConversation]['members'] ?? []));
                    if ($isMember): ?>
                        <?php foreach ($conversations[$activeConversation]['messages'] as $message): ?>
                            <div class="message <?php echo $message['sender'] == $userId ? 'sent' : 'received'; ?>" data-timestamp="<?php echo $message['timestamp']; ?>">
                                <div class="message-bubble">
                                    <div class="message-content"><?php echo escape($message['content']); ?></div>
                                    <div class="message-time">
                                        <?php echo formatTime($message['timestamp']); ?>
                                        <?php if ($message['sender'] == $userId): ?>
                                            <i class="fas fa-check-double" style="color: #53bdeb;" aria-hidden="true"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($conversations[$activeConversation]['type'] === 'group'): ?>
                            <div class="members-list">
                                Membres : <?php echo implode(', ', array_map(fn($m) => escape($xmlUsers->xpath("//user[id='$m']/username")[0]), $group[$activeConversation]['members'] ?? [])); ?>
                                <?php if ($group[$activeConversation]['admin'] == $userId): ?>
                                    <form method="post" class="welcome-form">
                                        <select name="invited_user_id" required aria-label="Inviter un utilisateur">
                                            <option value="">Inviter un utilisateur</option>
                                            <?php foreach ($contacts as $id => $name): ?>
                                                <?php if (!in_array($id, $group[$activeConversation]['members'] ?? [])): ?>
                                                    <option value="<?php echo escape($id); ?>"><?php echo escape($name); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="group_id" value="<?php echo escape($activeConversation); ?>">
                                        <button type="submit" name="invite_user" aria-label="Inviter">Inviter</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="message">
                            <div class="message-bubble">
                                <div class="message-content">Vous n'êtes pas encore membre. Acceptez l'invitation pour rejoindre.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="chat-input-container">
                    <?php if ($isMember): ?>
                        <form class="chat-input-form" method="POST" action="">
                            <input type="hidden" name="receiver_id" value="<?php echo escape($activeConversation); ?>">
                            <input type="hidden" name="receiver_type" value="<?php echo $conversations[$activeConversation]['type']; ?>">
                            <textarea class="chat-input" name="message_content" placeholder="Tapez votre message..." rows="1" required aria-label="Écrire un message"></textarea>
                            <button type="submit" name="send_message" class="send-button" title="Envoyer" aria-label="Envoyer le message"><i class="fas fa-paper-plane"></i></button>
                        </form>
                    <?php else: ?>
                        <div class="message-bubble">Acceptez l'invitation pour envoyer des messages.</div>
                    <?php endif; ?>
                    <?php if (isset($error)): ?>
                        <div class="error-message" role="alert"><?php echo escape($error); ?></div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="welcome-screen" role="alert">
                    <div class="welcome-icon" aria-hidden="true"><i class="fas fa-address-book"></i></div>
                    <h2>Gérer vos contacts</h2>
                    <p>Ajoutez un contact ou sélectionnez une conversation pour commencer.</p>
                    <form method="post" class="welcome-form">
                        <input type="text" name="new_contact_id" placeholder="ID du contact" required aria-label="ID du contact">
                        <input type="text" name="new_contact_name" placeholder="Nom du contact" required aria-label="Nom du contact">
                        <button type="submit" name="add_contact" aria-label="Ajouter un contact">Ajouter</button>
                    </form>
                    <?php if (!empty($groups)): ?>
                        <form method="post" class="welcome-form">
                            <select name="group_id" required aria-label="Sélectionner un groupe">
                                <option value="">Sélectionnez un groupe</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo escape($group['id']); ?>"><?php echo escape($group['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="invited_user_id" required aria-label="Sélectionner un contact">
                                <option value="">Sélectionnez un contact</option>
                                <?php foreach ($contacts as $id => $name): ?>
                                    <option value="<?php echo escape($id); ?>"><?php echo escape($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="invite_user" aria-label="Inviter">Inviter</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="alert" id="alertBox"></div>

    <!-- Modal Ajouter Contact -->
    <div id="addContactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Ajouter un contact</h3>
                <button class="close-btn" onclick="closeModal('addContactModal')" aria-label="Fermer">×</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="newContactId">ID du contact</label>
                        <input type="text" id="newContactId" name="new_contact_id" placeholder="Entrez l'ID du contact" required>
                    </div>
                    <div class="form-group">
                        <label for="newContactName">Nom du contact</label>
                        <input type="text" id="newContactName" name="new_contact_name" placeholder="Entrez le nom du contact" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addContactModal')" aria-label="Annuler">Annuler</button>
                    <button type="submit" name="add_contact" class="btn" aria-label="Ajouter">Ajouter</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Fonction de debounce
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Auto-resize textarea
        const chatInput = document.querySelector('.chat-input');
        if (chatInput) {
            chatInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = this.scrollHeight + 'px';
            });
            chatInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.closest('form').submit();
                }
            });
        }

        // Toggle sidebar
        const toggleBtn = document.getElementById('toggleSidebar');
        const sidebar = document.getElementById('sidebar');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('active');
            });
        }

        // Contact selection
        document.querySelectorAll('.contact-item').forEach(item => {
            item.addEventListener('click', function() {
                const conversationId = this.dataset.conversationId;
                window.location.href = `contacts.php?conversation=${encodeURIComponent(conversationId)}`;
            });
            item.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            const performSearch = debounce(function() {
                const searchTerm = this.value.toLowerCase();
                document.querySelectorAll('.contact-item').forEach(item => {
                    const name = item.querySelector('.contact-name').textContent.toLowerCase();
                    item.style.display = name.includes(searchTerm) ? 'flex' : 'none';
                });
            }, 300);
            searchInput.addEventListener('input', performSearch);
        }

        // Auto-scroll to latest message
        const messagesContainer = document.getElementById('chatMessages');
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        // Polling for new messages
        async function checkNewMessages() {
            if (!document.querySelector('.contact-item.active')) return;
            const activeId = new URLSearchParams(window.location.search).get('conversation');
            const activeType = document.querySelector('.contact-item.active').dataset.conversationType;
            try {
                const response = await fetch(`get_messages.php?conversation=${encodeURIComponent(activeId)}&type=${activeType}&userId=${encodeURIComponent(<?php echo json_encode($userId); ?>)}`);
                if (!response.ok) throw new Error('Network error');
                const data = await response.json();
                if (data.messages && data.messages.length) {
                    messagesContainer.innerHTML = data.messages.map(msg => `
                        <div class="message ${msg.sender == <?php echo json_encode($userId); ?> ? 'sent' : 'received'}" data-timestamp="${msg.timestamp}">
                            <div class="message-bubble">
                                <div class="message-content">${escape(msg.content)}</div>
                                <div class="message-time">
                                    ${new Date(msg.timestamp).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}
                                    ${msg.sender == <?php echo json_encode($userId); ?> ? '<i class="fas fa-check-double" style="color: #53bdeb;"></i>' : ''}
                                </div>
                            </div>
                        </div>
                    `).join('');
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                }
            } catch (error) {
                showAlert('Erreur lors de la vérification des messages.');
                console.error('Error checking messages:', error);
            }
        }
        setInterval(checkNewMessages, 5000);

        // Modal handling
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Alert system
        function showAlert(message) {
            const alertBox = document.getElementById('alertBox');
            if (alertBox) {
                alertBox.textContent = message;
                alertBox.classList.add('show');
                setTimeout(() => alertBox.classList.remove('show'), 3000);
            }
        }

        // Escape function for JavaScript
        function escape(htmlStr) {
            return htmlStr.replace(/&/g, "&").replace(/</g, "<").replace(/>/g, ">").replace(/"/g, """).replace(/'/g, "'");
        }
    </script>
</body>
</html>