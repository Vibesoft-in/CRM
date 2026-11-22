<?php
// app/Notifications/Channels/NotificationChannelInterface.php
namespace App\Notifications\Channels;

interface NotificationChannelInterface {
    /**
     * Get the identifier name of the channel (crm, email, web_push, mobile_push)
     */
    public function getName(): string;

    /**
     * Send notification payload to user
     * 
     * @param array $notification [type, title, message, link, payload]
     * @param array $user [id, name, email, employee_id, etc.]
     * @param array $config Channel-specific configuration
     * @return array ['success' => bool, 'response' => string, 'error' => ?string]
     */
    public function send(array $notification, array $user, array $config = []): array;
}
