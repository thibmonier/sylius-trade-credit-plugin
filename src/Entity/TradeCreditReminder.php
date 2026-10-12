<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Entity;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * A payment reminder sent for an order paid on account, at a level (days relative to the due
 * date, negative before it). Unique per order, due date and level: never sent twice. Written in
 * SQL by `ReminderSender`; read-only here.
 */
class TradeCreditReminder
{
    protected ?string $id = null;

    protected ?OrderInterface $order = null;

    protected \DateTimeImmutable $dueDate;

    protected int $level = 0;

    protected string $email = '';

    protected \DateTimeImmutable $sentAt;

    public function __construct()
    {
        $this->dueDate = new \DateTimeImmutable('today');
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
