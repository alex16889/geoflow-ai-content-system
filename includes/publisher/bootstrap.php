<?php

declare(strict_types=1);

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/PublisherException.php';
require_once __DIR__ . '/PublisherClock.php';
require_once __DIR__ . '/FileTransaction.php';
require_once __DIR__ . '/ArticleBridge.php';
require_once __DIR__ . '/Publisher.php';
