<?php
/**
 * Safe publisher failure. Messages are written for operators and must not
 * include filesystem paths or stack traces.
 */

declare(strict_types=1);

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class PublisherException extends RuntimeException
{
    public string $errorCode;
    public array $errors;

    public function __construct(string $message, string $errorCode = 'publisher_error', array $errors = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->errors = $errors;
    }
}
