<?php
require_once 'config.php';

function sendMessage($senderId, $receiverId, $receiverType, $content) {
    // Validate inputs
    if (empty($senderId) || empty($receiverId) || empty($content)) {
        throw new Exception("Sender ID, receiver ID, or content is empty");
    }

    // Load XML file
    if (!file_exists(MESSAGES_XML)) {
        file_put_contents(MESSAGES_XML, '<?xml version="1.0" encoding="UTF-8"?><messages></messages>');
    }

    $xml = simplexml_load_file(MESSAGES_XML);
    if ($xml === false) {
        error_log("Failed to load XML file: " . MESSAGES_XML);
        throw new Exception("Unable to load messages XML file");
    }

    // Add new message
    $message = $xml->addChild('message');
    $message->addAttribute('id', 'm' . uniqid());
    $message->addChild('sender', htmlspecialchars($senderId));
    $receiver = $message->addChild('receiver', htmlspecialchars($receiverId));
    $receiver->addAttribute('type', htmlspecialchars($receiverType));
    $message->addChild('content', htmlspecialchars($content));
    $message->addChild('timestamp', date('c'));
    $message->addChild('type', 'text');
    $message->addChild('is_read', '0'); // Added is_read field

    // Save XML file
    if (!$xml->asXML(MESSAGES_XML)) {
        error_log("Failed to save XML file: " . MESSAGES_XML);
        throw new Exception("Unable to save message to XML file");
    }
    return true;
}

function getMessages($userId) {
    // Validate input
    if (empty($userId)) {
        return [];
    }

    // Load XML file
    if (!file_exists(MESSAGES_XML)) {
        return [];
    }

    $xml = simplexml_load_file(MESSAGES_XML);
    if ($xml === false) {
        error_log("Failed to load XML file: " . MESSAGES_XML);
        return [];
    }

    $messages = [];
    foreach ($xml->message as $message) {
        if ($message->sender == $userId || ($message->receiver == $userId && $message->receiver['type'] == 'user')) {
            $partnerId = ($message->sender == $userId) ? $message->receiver : $message->sender;
            $messages[] = [
                'id' => (string)$message['id'],
                'sender' => (string)$message->sender,
                'receiver' => (string)$message->receiver,
                'receiver_type' => (string)$message->receiver['type'],
                'content' => (string)$message->content,
                'timestamp' => (string)$message->timestamp,
                'is_read' => (string)$message->is_read,
                'partner_name' => getPartnerName($partnerId) // Assumes a function to get partner name
            ];
        }
    }
    return $messages;
}

// Placeholder function to get partner name (replace with actual implementation)
function getPartnerName($userId) {
    // This should query your database to get the username for $userId
    // Example: Replace with your database query logic
    return "User_" . $userId; // Temporary placeholder
}
?>