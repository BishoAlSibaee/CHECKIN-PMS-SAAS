<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportCatalogCache;
use App\Services\Reports\ReportQueryService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportQueryService $reportQueryService) {}

    public function catalog()
    {
        $items = ReportCatalogCache::remember(fn () => ReportCatalog::all());

        return SuccessData('Report catalog', ['reports' => $items]);
    }

    public function run(string $slug, Request $request)
    {
        try {
            $data = $this->reportQueryService->run($slug, $request->all());

            $allRows = $data['rows'] ?? [];
            $totalRows = count($allRows);
            $forExport = filter_var($request->input('for_export'), FILTER_VALIDATE_BOOLEAN);

            if ($forExport) {
                $exportLimit = max(1, (int) config('reports.preview_limit', 500));
                $data['rows'] = array_slice($allRows, 0, $exportLimit);
                $data['total_rows'] = $totalRows;
                $data['per_page'] = $exportLimit;
                $data['current_page'] = 1;
                $data['last_page'] = 1;
                $data['preview_limit'] = $exportLimit;
                $data['is_truncated'] = $totalRows > $exportLimit;
            } else {
                $perPage = max(1, min(50, (int) config('reports.page_size', 50)));
                $page = max(1, (int) $request->input('page', 1));
                $lastPage = max(1, (int) ceil($totalRows / $perPage));
                $page = min($page, $lastPage);
                $offset = ($page - 1) * $perPage;

                $data['rows'] = array_slice($allRows, $offset, $perPage);
                $data['total_rows'] = $totalRows;
                $data['per_page'] = $perPage;
                $data['current_page'] = $page;
                $data['last_page'] = $lastPage;
                $data['preview_limit'] = $perPage;
                $data['is_truncated'] = $totalRows > $perPage;
            }

            $data['export_limit'] = max(1, (int) config('reports.preview_limit', 500));

            return SuccessData('Report generated', $data);
        } catch (\InvalidArgumentException $e) {
            return Failed($e->getMessage(), 422);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }
}
