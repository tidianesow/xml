
<?php
require_once '../includes/config.php';
require_once '../includes/groups.php';
require_once '../includes/messages.php';
require_once '../includes/functions.php';
require_once '../includes/xml_helpers.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Utilisateur';
$groups = getGroups($userId);

// Load users for contacts
$xmlUsers = simplexml_load_file(USERS_XML);
$users = [];
foreach ($xmlUsers->user as $user) {
    if ((string)$user['id'] != $userId) {
        $users[(string)$user['id']] = [
            'username' => (string)$user->username,
            'last_seen' => (string)$user->last_seen ?: '1970-01-01 00:00:00'
        ];
    }
}

// Load messages
$xmlMessages = simplexml_load_file(MESSAGES_XML);
$messages = [];
foreach ($xmlMessages->message as $msg) {
    if ((string)$msg['receiver_type'] === 'group' && in_array((string)$msg['receiver'], array_column($groups, 'id')) ||
        ((string)$msg['receiver_type'] === 'user' && ((string)$msg['sender'] === $userId || (string)$msg['receiver'] === $userId))) {
        $messages[] = [
            'sender' => (string)$msg['sender'],
            'receiver' => (string)$msg['receiver'],
            'receiver_type' => (string)$msg['receiver_type'],
            'content' => (string)$msg->content,
            'timestamp' => (string)$msg->timestamp,
            'read' => (string)$msg['read'] === 'true'
        ];
    }
}

// Organize conversations
function organizeConversations($messages, $groups, $users, $userId) {
    $conversations = [];
    foreach ($groups as $group) {
        $conversations['group_' . $group['id']] = [
            'id' => $group['id'],
            'name' => $group['name'],
            'type' => 'group',
            'message_count' => 0,
            'unread_count' => 0,
            'last_message' => '',
            'last_message_content' => ''
        ];
    }
    foreach ($users as $id => $user) {
        $conversations['user_' . $id] = [
            'id' => $id,
            'name' => $user['username'],
            'type' => 'user',
            'message_count' => 0,
            'unread_count' => 0,
            'last_message' => '',
            'last_message_content' => '',
            'last_seen' => $user['last_seen']
        ];
    }
    foreach ($messages as $msg) {
        $convId = $msg['receiver_type'] === 'group' ? 'group_' . $msg['receiver'] : 'user_' . ($msg['sender'] === $userId ? $msg['receiver'] : $msg['sender']);
        if (isset($conversations[$convId])) {
            $conversations[$convId]['message_count']++;
            if (!$msg['read'] && $msg['sender'] !== $userId) {
                $conversations[$convId]['unread_count']++;
            }
            if (!$conversations[$convId]['last_message'] || strtotime($msg['timestamp']) > strtotime($conversations[$convId]['last_message'])) {
                $conversations[$convId]['last_message'] = $msg['timestamp'];
                $conversations[$convId]['last_message_content'] = substr($msg['content'], 0, 50) . (strlen($msg['content']) > 50 ? '...' : '');
            }
        }
    }
    uasort($conversations, function($a, $b) {
        return strtotime($b['last_message'] ?: '1970-01-01') - strtotime($a['last_message'] ?: '1970-01-01');
    });
    return $conversations;
}

$conversations = organizeConversations($messages, $groups, $users, $userId);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    if (isset($_POST['create_group'])) {
        $name = trim($_POST['group_name']);
        if (!empty($name)) {
            createGroup($name, $userId);
            header('Location: dashboard.php');
            exit;
        }
    } elseif (isset($_POST['join_group'])) {
        $groupId = trim($_POST['group_id']);
        if (!empty($groupId)) {
            addMemberToGroup($groupId, $userId);
            header('Location: dashboard.php');
            exit;
        }
    } elseif (isset($_POST['invite_user'])) {
        $groupId = trim($_POST['group_id']);
        $invitedUserId = trim($_POST['invited_user_id']);
        if (!empty($groupId) && !empty($invitedUserId)) {
            $xmlGroups = simplexml_load_file(GROUPS_XML);
            $group = $xmlGroups->xpath("//group[id='$groupId']")[0];
            if ($group && !in_array($invitedUserId, array_column($groups[$groupId]['members'] ?? [], 'id'))) {
                $invitation = $group->addChild('invitation');
                $invitation->addAttribute('user_id', $invitedUserId);
                $invitation->addAttribute('status', 'pending');
                $invitation->addAttribute('invited_by', $userId);
                $invitation->addChild('timestamp', date('Y-m-d H:i:s'));
                $xmlGroups->asXML(GROUPS_XML);
            }
            header('Location: dashboard.php');
            exit;
        }
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Utility functions
function escape($data) { return htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); }
function formatDate($timestamp) { return date('H:i', strtotime($timestamp)); }
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) $initials .= strtoupper(substr($word, 0, 1));
    return substr($initials, 0, 2);
}
function isOnline($lastSeen) {
    return strtotime($lastSeen) > time() - 300; // Online if last seen within 5 minutes
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#075E54">
    <title>Messagerie - DSS Chat</title>
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
        }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; background: var(--background); height: 100vh; }
        .app-container { display: flex; flex: 1; overflow: hidden; }
        .sidebar { width: 400px; min-width: 300px; background: var(--white); border-right: 1px solid var(--border); display: flex; flex-direction: column; }
        .sidebar-header { padding: 15px; background: var(--primary-dark); color: var(--white); display: flex; align-items: center; justify-content: space-between; }
        .user-info { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: var(--white); display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .header-actions { display: flex; gap: 15px; }
        .action-btn { background: none; border: none; color: var(--white); font-size: 18px; cursor: pointer; }
        .search-bar { padding: 10px 15px; background: #F0F2F5; }
        .search-input { width: 100%; padding: 10px 15px; border: none; border-radius: 8px; background: var(--white); }
        .conversations-list { flex: 1; overflow-y: auto; }
        .conversation-item { display: flex; padding: 12px 15px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.2s; }
        .conversation-item:hover { background: #F5F5F5; }
        .conversation-avatar { width: 50px; height: 50px; border-radius: 50%; background: #DFE5E7; color: var(--primary-dark); display: flex; align-items: center; justify-content: center; margin-right: 15px; font-weight: bold; position: relative; }
        .group-avatar { background: #E2F3FB; }
        .status-dot { width: 12px; height: 12px; border-radius: 50%; position: absolute; bottom: 0; right: 0; border: 2px solid var(--white); }
        .online { background: var(--primary-light); }
        .offline { background: #D3D3D3; }
        .conversation-content { flex: 1; min-width: 0; }
        .conversation-header { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .conversation-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conversation-time { color: var(--text-light); font-size: 12px; }
        .conversation-preview { display: flex; justify-content: space-between; }
        .conversation-message { color: var(--text-light); font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
        .unread-count { background: var(--primary-light); color: var(--white); border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; }
        .main-content { flex: 1; display: flex; flex-direction: column; background: var(--background); background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAYAAACNiR0NAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAABnSURBVDhP7dDBCYAwDETR6CZO4hZu4hZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZu4RZ+5Q3lYQZ2eZq5XQAAAABJRU5ErkJggg=='); }
        .chat-header { padding: 15px; background: var(--primary-dark); color: var(--white); display: flex; align-items: center; justify-content: space-between; }
        .chat-messages { flex: 1; padding: 20px; overflow-y: auto; }
        .chat-bubble { max-width: 70%; margin-bottom: 10px; padding: 10px 15px; border-radius: 8px; }
        .chat-bubble.sent { background: #DCF8C6; margin-left: auto; }
        .chat-bubble.received { background: var(--white); margin-right: auto; }
        .chat-bubble-time { font-size: 12px; color: var(--text-light); margin-top: 5px; }
        .chat-input { padding: 15px; background: var(--white); border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .chat-input input { flex: 1; padding: 10px 15px; border: none; border-radius: 20px; background: #F0F2F5; }
        .chat-input button { background: var(--primary-light); color: var(--white); border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-content { background: var(--white); border-radius: 8px; width: 90%; max-width: 500px; overflow: hidden; }
        .modal-header { padding: 15px 20px; background: var(--primary-dark); color: var(--white); display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 15px 20px; background: #F0F2F5; display: flex; justify-content: flex-end; gap: 10px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px 15px; border: 1px solid var(--border); border-radius: 4px; font-size: 14px; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; font-weight: 500; cursor: pointer; }
        .btn-primary { background: var(--primary); color: var(--white); }
        .btn-secondary { background: #E4E6EB; color: var(--text); }
        @media (max-width: 768px) {
            .app-container { flex-direction: column; }
            .sidebar { width: 100%; height: 50vh; }
            .main-content { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="user-info">
                    <div class="user-avatar"><?php echo getInitials($username); ?></div>
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
                        <div class="conversation-item" onclick="loadChat('<?php echo escape($conv['id']); ?>', '<?php echo escape($conv['type']); ?>')">
                            <div class="conversation-avatar <?php echo $conv['type'] === 'group' ? 'group-avatar' : ''; ?>">
                                <?php echo getInitials($conv['name']); ?>
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
                                    <div class="conversation-message"><?php echo $conv['last_message_content'] ? escape($conv['last_message_content']) : 'Aucun message'; ?></div>
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
                    <div class="user-info">
                        <div class="conversation-avatar" id="chatAvatar"></div>
                        <div id="chatName" class="conversation-name"></div>
                    </div>
                    <div class="header-actions">
                        <button class="action-btn" id="inviteButton" style="display: none;" onclick="openModal('inviteUserModal')"><i class="fas fa-user-plus"></i></button>
                    </div>
                </div>
                <div class="chat-messages" id="chatMessages"></div>
                <form class="chat-input" id="messageForm" action="send_message.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" id="receiverId" name="receiver_id">
                    <input type="hidden" id="receiverType" name="receiver_type">
                    <input type="text" name="message" placeholder="Tapez un message..." autocomplete="off">
                    <button type="submit" name="send_message"><i class="fas fa-paper-plane"></i></button>
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
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nom du groupe</label>
                        <input type="text" name="group_name" class="form-control" required>
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
            <form method="post">
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
            <form method="post">
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

        function loadChat(id, type) {
            currentChat = { id, type };
            const conversations = <?php echo json_encode($conversations); ?>;
            const chatName = conversations[`${type}_${id}`]?.name || 'Conversation';
            const welcomeScreen = document.getElementById('welcomeScreen');
            const chatArea = document.getElementById('chatArea');
            const chatAvatar = document.getElementById('chatAvatar');
            const chatMessages = document.getElementById('chatMessages');
            const receiverId = document.getElementById('receiverId');
            const receiverType = document.getElementById('receiverType');
            const inviteButton = document.getElementById('inviteButton');

            welcomeScreen.style.display = 'none';
            chatArea.style.display = 'flex';
            document.getElementById('chatName').innerText = chatName;
            chatAvatar.innerHTML = getInitials(chatName);
            chatAvatar.className = `conversation-avatar ${type === 'group' ? 'group-avatar' : ''}`;
            if (type === 'group') {
                chatAvatar.innerHTML += `<i class="fas fa-users" style="font-size: 12px; position: absolute; bottom: -3px; right: -3px; background: var(--white); padding: 2px; border-radius: 50%;"></i>`;
                inviteButton.style.display = 'inline-block';
            } else {
                chatAvatar.innerHTML += `<span class="status-dot ${conversations[`${type}_${id}`]?.last_seen && new Date(conversations[`${type}_${id}`].last_seen) > new Date(Date.now() - 5*60*1000) ? 'online' : 'offline'}"></span>`;
                inviteButton.style.display = 'none';
            }
            receiverId.value = id;
            receiverType.value = type;
            chatMessages.innerHTML = '';
            <?php foreach ($messages as $msg): ?>
                if ('<?php echo $msg['receiver']; ?>' === id && '<?php echo $msg['receiver_type']; ?>' === type ||
                    ('<?php echo $msg['receiver_type']; ?>' === 'user' && ('<?php echo $msg['sender']; ?>' === id || '<?php echo $msg['receiver']; ?>' === id))) {
                    const bubble = document.createElement('div');
                    bubble.className = `chat-bubble p-3 mb-2 ${'<?php echo $msg['sender']; ?>' === '<?php echo $userId; ?>' ? 'sent' : 'received'}`;
                    bubble.innerHTML = `<div><?php echo escape($msg['content']); ?></div><div class="chat-bubble-time"><?php echo formatDate($msg['timestamp']); ?></div>`;
                    chatMessages.appendChild(bubble);
                }
            <?php endforeach; ?>
            chatMessages.scrollTop = chatMessages.scrollHeight;

            // Mark messages as read
            fetch('mark_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `receiver_id=${id}&receiver_type=${type}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            });
        }

        function searchConversations() {
            const query = document.querySelector('.search-input').value.toLowerCase();
            const list = document.querySelector('.conversations-list');
            list.innerHTML = '';
            const conversations = <?php echo json_encode($conversations); ?>;
            Object.values(conversations).filter(conv => conv.name.toLowerCase().includes(query)).forEach(conv => {
                const div = document.createElement('div');
                div.className = 'conversation-item';
                div.onclick = () => loadChat(conv.id, conv.type);
                div.innerHTML = `
                    <div class="conversation-avatar ${conv.type === 'group' ? 'group-avatar' : ''}">
                        ${getInitials(conv.name)}
                        ${conv.type === 'user' ? `<span class="status-dot ${new Date(conv.last_seen) > new Date(Date.now() - 5*60*1000) ? 'online' : 'offline'}"></span>` : '<i class="fas fa-users" style="font-size: 12px; position: absolute; bottom: -3px; right: -3px; background: var(--white); padding: 2px; border-radius: 50%;"></i>'}
                    </div>
                    <div class="conversation-content">
                        <div class="conversation-header">
                            <div class="conversation-name">${conv.name}</div>
                            <div class="conversation-time">${conv.last_message ? new Date(conv.last_message).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : ''}</div>
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

        function openInviteModal(groupId) {
            document.getElementById('inviteGroupId').value = groupId;
            openModal('inviteUserModal');
        }

        function startNewChat() {
            const userId = document.getElementById('newChatUserId').value;
            if (userId) {
                loadChat(userId, 'user');
                closeModal('newChatModal');
            }
        }

        function getInitials(name) {
            const words = name.split(' ');
            let initials = '';
            for (const word of words) initials += word.charAt(0).toUpperCase();
            return initials.substring(0, 2);
        }

        // WebSocket for real-time updates (Pusher)
        /*
        const pusher = new Pusher('YOUR_PUSHER_KEY', { cluster: 'YOUR_PUSHER_CLUSTER' });
        const channel = pusher.subscribe('chat-channel');
        channel.bind('new-message', data => {
            if ((data.receiver === currentChat?.id && data.receiver_type === currentChat?.type) ||
                (data.receiver_type === 'user' && (data.sender === currentChat?.id || data.receiver === currentChat?.id))) {
                const messagesDiv = document.getElementById('chatMessages');
                const bubble = document.createElement('div');
                bubble.className = `chat-bubble p-3 mb-2 ${data.sender === '<?php echo $userId; ?>' ? 'sent' : 'received'}`;
                bubble.innerHTML = `<div>${data.content}</div><div class="chat-bubble-time">${new Date(data.timestamp).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}</div>`;
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
                        div.className = 'conversation-item';
                        div.onclick = () => loadChat(conv.id, conv.type);
                        div.innerHTML = `
                            <div class="conversation-avatar ${conv.type === 'group' ? 'group-avatar' : ''}">
                                ${getInitials(conv.name)}
                                ${conv.type === 'user' ? `<span class="status-dot ${new Date(conv.last_seen) > new Date(Date.now() - 5*60*1000) ? 'online' : 'offline'}"></span>` : '<i class="fas fa-users" style="font-size: 12px; position: absolute; bottom: -3px; right: -3px; background: var(--white); padding: 2px; border-radius: 50%;"></i>'}
                            </div>
                            <div class="conversation-content">
                                <div class="conversation-header">
                                    <div class="conversation-name">${conv.name}</div>
                                    <div class="conversation-time">${conv.last_message ? new Date(conv.last_message).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : ''}</div>
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
        */

        // Auto-load first conversation
        <?php if (!empty($conversations)): ?>
            loadChat('<?php echo reset($conversations)['id']; ?>', '<?php echo reset($conversations)['type']; ?>');
        <?php endif; ?>
    </script>
</body>
</html>
