<?php

namespace App\Notifications;

use StellarSecurity\Notifications\DTO\NotificationEvent;

final class NotificationEventWithSender extends NotificationEvent
{
    private const SUPPORT_EMAIL = 'support@stellarsecurity.com';

    public function __construct(
        private readonly NotificationEvent $event,
        private readonly string $fromEmail,
        private readonly ?string $fromName = null,
    ) {
    }

    public static function support(NotificationEvent $event): self
    {
        return new self($event, self::SUPPORT_EMAIL);
    }

    public static function from(NotificationEvent $event, string $fromEmail, ?string $fromName = null): self
    {
        return new self($event, $fromEmail, $fromName);
    }

    public function toArray(): array
    {
        $data = $this->event->toArray();
        $data['from_email'] = $this->fromEmail;

        if ($this->fromName !== null && trim($this->fromName) !== '') {
            $data['from_name'] = $this->fromName;
        }

        return $data;
    }
}
