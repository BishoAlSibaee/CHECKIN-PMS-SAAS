<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reports\ReportQueryService;
use Illuminate\Console\Command;

class SiteQaAuditCommand extends Command
{
    protected $signature = 'site:qa-audit {--json : Output JSON only}';

    protected $description = 'Run a site-wide QA audit (routes, reports, data sanity)';

    public function handle(ReportQueryService $reportQueryService): int
    {
        $report = [
            'generated_at' => now()->toDateTimeString(),
            'database' => $this->auditDatabase(),
            'reports' => $this->auditReports($reportQueryService),
            'routes' => $this->auditUiRoutes(),
            'issues' => [],
        ];

        $report['issues'] = array_merge(
            $report['database']['issues'] ?? [],
            $report['reports']['issues'] ?? []
        );

        $report['summary'] = [
            'report_slugs_ok' => $report['reports']['passed'] ?? 0,
            'report_slugs_failed' => count($report['reports']['failures'] ?? []),
            'issue_count' => count($report['issues']),
            'status' => empty($report['issues']) && empty($report['reports']['failures'] ?? []) ? 'PASS' : 'WARN',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Site QA Audit — '.$report['generated_at']);
        $this->newLine();
        $this->line('Database: users='.$report['database']['users']
            .', clients='.$report['database']['clients']
            .', reservations='.$report['database']['reservations']);
        $this->line('Reports: '.($report['reports']['passed'] ?? 0).'/'.($report['reports']['total'] ?? 0).' OK');

        if (! empty($report['reports']['failures'])) {
            $this->warn('Report failures:');
            foreach ($report['reports']['failures'] as $fail) {
                $this->line("  - {$fail['slug']}: {$fail['error']}");
            }
        }

        if (! empty($report['issues'])) {
            $this->warn('Issues:');
            foreach ($report['issues'] as $issue) {
                $this->line('  - '.$issue);
            }
        } else {
            $this->info('No data issues detected.');
        }

        $this->newLine();
        $this->line('UI routes mapped: '.count($report['routes']));
        $this->line('Overall: '.$report['summary']['status']);

        return $report['summary']['status'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }

    private function auditDatabase(): array
    {
        $issues = [];
        $users = User::count();
        $clients = Client::count();
        $reservations = Reservation::count();

        if ($users === 0) {
            $issues[] = 'No users in database — login will fail.';
        }
        if ($clients === 0) {
            $issues[] = 'No clients — reservation create UI cannot select guests.';
        }
        if ($reservations === 0) {
            $issues[] = 'No reservations — dashboard/reports may look empty.';
        }

        $unbalanced = Reservation::query()
            ->where('reservation_status', 1)
            ->get()
            ->filter(function ($r) {
                $paid = (float) $r->payments()->where('type', 0)->sum('pay');
                $refunded = (float) $r->payments()->where('type', 1)->sum('pay');

                return $paid - $refunded > (float) $r->total + 0.01;
            })
            ->count();

        if ($unbalanced > 0) {
            $issues[] = "{$unbalanced} confirmed reservation(s) have paid amount exceeding total.";
        }

        return compact('users', 'clients', 'reservations', 'issues');
    }

    private function auditReports(ReportQueryService $service): array
    {
        $slugs = [
            'overview', 'room-board', 'accrual-revenue', 'cash-box', 'chart-of-accounts', 'journal-entries',
            'arrivals-departures', 'reservations-list', 'occupancy', 'revenue-summary',
            'accrual-cash-reconciliation', 'ar-aging', 'adjustments', 'tax', 'revpar',
            'by-dimension', 'peak-analysis', 'payments-refunds', 'closing-package',
            'general-ledger', 'trial-balance', 'cash-flow',
            'financial-audit-log',
        ];

        $params = ['start_date' => '2026-08-01', 'end_date' => '2026-08-31'];
        $passed = 0;
        $failures = [];
        $issues = [];

        foreach ($slugs as $slug) {
            try {
                $data = $service->run($slug, $params);
                if (! isset($data['columns'], $data['rows'])) {
                    throw new \RuntimeException('Missing columns/rows in response');
                }
                $passed++;
            } catch (\Throwable $e) {
                $failures[] = ['slug' => $slug, 'error' => $e->getMessage()];
            }
        }

        return [
            'total' => count($slugs),
            'passed' => $passed,
            'failures' => $failures,
            'issues' => $issues,
        ];
    }

    private function auditUiRoutes(): array
    {
        return [
            ['path' => '/#/dashboard', 'section' => 'Dashboard'],
            ['path' => '/#/dashboard/room-board', 'section' => 'Room Board'],
            ['path' => '/#/booking/reservations', 'section' => 'Reservations'],
            ['path' => '/#/booking/reservations/create', 'section' => 'Create Reservation'],
            ['path' => '/#/booking/peak-periods', 'section' => 'Peak Periods'],
            ['path' => '/#/reports', 'section' => 'Reports Hub'],
            ['path' => '/#/reports/run/overview', 'section' => 'Financial Overview Report'],
            ['path' => '/#/reports/run/accrual-revenue', 'section' => 'Accrual Revenue Report'],
            ['path' => '/#/reports/run/cash-box', 'section' => 'Cash Box Report'],
            ['path' => '/#/reports/run/revenue-summary', 'section' => 'Report Runner'],
            ['path' => '/#/reports/run/chart-of-accounts', 'section' => 'Chart of Accounts Report'],
            ['path' => '/#/reports/run/journal-entries', 'section' => 'Journal Entries Report'],
            ['path' => '/#/admin/property', 'section' => 'Property'],
            ['path' => '/#/admin/pricing', 'section' => 'Pricing'],
            ['path' => '/#/admin/users', 'section' => 'Users'],
            ['path' => '/#/admin/clients', 'section' => 'Clients'],
            ['path' => '/#/admin/departments', 'section' => 'Departments'],
            ['path' => '/#/admin/job-titles', 'section' => 'Job Titles'],
            ['path' => '/#/admin/penalties', 'section' => 'Penalties'],
            ['path' => '/#/admin/discounts', 'section' => 'Discounts'],
            ['path' => '/#/admin/guests', 'section' => 'Guests'],
            ['path' => '/#/auth', 'section' => 'Login'],
        ];
    }
}
