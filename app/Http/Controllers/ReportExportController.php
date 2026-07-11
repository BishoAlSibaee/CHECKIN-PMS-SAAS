<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessReportExportJob;
use App\Models\ReportExport;
use App\Services\Reports\ReportExportProcessor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportExportController extends Controller
{
    public function __construct(private ReportExportProcessor $exportProcessor) {}

    public function downloadNow(string $slug, Request $request)
    {
        if (! ReportExportProcessor::isValidSlug($slug)) {
            return Failed('Unknown report slug.', 422);
        }

        if (ReportExportProcessor::slugRequiresAccountingPermission($slug)) {
            $user = auth()->user();
            if (! $user || ! $user->can('view accounting reports')) {
                return Failed('You do not have permission to export this report.', 403);
            }
        }

        $validated = $request->validate([
            'format' => 'required|in:excel,pdf',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'compare_start_date' => 'nullable|date',
            'compare_end_date' => 'nullable|date|after_or_equal:compare_start_date',
        ]);

        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        if ($slug !== 'room-board' && (! $startDate || ! $endDate)) {
            throw ValidationException::withMessages([
                'start_date' => ['Start date and end date are required.'],
            ]);
        }

        if ($slug === 'room-board' && ! $endDate) {
            throw ValidationException::withMessages([
                'end_date' => ['Snapshot date is required for room board report.'],
            ]);
        }

        $format = $validated['format'] === 'pdf'
            ? ReportExport::FORMAT_PDF
            : ReportExport::FORMAT_EXCEL;

        $params = array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'compare_start_date' => $validated['compare_start_date'] ?? null,
            'compare_end_date' => $validated['compare_end_date'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $file = $this->exportProcessor->buildDownload($slug, $format, $params);
        } catch (\Throwable $e) {
            return Failed($e->getMessage());
        }

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function requestEmail(string $slug, Request $request)
    {
        if (! ReportExportProcessor::isValidSlug($slug)) {
            return Failed('Unknown report slug.', 422);
        }

        if (ReportExportProcessor::slugRequiresAccountingPermission($slug)) {
            $user = auth()->user();
            if (! $user || ! $user->can('view accounting reports')) {
                return Failed('You do not have permission to export this report.', 403);
            }
        }

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'format' => 'nullable|in:excel,pdf',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'compare_start_date' => 'nullable|date',
            'compare_end_date' => 'nullable|date|after_or_equal:compare_start_date',
        ]);

        $userId = auth()->id();
        $lock = Cache::lock('report-export:user:'.$userId.':'.$slug, 120);

        if (! $lock->get()) {
            return Failed('A report export for this report is already being queued. Please wait.', 429);
        }

        try {
            $pendingCount = ReportExport::query()
                ->where('user_id', $userId)
                ->where('status', ReportExport::STATUS_PENDING)
                ->count();

            if ($pendingCount >= (int) config('reports.max_pending_per_user', 3)) {
                return Failed('You already have pending report requests. Please wait until they are processed.', 429);
            }

            $startDate = $validated['start_date'] ?? null;
            $endDate = $validated['end_date'] ?? null;

            if ($slug !== 'room-board' && (! $startDate || ! $endDate)) {
                throw ValidationException::withMessages([
                    'start_date' => ['Start date and end date are required.'],
                ]);
            }

            if ($slug === 'room-board' && ! $endDate) {
                throw ValidationException::withMessages([
                    'end_date' => ['Snapshot date is required for room board report.'],
                ]);
            }

            $export = ReportExport::create([
                'user_id' => $userId,
                'slug' => $slug,
                'file_format' => $validated['format'] ?? ReportExport::FORMAT_EXCEL,
                'recipient_email' => $validated['email'],
                'start_date' => $startDate ? Carbon::parse($startDate)->toDateString() : null,
                'end_date' => $endDate ? Carbon::parse($endDate)->toDateString() : null,
                'compare_start_date' => ! empty($validated['compare_start_date'])
                    ? Carbon::parse($validated['compare_start_date'])->toDateString() : null,
                'compare_end_date' => ! empty($validated['compare_end_date'])
                    ? Carbon::parse($validated['compare_end_date'])->toDateString() : null,
                'status' => ReportExport::STATUS_PENDING,
                'download_token' => ReportExport::generateToken(),
            ]);

            ProcessReportExportJob::dispatch($export->id);
        } finally {
            $lock->release();
        }

        return SuccessData('Report export queued. You will receive an email when it is ready.', [
            'export' => [
                'id' => $export->id,
                'status' => $export->status,
                'format' => $export->file_format,
                'email' => $export->recipient_email,
                'slug' => $export->slug,
                'created_at' => $export->created_at?->toDateTimeString(),
            ],
        ], 202);
    }

    public function index(Request $request)
    {
        $exports = ReportExport::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (ReportExport $export) => [
                'id' => $export->id,
                'slug' => $export->slug,
                'format' => $export->file_format,
                'email' => $export->recipient_email,
                'status' => $export->status,
                'row_count' => $export->row_count,
                'start_date' => $export->start_date?->toDateString(),
                'end_date' => $export->end_date?->toDateString(),
                'download_url' => $export->status === ReportExport::STATUS_READY && ! $export->isExpired()
                    ? $export->downloadUrl() : null,
                'expires_at' => $export->expires_at?->toDateTimeString(),
                'error_message' => $export->error_message,
                'created_at' => $export->created_at?->toDateTimeString(),
            ]);

        return SuccessData('Report exports', ['exports' => $exports]);
    }

    public function download(ReportExport $export, Request $request)
    {
        $token = (string) $request->query('token', '');
        if ($token === '' || ! hash_equals($export->download_token, $token)) {
            return Failed('Invalid download link.', 403);
        }

        $user = auth('api')->user();
        if ($user && (int) $export->user_id !== (int) $user->id) {
            return Failed('Invalid download link.', 403);
        }

        if ($export->status !== ReportExport::STATUS_READY) {
            return Failed('Report is not ready yet.', 404);
        }

        if ($export->isExpired()) {
            return Failed('Download link has expired.', 410);
        }

        if (! $export->file_path || ! Storage::disk('local')->exists($export->file_path)) {
            return Failed('Report file not found.', 404);
        }

        $filename = $export->slug.'-'.($export->end_date?->format('Y-m-d') ?? $export->id).'.'.$export->fileExtension();

        return Storage::disk('local')->download($export->file_path, $filename, [
            'Content-Type' => $export->mimeType(),
        ]);
    }
}
