<?php
require_once 'config.php';

function createGroup($name, $adminId) {
    $xml = simplexml_load_file(GROUPS_XML);
    
    $group = $xml->addChild('group');
    $group->addAttribute('id', 'g' . uniqid());
    $group->addChild('name', htmlspecialchars($name));
    $members = $group->addChild('members');
    $members->addChild('member', $adminId);
    $group->addChild('admin', $adminId);
    return $xml->asXML(GROUPS_XML);
}

function addMemberToGroup($groupId, $userId) {
    $xml = simplexml_load_file(GROUPS_XML);
    
    foreach ($xml->group as $group) {
        if ($group['id'] == $groupId) {
            $group->members->addChild('member', $userId);
            return $xml->asXML(GROUPS_XML);
        }
    }
    return false;
}

function getGroups($userId) {
    $xml = simplexml_load_file(GROUPS_XML);
    $groups = [];
    
    foreach ($xml->group as $group) {
        foreach ($group->members->member as $member) {
            if ($member == $userId) {
                $groups[] = [
                    'id' => (string)$group['id'],
                    'name' => (string)$group->name,
                    'admin' => (string)$group->admin
                ];
                break;
            }
        }
    }
    return $groups;
}
?>