<?php
/**
 * Maps a GEOFlow article row onto a publisher item.
 * Unreviewed rows stay drafts so the file pipeline refuses to publish them.
 */

declare(strict_types=1);

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class ArticlePublisherBridge
{
    public static function toItem(array $article): array
    {
        $idNumber = (int) ($article['id'] ?? 0);
        if ($idNumber <= 0) {
            throw new PublisherException('Article cannot be exported without an id', 'validation_failed');
        }

        $title = trim((string) ($article['title'] ?? ''));
        $body = trim((string) ($article['content'] ?? ''));
        if ($title === '' || $body === '') {
            throw new PublisherException('Article cannot be exported without title and body', 'validation_failed');
        }

        $slug = strtolower(trim((string) ($article['slug'] ?? '')));
        if ($slug === '') {
            $slug = 'article-' . $idNumber;
        }

        $reviewStatus = trim((string) ($article['review_status'] ?? 'pending'));
        $reviewed = in_array($reviewStatus, ['approved', 'auto_approved'], true);

        return [
            'id' => 'article-' . $idNumber,
            'slug' => $slug,
            'title' => $title,
            'body' => $body,
            'status' => $reviewed ? 'reviewed' : 'draft',
        ];
    }
}
