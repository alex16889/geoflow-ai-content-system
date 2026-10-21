<?php

declare(strict_types=1);

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

interface PublisherClock
{
    public function now(): DateTimeImmutable;
}

class SystemPublisherClock implements PublisherClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

class FrozenPublisherClock implements PublisherClock
{
    private DateTimeImmutable $instant;

    public function __construct(DateTimeImmutable $instant)
    {
        $this->instant = $instant->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
