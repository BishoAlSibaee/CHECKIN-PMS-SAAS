/** True when end is strictly before start (invalid report period). */
export function isReportDateRangeInvalid(start: string, end: string): boolean {
  if (!start || !end) {
    return false;
  }
  return end < start;
}

/** Keep end on or after start when the user changes dates. */
export function syncReportEndDate(start: string, end: string): string {
  if (!start || !end || end >= start) {
    return end;
  }
  return start;
}

/** Keep start on or before end when the user changes dates. */
export function syncReportStartDate(start: string, end: string): string {
  if (!start || !end || start <= end) {
    return start;
  }
  return end;
}
