<?php
require_once '../includes/config.php';
require_once '../includes/groups.php';
require_once '../includes/messages.php';
require_once '../includes/security.php';
#require_once '../includes/functions.php';
#require_once '../includes/xml_helpers.php';

// Démarrer la session avec des paramètres sécurisés
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict'
    ]);
}

// Vérification de l'authentification
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

// Vérification de l'expiration de session
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 3600) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

// Mise à jour du dernier vu
updateLastSeen($_SESSION['user_id']);

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Cache pour améliorer les performances
$cacheKey = "user_data_{$userId}";
$cachedData = getFromCache($cacheKey);

if (!$cachedData) {
    $groups = getGroups($userId);
    $users = getUsers($userId);
    $conversations = getConversations($userId);
    
    // Mise en cache pour 5 minutes
    setCache($cacheKey, [
        'groups' => $groups,
        'users' => $users,
        'conversations' => $conversations
    ], 300);
} else {
    $groups = $cachedData['groups'];
    $users = $cachedData['users'];
    $conversations = $cachedData['conversations'];
}

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF invalide']));
    }
    
    // Limitation du taux de requêtes
    if (!checkRateLimit($userId, 'action', 10, 60)) {
        http_response_code(429);
        die(json_encode(['error' => 'Trop de requêtes']));
    }
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        if (isset($_POST['create_group'])) {
            $name = trim($_POST['group_name']);
            if (empty($name)) throw new Exception('Le nom du groupe est requis');
            if (strlen($name) > 50) throw new Exception('Le nom du groupe est trop long');
            
            $groupId = createGroup($name, $userId);
            $response = ['success' => true, 'message' => 'Groupe créé avec succès', 'groupId' => $groupId];
            
        } elseif (isset($_POST['join_group'])) {
            $groupId = trim($_POST['group_id']);
            if (empty($groupId) || !ctype_digit($groupId)) throw new Exception('ID de groupe invalide');
            
            if (addMemberToGroup($groupId, $userId)) {
                $response = ['success' => true, 'message' => 'Vous avez rejoint le groupe'];
            } else {
                throw new Exception('Impossible de rejoindre le groupe');
            }
            
        } elseif (isset($_POST['invite_user'])) {
            $groupId = trim($_POST['group_id']);
            $invitedUserId = trim($_POST['invited_user_id']);
            
            if (empty($groupId) || empty($invitedUserId)) throw new Exception('Données manquantes');
            
            if (inviteUserToGroup($groupId, $invitedUserId, $userId)) {
                $response = ['success' => true, 'message' => 'Invitation envoyée'];
            } else {
                throw new Exception('Erreur lors de l\'invitation');
            }
            
        } elseif (isset($_POST['send_message'])) {
            $receiverId = trim($_POST['receiver_id']);
            $receiverType = trim($_POST['receiver_type']);
            $content = trim($_POST['message']);
            
            if (empty($receiverId) || empty($content) || !in_array($receiverType, ['user', 'group'])) {
                throw new Exception('Données invalides');
            }
            
            if (strlen($content) > 1000) throw new Exception('Message trop long');
            
            if ($receiverType === 'group' && !isGroupMember($receiverId, $userId)) {
                throw new Exception('Vous n\'êtes pas membre de ce groupe');
            }
            
            $messageId = sendMessage($userId, $receiverId, $receiverType, $content);
            $response = ['success' => true, 'message' => 'Message envoyé', 'messageId' => $messageId];
            
        } elseif (isset($_POST['mark_read'])) {
            $receiverId = trim($_POST['receiver_id']);
            $receiverType = trim($_POST['receiver_type']);
            
            if (markMessagesAsRead($userId, $receiverId, $receiverType)) {
                $response = ['success' => true, 'message' => 'Messages marqués comme lus'];
            }
            
        } elseif (isset($_POST['delete_message'])) {
            $messageId = trim($_POST['message_id']);
            
            if (deleteMessage($messageId, $userId)) {
                $response = ['success' => true, 'message' => 'Message supprimé'];
            } else {
                throw new Exception('Impossible de supprimer le message');
            }
            
        } elseif (isset($_POST['leave_group'])) {
            $groupId = trim($_POST['group_id']);
            
            if (leaveGroup($groupId, $userId)) {
                $response = ['success' => true, 'message' => 'Vous avez quitté le groupe'];
            } else {
                throw new Exception('Impossible de quitter le groupe');
            }
        }
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    // Invalider le cache
    deleteFromCache($cacheKey);
    
    // Réponse AJAX
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    header('Location: dashboard.php');
    exit;
}

// Génération du token CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#075E54">
    <meta name="description" content="DSS Chat - Messagerie instantanée sécurisée">
    <title>Messagerie - DSS Chat</title>
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" as="style">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #128C7E;
            --primary-dark: #075E54;
            --primary-light: #25D366;
            --secondary: #34B7F1;
            --background: #ECE5DD;
            --white: #FFFFFF;
            --text: #000000;
            --text-light: #667781;
            --border: #E9EDEF;
            --danger: #DC3545;
            --warning: #FFC107;
            --success: #28A745;
            --shadow: rgba(0, 0, 0, 0.1);
        }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; margin: 0; background: var(--background); height: 100vh; overflow: hidden; }
        .app-container { display: flex; height: 100vh; overflow: hidden; }
        .sidebar { width: 400px; min-width: 320px; background: var(--white); border-right: 1px solid var(--border); display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-header { padding: 16px 20px; background: var(--primary-dark); color: var(--white); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 4px var(--shadow); }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: var(--white); display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; position: relative; overflow: hidden; }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .header-actions { display: flex; gap: 12px; align-items: center; }
        .action-btn { background: none; border: none; color: var(--white); font-size: 18px; cursor: pointer; padding: 8px; border-radius: 50%; transition: background 0.2s; }
        .action-btn:hover { background: rgba(255, 255, 255, 0.1); }
        .search-bar { padding: 12px 16px; background: #F0F2F5; border-bottom: 1px solid var(--border); }
        .search-input { width: 100%; padding: 10px 16px; border: none; border-radius: 20px; background: var(--white); font-size: 14px; outline: none; }
        .search-input:focus { box-shadow: 0 0 0 2px var(--primary); }
        .conversations-list { flex: 1; overflow-y: auto; scroll-behavior: smooth; }
        .conversation-item { display: flex; padding: 12px 16px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.2s; position: relative; }
        .conversation-item:hover { background: #F5F7FA; }
        .conversation-item.active { background: #E3F2FD; }
        .conversation-avatar { width: 50px; height: 50px; border-radius: 50%; background: #DFE5E7; color: var(--primary-dark); display: flex; align-items: center; justify-content: center; margin-right: 12px; font-weight: bold; font-size: 16px; position: relative; overflow: hidden; }
        .conversation-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .group-avatar { background: #E3F2FD; color: var(--secondary); }
        .status-dot { width: 12px; height: 12px; border-radius: 50%; position: absolute; bottom: 2px; right: 2px; border: 2px solid var(--white); }
        .status-dot.online { background: var(--primary-light); }
        .status-dot.offline { background: #95A5A6; }
        .conversation-content { flex: 1; min-width: 0; }
        .conversation-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .conversation-name { font-weight: 500; font-size: 16px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conversation-time { color: var(--text-light); font-size: 12px; white-space: nowrap; }
        .conversation-preview { display: flex; justify-content: space-between; align-items: center; }
        .conversation-message { color: var(--text-light); font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
        .unread-count { background: var(--primary-light); color: var(--white); border-radius: 50%; min-width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; padding: 0 6px; }
        .main-content { flex: 1; display: flex; flex-direction: column; background: var(--background); background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><circle cx="50" cy="50" r="2" fill="%23f0f0f0" opacity="0.5"/></svg>'); background-size: 100px 100px; }
        .welcome-screen { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; color: var(--text-light); }
        .welcome-icon { font-size: 80px; margin-bottom: 20px; color: var(--primary); }
        .welcome-screen h2 { margin: 0 0 10px 0; font-size: 24px; color: var(--text); }
        .welcome-screen p { margin: 0; font-size: 16px; }
        .chat-header { padding: 16px 20px; background: var(--primary-dark); color: var(--white); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 4px var(--shadow); }
        .chat-user-info { display: flex; align-items: center; gap: 12px; }
        .chat-user-details { display: flex; flex-direction: column; }
        .chat-user-name { font-weight: 500; font-size: 16px; margin: 0; }
        .chat-user-status { font-size: 12px; opacity: 0.8; margin: 0; }
        .chat-messages { flex: 1; padding: 20px; overflow-y: auto; scroll-behavior: smooth; }
        .message-group { margin-bottom: 16px; }
        .message-date { text-align: center; margin: 20px 0; color: var(--text-light); font-size: 12px; }
        .chat-bubble { max-width: 70%; margin-bottom: 8px; padding: 8px 12px; border-radius: 18px; word-wrap: break-word; position: relative; animation: messageSlideIn 0.3s ease; }
        @keyframes messageSlideIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .chat-bubble.sent { background: #DCF8C6; margin-left: auto; border-bottom-right-radius: 4px; }
        .chat-bubble.received { background: var(--white); margin-right: auto; border-bottom-left-radius: 4px; box-shadow: 0 1px 2px var(--shadow); }
        .message-content { margin: 0; line-height: 1.4; }
        .message-meta { display: flex; align-items: center; justify-content: space-between; margin-top: 4px; font-size: 11px; color: var(--text-light); }
        .message-time { opacity: 0.7; }
        .message-status { display: flex; align-items: center; gap: 4px; }
        .message-actions { opacity: 0; transition: opacity 0.2s; }
        .chat-bubble:hover .message-actions { opacity: 1; }
        .message-action-btn { background: none; border: none; color: var(--text-light); cursor: pointer; font-size: 12px; padding: 2px 4px; border-radius: 4px; }
        .message-action-btn:hover { background: rgba(0, 0, 0, 0.1); }
        .typing-indicator { padding: 10px 20px; color: var(--text-light); font-style: italic; font-size: 14px; display: none; }
        .chat-input { padding: 16px 20px; background: var(--white); border-top: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
        .chat-input input { flex: 1; padding: 10px 16px; border: 1px solid var(--border); border-radius: 24px; font-size: 14px; outline: none; transition: border-color 0.2s; }
        .chat-input input:focus { border-color: var(--primary); }
        .chat-input button { background: var(--primary); color: var(--white); border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; }
        .chat-input button:hover { background: var(--primary-dark); }
        .chat-input button:disabled { background: #BDC3C7; cursor: not-allowed; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background: var(--white); border-radius: 8px; width: 90%; max-width: 500px; overflow: hidden; box-shadow: 0 4px 12px var(--shadow); }
        .modal-header { padding: 16px 20px; background: var(--primary-dark); color: var(--white); display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 16px 20px; background: #F0F2F5; display: flex; justify-content: flex-end; gap: 12px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: var(--text); }
        .form-control { width: 100%; padding: 10px 16px; border: 1px solid var(--border); border-radius: 4px; font-size: 14px; outline: none; transition: border-color 0.2s; }
        .form-control:focus { border-color: var(--primary); }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; font-weight: 500; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--primary); color: var(--white); }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: #E4E6EB; color: var(--text); }
        .btn-secondary:hover { background: #D8DAE0; }
        .btn-danger { background: var(--danger); color: var(--white); }
        .btn-danger:hover { background: #C82333; }
        @media (max-width: 768px) {
            .app-container { flex-direction: column; }
            .sidebar { width: 100%; height: 50vh; }
            .main-content { width: 100%; height: 50vh; }
            .conversation-item { padding: 10px 12px; }
            .conversation-avatar { width: 40px; height: 40px; font-size: 14px; }
            .conversation-name { font-size: 14px; }
            .conversation-message { font-size: 12px; max-width: 150px; }
            .chat-input input { font-size: 12px; }
        }
        @media (max-width: 480px) {
            .sidebar { min-width: 100%; }
            .user-avatar { width: 32px; height: 32px; font-size: 12px; }
            .action-btn { font-size: 16px; padding: 6px; }
            .modal-content { width: 95%; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="user-info">
                    <div class="user-avatar"><?php echo $users[$userId]['avatar'] ? '<img src="' . escape($users[$userId]['avatar']) . '" alt="Avatar">' : getInitials($username); ?></div>
                    <span><?php echo escape($username); ?></span>
                </div>
                <div class="header-actions">
                    <button class="action-btn" title="Nouvelle conversation" onclick="openModal('newChatModal')"><i class="fas fa-comment-alt"></i></button>
                    <button class="action-btn" title="Nouveau groupe" onclick="openModal('newGroupModal')"><i class="fas fa-users"></i></button>
                    <button class="action-btn" title="Rejoindre un groupe" onclick="openModal('joinGroupModal')"><i class="fas fa-user-plus"></i></button>
                    <a class="action-btn" href="logout.php" title="Déconnexion"><i class="fas fa-sign-out-alt"></i></a>
                </div>
            </div>
            <div class="search-bar">
                <input type="text" class="search-input" placeholder="Rechercher un contact ou groupe..." oninput="searchConversations()">
            </div>
            <div class="conversations-list">
                <?php if (empty($conversations)): ?>
                    <div class="empty-state" style="text-align: center; padding: 20px;">
                        <i class="fas fa-comments" style="font-size: 40px; color: var(--text-light);"></i>
                        <h3>Aucune conversation</h3>
                        <p>Commencez une nouvelle discussion</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $key => $conv): ?>
                        <div class="conversation-item" data-id="<?php echo escape($conv['id']); ?>" data-type="<?php echo escape($conv['type']); ?>" onclick="loadChat('<?php echo escape($conv['id']); ?>', '<?php echo escape($conv['type']); ?>')">
                            <div class="conversation-avatar <?php echo $conv['type'] === 'group' ? 'group-avatar' : ''; ?>">
                                <?php echo $conv['avatar'] ? '<img src="' . escape($conv['avatar']) . '" alt="Avatar">' : getInitials($conv['name']); ?>
                                <?php if ($conv['type'] === 'user'): ?>
                                    <span class="status-dot <?php echo isOnline($conv['last_seen']) ? 'online' : 'offline'; ?>"></span>
                                <?php elseif ($conv['type'] === 'group'): ?>
                                    <i class="fas fa-users" style="font-size: 12px; position: absolute; bottom: -3px; right: -3px; background: var(--white); padding: 2px; border-radius: 50%;"></i>
                                <?php endif; ?>
                            </div>
                            <div class="conversation-content">
                                <div class="conversation-header">
                                    <div class="conversation-name"><?php echo escape($conv['name']); ?></div>
                                    <div class="conversation-time"><?php echo $conv['last_message'] ? formatDate($conv['last_message']) : ''; ?></div>
                                </div>
                                <div class="conversation-preview">
                                    <div class="conversation-message">
                                        <?php echo $conv['last_message_content'] ? escape($conv['last_message_content']) : 'Aucun message'; ?>
                                    </div>
                                    <?php if ($conv['unread_count'] > 0): ?>
                                        <div class="unread-count"><?php echo $conv['unread_count']; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Main Content -->
        <section class="main-content" id="mainContent">
            <div class="welcome-screen" id="welcomeScreen">
                <div class="welcome-icon"><i class="fas fa-comment-dots"></i></div>
                <h2>Bienvenue sur DSS Chat</h2>
                <p>Sélectionnez une conversation pour commencer à discuter</p>
            </div>
            <div id="chatArea" style="display: none; flex: 1; flex-direction: column;">
                <div class="chat-header" id="chatHeader">
                    <div class="chat-user-info">
                        <div class="conversation-avatar" id="chatAvatar"></div>
                        <div class="chat-user-details">
                            <h3 class="chat-user-name" id="chatName"></h3>
                            <p class="chat-user-status" id="chatStatus"></p>
                        </div>
                    </div>
                    <div class="header-actions">
                        <button class="action-btn" id="inviteButton" style="display: none;" onclick="openModal('inviteUserModal')"><i class="fas fa-user-plus"></i></button>
                        <button class="action-btn" id="leaveGroupButton" style="display: none;" onclick="leaveGroup()"><i class="fas fa-sign-out-alt"></i></button>
                    </div>
                </div>
                <div class="chat-messages" id="chatMessages"></div>
                <div class="typing-indicator" id="typingIndicator">Quelqu'un tape...</div>
                <form class="chat-input" id="messageForm" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" id="receiverId" name="receiver_id">
                    <input type="hidden" id="receiverType" name="receiver_type">
                    <input type="text" name="message" id="messageInput" placeholder="Tapez un message..." autocomplete="off">
                    <button type="submit" name="send_message" id="sendButton"><i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
        </section>
    </div>

    <!-- Modals -->
    <div id="newChatModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Nouvelle conversation</h3>
                <button class="action-btn" onclick="closeModal('newChatModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Contact</label>
                    <select id="newChatUserId" class="form-control">
                        <option value="">Sélectionnez un contact</option>
                        <?php foreach ($users as $id => $user): ?>
                            <option value="<?php echo escape($id); ?>"><?php echo escape($user['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('newChatModal')">Annuler</button>
                <button class="btn btn-primary" onclick="startNewChat()">Démarrer</button>
            </div>
        </div>
    </div>

    <div id="newGroupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Nouveau groupe</h3>
                <button class="action-btn" onclick="closeModal('newGroupModal')">×</button>
            </div>
            <form method="post" id="newGroupForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nom du groupe</label>
                        <input type="text" name="group_name" class="form-control" required maxlength="50">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('newGroupModal')">Annuler</button>
                    <button type="submit" name="create_group" class="btn btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="joinGroupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Rejoindre un groupe</h3>
                <button class="action-btn" onclick="closeModal('joinGroupModal')">×</button>
            </div>
            <form method="post" id="joinGroupForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label>ID du groupe</label>
                        <input type="text" name="group_id" class="form-control" placeholder="Entrez l'ID du groupe" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('joinGroupModal')">Annuler</button>
                    <button type="submit" name="join_group" class="btn btn-primary">Rejoindre</button>
                </div>
            </form>
        </div>
    </div>

    <div id="inviteUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Inviter un contact</h3>
                <button class="action-btn" onclick="closeModal('inviteUserModal')">×</button>
            </div>
            <form method="post" id="inviteUserForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" id="inviteGroupId" name="group_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Contact</label>
                        <select name="invited_user_id" class="form-control" required>
                            <option value="">Sélectionnez un contact</option>
                            <?php foreach ($users as $id => $user): ?>
                                <option value="<?php echo escape($id); ?>"><?php echo escape($user['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('inviteUserModal')">Annuler</button>
                    <button type="submit" name="invite_user" class="btn btn-primary">Inviter</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentChat = null;
        let typingTimeout = null;

        function loadChat(id, type) {
            currentChat = { id, type };
            const conversations = <?php echo json_encode($conversations); ?>;
            const users = <?php echo json_encode($users); ?>;
            const chatName = conversations[`${type}_${id}`]?.name || 'Conversation';
            const avatar = conversations[`${type}_${id}`]?.avatar || '';
            const status = type === 'user' ? (users[id]?.last_seen ? getStatusText(users[id].last_seen) : 'Hors ligne') : 'Groupe';
            const welcomeScreen = document.getElementById('welcomeScreen');
            const chatArea = document.getElementById('chatArea');
            const chatAvatar = document.getElementById('chatAvatar');
            const chatMessages = document.getElementById('chatMessages');
            const receiverId = document.getElementById('receiverId');
            const receiverType = document.getElementById('receiverType');
            const inviteButton = document.getElementById('inviteButton');
            const leaveGroupButton = document.getElementById('leaveGroupButton');

            // Update UI
            welcomeScreen.style.display = 'none';
            chatArea.style.display = 'flex';
            document.getElementById('chatName').innerText = chatName;
            document.getElementById('chatStatus').innerText = status;
            chatAvatar.innerHTML = avatar ? `<img src="${avatar}" alt="Avatar">` : getInitials(chatName);
            chatAvatar.className = `conversation-avatar ${type === 'group' ? 'group-avatar' : ''}`;
            if (type === 'group') {
                chatAvatar.innerHTML += `<i class="fas fa-users" style="font-size: 12px; position: absolute; bottom: -3px; right: -3px; background: var(--white); padding: 2px; border-radius: 50%;"></i>`;
                inviteButton.style.display = 'inline-block';
                leaveGroupButton.style.display = 'inline-block';
            } else {
                chatAvatar.innerHTML += `<span class="status-dot ${users[id]?.last_seen && new Date(users[id].last_seen) > new Date(Date.now() - 5*60*1000) ? 'online' : 'offline'}"></span>`;
                inviteButton.style.display = 'none';
                leaveGroupButton.style.display = 'none';
            }
            receiverId.value = id;
            receiverType.value = type;

            // Highlight active conversation
            document.querySelectorAll('.conversation-item').forEach(item => item.classList.remove('active'));
            const activeItem = document.querySelector(`.conversation-item[data-id="${id}"][data-type="${type}"]`);
            if (activeItem) activeItem.classList.add('active');

            // Load messages
            chatMessages.innerHTML = '';
            const messages = <?php echo json_encode(getMessages($userId)); ?>;
            let lastDate = null;
            messages.forEach(msg => {
                if ((msg.receiver === id && msg.receiver_type === type) ||
                    (msg.receiver_type === 'user' && (msg.sender === id || msg.receiver === id))) {
                    if (msg.deleted) return;
                    const messageDate = new Date(msg.timestamp).toLocaleDateString();
                    if (messageDate !== lastDate) {
                        chatMessages.innerHTML += `<div class="message-date">${formatDate(msg.timestamp)}</div>`;
                        lastDate = messageDate;
                    }
                    const bubble = document.createElement('div');
                    bubble.className = `chat-bubble ${msg.sender === '<?php echo $userId; ?>' ? 'sent' : 'received'}`;
                    bubble.innerHTML = `
                        <div class="message-content">${msg.content}${msg.edited ? ' <small>(modifié)</small>' : ''}</div>
                        <div class="message-meta">
                            <span class="message-time">${new Date(msg.timestamp).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}</span>
                            <span class="message-actions">
                                ${msg.sender === '<?php echo $userId; ?>' ? `<button class="message-action-btn" onclick="deleteMessage('${msg.id}')"><i class="fas fa-trash"></i></button>` : ''}
                            </span>
                        </div>
                    `;
                    chatMessages.appendChild(bubble);
                }
            });
            chatMessages.scrollTop = chatMessages.scrollHeight;

            // Mark messages as read
            fetch('dashboard.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: `mark_read=1&receiver_id=${id}&receiver_type=${type}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            });
        }

        function searchConversations() {
            const query = document.querySelector('.search-input').value.toLowerCase();
            const list = document.querySelector('.conversations-list');
            list.innerHTML = '';
            const conversations = <?php echo json_encode($conversations); ?>;
            Object.values(conversations).filter(conv => conv.name.toLowerCase().includes(query)).forEach(conv => {
                const div = document.createElement('div');
                div.className = `conversation-item ${currentChat?.id === conv.id && currentChat?.type === conv.type ? 'active' : ''}`;
                div.dataset.id = conv.id;
                div.dataset.type = conv.type;
                div.onclick = () => loadChat(conv.id, conv.type);
                div.innerHTML = `
                    <div class="conversation-avatar ${conv.type === 'group' ? 'group-avatar' : ''}">
                        ${conv.avatar ? `<img src="${conv.avatar}" alt="Avatar">` : getInitials(conv.name)}
                        ${conv.type === 'user' ? `<span class="status-dot ${new Date(conv.last_seen) > new Date(Date.now() - 5*60*1000) ? 'online' : 'offline'}"></span>` : '<i class="fas fa-users" style="font-size: 12px; position: absolute; bottom: -3px; right: -3px; background: var(--white); padding: 2px; border-radius: 50%;"></i>'}
                    </div>
                    <div class="conversation-content">
                        <div class="conversation-header">
                            <div class="conversation-name">${conv.name}</div>
                            <div class="conversation-time">${conv.last_message ? formatDate(conv.last_message) : ''}</div>
                        </div>
                        <div class="conversation-preview">
                            <div class="conversation-message">${conv.last_message_content || 'Aucun message'}</div>
                            ${conv.unread_count > 0 ? `<div class="unread-count">${conv.unread_count}</div>` : ''}
                        </div>
                    </div>
                `;
                list.appendChild(div);
            });
        }

        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function startNewChat() {
            const userId = document.getElementById('newChatUserId').value;
            if (userId) {
                loadChat(userId, 'user');
                closeModal('newChatModal');
            }
        }

        function deleteMessage(messageId) {
            if (confirm('Voulez-vous supprimer ce message ?')) {
                fetch('dashboard.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: `delete_message=1&message_id=${messageId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
                }).then(response => response.json()).then(data => {
                    if (data.success) {
                        loadChat(currentChat.id, currentChat.type);
                    } else {
                        alert(data.message);
                    }
                });
            }
        }

        function leaveGroup() {
            if (confirm('Voulez-vous quitter ce groupe ?')) {
                fetch('dashboard.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: `leave_group=1&group_id=${currentChat.id}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
                }).then(response => response.json()).then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                });
            }
        }

        function getInitials(name) {
            const words = name.split(' ').filter(w => w);
            let initials = '';
            for (const word of words) initials += word.charAt(0).toUpperCase();
            return initials.substring(0, 2);
        }

        function getStatusText(lastSeen) {
            const diff = Math.floor((Date.now() - new Date(lastSeen)) / 1000);
            if (diff < 300) return 'En ligne';
            if (diff < 3600) return `Vu il y a ${Math.round(diff/60)} min`;
            if (diff < 86400) return `Vu il y a ${Math.round(diff/3600)} h`;
            return `Vu il y a ${Math.round(diff/86400)} j`;
        }

        // Handle message input for typing indicator
        document.getElementById('messageInput').addEventListener('input', function() {
            if (currentChat) {
                // Send typing event via Pusher
                /*
                const pusher = new Pusher('YOUR_PUSHER_KEY', { cluster: 'YOUR_PUSHER_CLUSTER' });
                pusher.trigger('chat-channel', 'typing', {
                    userId: '<?php echo $userId; ?>',
                    receiverId: currentChat.id,
                    receiverType: currentChat.type
                });
                */
                clearTimeout(typingTimeout);
                typingTimeout = setTimeout(() => {
                    // Send stop typing event
                }, 2000);
            }
        });

        // WebSocket for real-time updates (Pusher)
        /*
        const pusher = new Pusher('YOUR_PUSHER_KEY', { cluster: 'YOUR_PUSHER_CLUSTER' });
        const channel = pusher.subscribe('chat-channel');
        channel.bind('new-message', data => {
            if ((data.receiver === currentChat?.id && data.receiver_type === currentChat?.type) ||
                (data.receiver_type === 'user' && (data.sender === currentChat?.id || data.receiver === currentChat?.id))) {
                const messagesDiv = document.getElementById('chatMessages');
                const lastDateDiv = messagesDiv.querySelector('.message-date:last-child');
                const messageDate = new Date(data.timestamp).toLocaleDateString();
                const lastDate = lastDateDiv ? lastDateDiv.textContent : null;
                if (messageDate !== lastDate) {
                    messagesDiv.innerHTML += `<div class="message-date">${formatDate(data.timestamp)}</div>`;
                }
                const bubble = document.createElement('div');
                bubble.className = `chat-bubble ${data.sender === '<?php echo $userId; ?>' ? 'sent' : 'received'}`;
                bubble.innerHTML = `
                    <div class="message-content">${data.content}</div>
                    <div class="message-meta">
                        <span class="message-time">${new Date(data.timestamp).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}</span>
                        <span class="message-actions">
                            ${data.sender === '<?php echo $userId; ?>' ? `<button class="message-action-btn" onclick="deleteMessage('${data.id}')"><i class="fas fa-trash"></i></button>` : ''}
                        </span>
                    </div>
                `;
                messagesDiv.appendChild(bubble);
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }
            // Refresh conversation list
            fetch('get_conversations.php')
                .then(response => response.json())
                .then(data => {
                    const list = document.querySelector('.conversations-list');
                    list.innerHTML = '';
                    data.forEach(conv => {
                        const div = document.createElement('div');
                        div.className = `conversation-item ${currentChat?.id === conv.id && currentChat?.type === conv.type ? 'active' : ''}`;
                        div.dataset.id = conv.id;
                        div.dataset.type = conv.type;
                        div.onclick = () => loadChat(conv.id, conv.type);
                        div.innerHTML = `
                            <div class="conversation-avatar ${conv.type === 'group' ? 'group-avatar' : ''}">
                                ${conv.avatar ? `<img src="${conv.avatar}" alt="Avatar">` : getInitials(conv.name)}
                                ${conv.type === 'user' ? `<span class="status-dot ${new Date(conv.last_seen) > new Date(Date.now() - 5*60*1000) ? 'online' : 'offline'}"></span>` : '<i class="fas fa-users" style="font-size: 12px; position: absolute; bottom: -3px; right: -3px; background: var(--white); padding: 2px; border-radius: 50%;"></i>'}
                            </div>
                            <div class="conversation-content">
                                <div class="conversation-header">
                                    <div class="conversation-name">${conv.name}</div>
                                    <div class="conversation-time">${conv.last_message ? formatDate(conv.last_message) : ''}</div>
                                </div>
                                <div class="conversation-preview">
                                    <div class="conversation-message">${conv.last_message_content || 'Aucun message'}</div>
                                    ${conv.unread_count > 0 ? `<div class="unread-count">${conv.unread_count}</div>` : ''}
                                </div>
                            </div>
                        `;
                        list.appendChild(div);
                    });
                });
        });
        channel.bind('typing', data => {
            if (data.userId !== '<?php echo $userId; ?>' && data.receiverId === currentChat?.id && data.receiverType === currentChat?.type) {
                const typingIndicator = document.getElementById('typingIndicator');
                typingIndicator.style.display = 'block';
                clearTimeout(typingTimeout);
                typingTimeout = setTimeout(() => {
                    typingIndicator.style.display = 'none';
                }, 2000);
            }
        });
        */

        // Auto-load first conversation
        <?php if (!empty($conversations)): ?>
            loadChat('<?php echo reset($conversations)['id']; ?>', '<?php echo reset($conversations)['type']; ?>');
        <?php endif; ?>
    </script>
</body>
</html>
