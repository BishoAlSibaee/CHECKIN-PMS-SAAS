import { Injectable } from '@angular/core';

export interface AuditLog {
  id: string;
  timestamp: string;
  action: string;
  entityType: string;
  entityId: string;
  entityName: string;
  performedBy: string;
  performedByName: string;
  details: string;
}

/** In-memory audit log used by RBAC unit tests (no API yet). */
@Injectable({ providedIn: 'root' })
export class AuditService {
  private logs: AuditLog[] = [];

  log(entry: Omit<AuditLog, 'id' | 'timestamp'>): void {
    this.logs.unshift({
      ...entry,
      id: `log-${Date.now()}-${this.logs.length}`,
      timestamp: new Date().toISOString(),
    });
  }

  getLogs(): AuditLog[] {
    return [...this.logs];
  }

  getLogsByEntity(entityType: string, entityId: string): AuditLog[] {
    return this.logs.filter(l => l.entityType === entityType && l.entityId === entityId);
  }

  getLogsByUser(userId: string): AuditLog[] {
    return this.logs.filter(l => l.performedBy === userId);
  }

  searchLogs(query: string): AuditLog[] {
    if (!query.trim()) {
      return [];
    }
    const q = query.toLowerCase();
    return this.logs.filter(l =>
      l.action.toLowerCase().includes(q) ||
      l.entityName.toLowerCase().includes(q) ||
      l.details.toLowerCase().includes(q)
    );
  }

  clearLogs(): void {
    this.logs = [];
  }

  clear(): void {
    this.clearLogs();
  }

  exportLogs(): string {
    const header = 'id,timestamp,action,entityType,entityId,entityName,performedBy,details';
    const rows = this.logs.map(l =>
      [l.id, l.timestamp, l.action, l.entityType, l.entityId, l.entityName, l.performedBy, l.details].join(',')
    );
    return [header, ...rows].join('\n');
  }
}
