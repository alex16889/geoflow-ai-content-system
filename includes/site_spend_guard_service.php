<?php
/**
 * Per-site spend guardrails for paid external APIs.
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class SiteSpendGuardService {
    public const PROVIDER_DATAFORSEO = 'dataforseo';

    public static function evaluateBudget(float $dailyBudget, float $todaySpend, float $estimatedCost): array {
        $dailyBudget = max(0.0, $dailyBudget);
        $todaySpend = max(0.0, $todaySpend);
        $estimatedCost = max(0.0, $estimatedCost);

        if ($dailyBudget <= 0.0) {
            return [
                'allowed' => true,
                'daily_budget' => 0.0,
                'today_spend' => $todaySpend,
                'estimated_cost' => $estimatedCost,
                'remaining' => null,
            ];
        }

        $remaining = max(0.0, $dailyBudget - $todaySpend);
        return [
            'allowed' => ($todaySpend + $estimatedCost) <= $dailyBudget,
            'daily_budget' => $dailyBudget,
            'today_spend' => $todaySpend,
            'estimated_cost' => $estimatedCost,
            'remaining' => $remaining,
        ];
    }

    public static function estimateDataForSeoCost(int $seedCount, int $limit): float {
        $seedCount = max(1, $seedCount);
        $limit = max(1, $limit);
        $batches = max(1, (int) ceil($limit / 100));
        return round($seedCount * $batches * 0.02, 6);
    }

    public static function dataForSeoDailyBudget(): float {
        if (!function_exists('get_setting')) {
            return 5.0;
        }

        $value = (float) get_setting('dataforseo_daily_budget_usd', '5');
        return max(0.0, $value);
    }

    public static function todaySpend(PDO $db, int $siteId, string $provider): float {
        if (!geoflow_db_table_exists($db, 'site_api_spend')) {
            return 0.0;
        }

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount_usd), 0)
            FROM site_api_spend
            WHERE site_id = ?
              AND provider = ?
              AND spent_at >= CURRENT_DATE
              AND spent_at < CURRENT_DATE + INTERVAL '1 day'
        ");
        $stmt->execute([$siteId, $provider]);
        return (float) $stmt->fetchColumn();
    }

    public static function assertCanSpend(PDO $db, int $siteId, string $provider, float $estimatedCost, ?float $dailyBudget = null): array {
        $dailyBudget = $dailyBudget ?? self::dataForSeoDailyBudget();
        $todaySpend = self::todaySpend($db, $siteId, $provider);
        $evaluation = self::evaluateBudget($dailyBudget, $todaySpend, $estimatedCost);

        if (!$evaluation['allowed']) {
            throw new RuntimeException(sprintf(
                '当前站点今日 %s 预算不足：预算 $%.4f，已用 $%.4f，预估本次 $%.4f',
                $provider,
                $evaluation['daily_budget'],
                $evaluation['today_spend'],
                $evaluation['estimated_cost']
            ));
        }

        return $evaluation;
    }

    public static function recordSpend(PDO $db, int $siteId, string $provider, float $amountUsd, array $context = []): void {
        if ($siteId <= 0 || $amountUsd < 0 || !geoflow_db_table_exists($db, 'site_api_spend')) {
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO site_api_spend (site_id, provider, event_type, amount_usd, units, description, metadata_json, spent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $siteId,
            $provider,
            (string) ($context['event_type'] ?? 'api_call'),
            $amountUsd,
            (int) ($context['units'] ?? 0),
            (string) ($context['description'] ?? ''),
            json_encode($context['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
