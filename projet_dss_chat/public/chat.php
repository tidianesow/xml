<?php
require_once '../includes/config.php';
require_once '../includes/messages.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Utilisateur';
$otherUserId = $_GET['user_id'] ?? '';
$xmlMessages = simplexml_load_file(MESSAGES_XML);
$messages = [];
foreach ($xmlMessages->message as $msg) {
    if ((string)$msg['receiver_type'] === 'user' && ((string)$msg['sender'] === $userId && (string)$msg['receiver'] === $otherUserId) || ((string)$msg['sender'] === $otherUserId && (string)$msg['receiver'] === $userId)) {
        $messages[] = [
            'sender' => (string)$msg['sender'],
            'receiver' => (string)$msg['receiver'],
            'content' => (string)$msg->content,
            'timestamp' => (string)$msg->timestamp
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $content = trim($_POST['message_content']);
    if (!empty($content) && !empty($otherUserId)) {
        sendMessage($userId, $otherUserId, 'user', $content);
        header('Location: chat.php?user_id=' . $otherUserId);
        exit;
    }
}

function escape($data) { return htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); }
function formatDate($timestamp) { return date('H:i', strtotime($timestamp)); }
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) $initials .= strtoupper(substr($word, 0, 1));
    return substr($initials, 0, 2);
}

$xmlUsers = simplexml_load_file(USERS_XML);
$otherUsername = $xmlUsers->xpath("//user[id='$otherUserId']/username")[0] ?? $otherUserId;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - Mon Application</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #111b21; height: 100vh; overflow: hidden; }
        .whatsapp-container { display: flex; height: 100vh; max-width: 1400px; margin: 0 auto; background: #111b21; }
        .sidebar { width: 400px; background: #111b21; border-right: 1px solid #313d45; display: flex; flex-direction: column; }
        .sidebar-header { background: #202c33; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #313d45; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #25d366 0%, #128c7e 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.1rem; }
        .user-name { color: #e9edef; font-size: 1rem; font-weight: 500; }
        .header-actions { display: flex; gap: 10px; }
        .header-actions a { background: none; border: none; color: #8696a0; font-size: 1.2rem; cursor: pointer; padding: 8px; border-radius: 50%; transition: all 0.3s ease; text-decoration: none; }
        .header-actions a:hover { background: #313d45; color: #e9edef; }
        .search-bar { background: #202c33; padding: 10px 15px; border-bottom: 1px solid #313d45; }
        .search-input { width: 100%; background: #2a3942; border: none; border-radius: 8px; padding: 10px 15px; color: #e9edef; font-size: 0.9rem; }
        .search-input::placeholder { color: #8696a0; }
        .search-input:focus { outline: none; background: #313d45; }
        .conversations-list { flex: 1; overflow-y: auto; }
        .conversation-item { display: flex; align-items: center; padding: 12px 20px; cursor: pointer; transition: background 0.2s ease; border-bottom: 1px solid #1f2937; }
        .conversation-item:hover { background: #202c33; }
        .main-chat { flex: 1; display: flex; flex-direction: column; background: #0b141a; }
        .chat-header { background: #202c33; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #313d45; }
        .chat-info { display: flex; align-items: center; gap: 12px; }
        .chat-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #25d366 0%, #128c7e 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.1rem; }
        .chat-details h3 { color: #e9edef; font-size: 1rem; font-weight: 500; margin-bottom: 2px; }
        .chat-status { color: #8696a0; font-size: 0.8rem; }
        .chat-actions { display: flex; gap: 10px; }
        .chat-actions a { background: none; border: none; color: #8696a0; font-size: 1.2rem; cursor: pointer; padding: 8px; border-radius: 50%; transition: all 0.3s ease; text-decoration: none; }
        .chat-actions a:hover { background: #313d45; color: #e9edef; }
        .chat-messages { flex: 1; overflow-y: auto; padding: 20px; background: #0b141a; background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.15) 1px, transparent 0); background-size: 20px 20px; }
        .message { display: flex; margin-bottom: 15px; animation: fadeIn 0.3s ease; }
        .message.sent { justify-content: flex-end; }
        .message-bubble { max-width: 70%; padding: 10px 15px; border-radius: 12px; }
        .message.received .message-bubble { background: #202c33; color: #e9edef; border-bottom-left-radius: 4px; }
        .message.sent .message-bubble { background: #005c4b; color: #e9edef; border-bottom-right-radius: 4px; }
        .message-content { margin-bottom: 5px; line-height: 1.4; }
        .message-time { font-size: 0.7rem; color: #8696a0; display: flex; align-items: center; gap: 5px; justify-content: flex-end; }
        .message.sent .message-time { justify-content: flex-end; }
        .chat-input-container { background: #202c33; padding: 15px 20px; border-top: 1px solid #313d45; }
        .chat-input-wrapper { display: flex; align-items: center; gap: 10px; }
        .chat-input-form { flex: 1; display: flex; align-items: center; gap: 10px; }
        .chat-input { flex: 1; background: #2a3942; border: none; border-radius: 20px; padding: 12px 20px; color: #e9edef; font-size: 0.9rem; resize: none; max-height: 100px; }
        .chat-input::placeholder { color: #8696a0; }
        .chat-input:focus { outline: none; background: #313d45; }
        .send-button { background: #25d366; border: none; border-radius: 50%; width: 45px; height: 45px; color: white; font-size: 1.1rem; cursor: pointer; transition: all 0.3s ease; }
        .send-button:hover { background: #128c7e; transform: scale(1.05); }
        .welcome-screen { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #0b141a; text-align: center; padding: 40px; }
        .welcome-icon { font-size: 8rem; color: #8696a0; margin-bottom: 30px; }
        .welcome-screen h2 { color: #e9edef; font-size: 2rem; margin-bottom: 15px; font-weight: 300; }
        .welcome-screen p { color: #8696a0; font-size: 1rem; line-height: 1.6; max-width: 500px; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 768px) { .sidebar { width: 100%; position: absolute; z-index: 10; transform: translateX(-100%); transition: transform 0.3s ease; } .sidebar.active { transform: translateX(0); } .main-chat { width: 100%; } }
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #202c33; } ::-webkit-scrollbar-thumb { background: #8696a0; border-radius: 3px; } ::-webkit-scrollbar-thumb:hover { background: #e9edef; }
    </style>
</head>
<body>
    <div class="whatsapp-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="user-profile">
                    <div class="user-avatar"><?php echo getInitials($username); ?></div>
                    <div class="user-name"><?php echo escape($username); ?></div>
                </div>
                <div class="header-actions">
                    <a href="dashboard.php" title="Retour"><i class="fas fa-arrow-left"></i></a>
                </div>
            </div>
            <div class="search-bar">
                <input type="text" class="search-input" placeholder="Rechercher un contact...">
            </div>
            <div class="conversations-list">
                <?php if (empty($otherUserId)): ?>
                    <div class="empty-conversations">
                        <i class="fas fa-users"></i>
                        <h3>Aucun contact sélectionné</h3>
                        <p>Sélectionnez un contact pour commencer à discuter.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="main-chat">
            <?php if (!empty($otherUserId)): ?>
                <div class="chat-header">
                    <div class="chat-info">
                        <div class="chat-avatar"><?php echo getInitials($otherUsername); ?><div class="status-indicator"></div></div>
                        <div class="chat-details">
                            <h3><?php echo escape($otherUsername); ?></h3>
                            <div class="chat-status">En ligne</div>
                        </div>
                    </div>
                    <div class="chat-actions">
                        <a href="dashboard.php" title="Retour"><i class="fas fa-arrow-left"></i></a>
                    </div>
                </div>
                <div class="chat-messages" id="chatMessages">
                    <?php foreach ($messages as $message): ?>
                        <div class="message <?php echo $message['sender'] == $userId ? 'sent' : 'received'; ?>">
                            <div class="message-bubble">
                                <div class="message-content"><?php echo escape($message['content']); ?></div>
                                <div class="message-time"><?php echo formatDate($message['timestamp']); ?>
                                    <?php if ($message['sender'] == $userId): ?><i class="fas fa-check-double" style="color: #53bdeb;"></i><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="chat-input-container">
                    <form class="chat-input-form" method="POST" action="">
                        <input type="hidden" name="user_id" value="<?php echo escape($otherUserId); ?>">
                        <textarea class="chat-input" name="message_content" placeholder="Tapez votre message..." rows="1" required></textarea>
                        <button type="submit" class="send-button" name="send_message" title="Envoyer"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>
            <?php else: ?>
                <div class="chat-header">
                    <div class="chat-info">
                        <div class="chat-avatar"><?php echo getInitials($username); ?></div>
                        <div class="chat-details">
                            <h3>Bienvenue</h3>
                            <div class="chat-status">Sélectionnez un contact</div>
                        </div>
                    </div>
                    <div class="chat-actions">
                        <a href="contacts.php" title="Contacts"><i class="fas fa-users"></i></a>
                    </div>
                </div>
                <div class="chat-messages">
                    <div class="welcome-screen">
                        <div class="welcome-icon"><i class="fas fa-comment"></i></div>
                        <h2>Bienvenue, <?php echo escape($username); ?>!</h2>
                        <p>Sélectionnez un contact pour commencer à discuter.</p>
                    </div>
                </div>
                <div class="chat-input-container">
                    <div class="message-bubble">Sélectionnez un contact pour discuter.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
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

        const messagesContainer = document.getElementById('chatMessages');
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        setInterval(function() {
            if (document.querySelector('.chat-messages')) {
                location.reload();
            }
        }, 30000);
    </script>
</body>
</html>