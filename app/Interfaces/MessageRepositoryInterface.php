<?php


namespace App\Interfaces;

interface MessageRepositoryInterface
{
    public function getAllUserConversations(string $userId);
    public function getConversation(string $currentUserId, string $otherUserId);
    public function create(array $data);
    public function getById(string $id);
    public function markAsRead(string $id);
    public function markConversationAsRead(string $currentUserId, string $senderId);
    public function getUnreadCount(string $userId);
    public function getRecentConversations(string $userId);
    public function getUsersForMessaging(string $userId, ?string $search = null, int $limit = 50, int $offset = 0);

}