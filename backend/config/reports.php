<?php

return [
    'page_size' => (int) env('REPORT_PAGE_SIZE', 50),
    'preview_limit' => (int) env('REPORT_PREVIEW_LIMIT', 500),
    /** Max rows for immediate PDF/Excel/CSV download. Larger sets → email or tighter filters. */
    'max_direct_download_rows' => (int) env('REPORT_MAX_DIRECT_DOWNLOAD_ROWS', 40),
    'pdf_rows_per_page' => (int) env('REPORT_PDF_ROWS_PER_PAGE', 20),
    'export_retention_days' => (int) env('REPORT_EXPORT_RETENTION_DAYS', 7),
    'max_pending_per_user' => (int) env('REPORT_MAX_PENDING_PER_USER', 3),
];
