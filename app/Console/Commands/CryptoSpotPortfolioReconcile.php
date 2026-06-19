<?php

namespace App\Console\Commands;

use App\Services\PortfolioReconciliationService;
use Illuminate\Console\Command;

class CryptoSpotPortfolioReconcile extends Command
{
    protected $signature = 'cryptospot:portfolio-reconcile
        {--portfolio-id= : Portfolio account ID}
        {--fix : Apply safe reconciliation fixes}
        {--json : Output JSON only}';

    protected $description = 'Audit portfolio accounting against trade plans, simulated trades, and transactions.';

    public function handle(PortfolioReconciliationService $service): int
    {
        $portfolioId = $this->option('portfolio-id') ? (int) $this->option('portfolio-id') : null;
        $fix = (bool) $this->option('fix');
        $json = (bool) $this->option('json');

        $report = $service->reconcile($portfolioId, $fix);

        if ($json) {
            $this->line(json_encode($this->withoutAllReports($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->renderHumanReport($report, $fix);
        return self::SUCCESS;
    }

    private function renderHumanReport(array $report, bool $fix): void
    {
        $reports = $report['reports'] ?? $report['all_reports'] ?? [$report];
        $this->info('Portfolio Reconciliation Report');
        $this->line('Mode: '.($fix ? 'FIX (derived account totals only)' : 'DRY-RUN / read-only'));
        $this->line('Overall status: '.strtoupper($report['status'] ?? 'unknown'));

        foreach ($reports as $item) {
            $this->newLine();
            $this->line('Portfolio:');
            foreach ($item['portfolio'] as $key => $value) $this->line('  - '.str_replace('_', ' ', $key).': '.$this->formatValue($value));

            $this->line('Computed:');
            foreach ($item['computed'] as $key => $value) $this->line('  - '.str_replace('_', ' ', $key).': '.$this->formatValue($value));

            $this->line('Diffs:');
            foreach ($item['diffs'] as $key => $value) $this->line('  - '.str_replace('_', ' ', $key).' diff: '.$this->formatValue($value));

            $this->line('Issues:');
            $this->line('  - expired unreleased plans: '.count($item['issues']['expired_unreleased_trade_plans']));
            $this->line('  - closed unreleased trades: '.count($item['issues']['closed_unreleased_trades']));
            $this->line('  - duplicate active opportunities: '.count($item['issues']['duplicate_active_opportunities']));
            $this->line('  - duplicate transactions: '.$this->countDuplicateTransactions($item['issues']['duplicate_transactions']));
            $this->line('  - linkage issues: '.count($item['issues']['linkage_issues']));
            $this->line('  - legacy expired simulated trades: '.count($item['issues']['legacy_expired_simulated_trades']));

            if (! empty($item['recommendations'])) {
                $this->line('Recommendations:');
                foreach ($item['recommendations'] as $recommendation) $this->line('  - '.$recommendation);
            }

            if ($item['fixes_applied']) {
                $this->line('Fixes applied (before => after):');
                foreach ($item['fixes_applied']['after'] as $key => $after) {
                    $this->line('  - '.$key.': '.$this->formatValue($item['fixes_applied']['before'][$key]).' => '.$this->formatValue($after));
                }
            }

            $this->line('Portfolio status: '.strtoupper($item['status']));
        }
    }

    private function withoutAllReports(array $report): array
    {
        unset($report['all_reports']);
        return $report;
    }

    private function countDuplicateTransactions(array $duplicates): int
    {
        return collect($duplicates)->sum(fn ($rows) => count($rows));
    }

    private function formatValue(mixed $value): string
    {
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_SLASHES);
        if (is_bool($value)) return $value ? 'true' : 'false';
        if ($value === null) return 'null';
        return (string) $value;
    }
}
