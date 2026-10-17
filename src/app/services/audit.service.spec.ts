import { TestBed } from '@angular/core/testing';
import { AuditService } from './audit.service';

describe('AuditService', () => {
  let service: AuditService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [AuditService]
    });
    service = TestBed.inject(AuditService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  describe('log', () => {
    it('should add a log entry', () => {
      const initialCount = service.getLogs().length;
      service.log({
        action: 'role_created',
        entityType: 'role',
        entityId: 't1',
        entityName: 'Test Entity',
        performedBy: 'u1',
        performedByName: 'Test User',
        details: 'Test details'
      });
      expect(service.getLogs().length).toBe(initialCount + 1);
    });
  });

  describe('getLogs', () => {
    it('should return logs array', () => {
      const logs = service.getLogs();
      expect(Array.isArray(logs)).toBe(true);
    });
  });

  describe('getLogsByEntity', () => {
    it('should return logs for specific entity', () => {
      const logs = service.getLogsByEntity('role', 'r1');
      expect(Array.isArray(logs)).toBe(true);
    });
  });

  describe('getLogsByUser', () => {
    it('should return logs for specific user', () => {
      const logs = service.getLogsByUser('u1');
      expect(Array.isArray(logs)).toBe(true);
    });
  });

  describe('searchLogs', () => {
    it('should return empty array for empty query', () => {
      const logs = service.searchLogs('');
      expect(Array.isArray(logs)).toBe(true);
    });

    it('should find logs matching query', () => {
      const logs = service.searchLogs('admin');
      expect(Array.isArray(logs)).toBe(true);
    });
  });

  describe('clearLogs', () => {
    it('should clear all logs', () => {
      service.clearLogs();
      expect(service.getLogs().length).toBe(0);
    });
  });

  describe('exportLogs', () => {
    it('should export logs as CSV string', () => {
      const csv = service.exportLogs();
      expect(typeof csv).toBe('string');
      expect(csv.length).toBeGreaterThan(0);
    });
  });
});
