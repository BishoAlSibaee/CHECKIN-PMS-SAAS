import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TranslateModule } from '@ngx-translate/core';
import { PermissionService } from './permission.service';
import { DataService } from './Api/data.service';
import { provideHttpClient, withInterceptorsFromDi } from '@angular/common/http';

describe('PermissionService', () => {
  let service: PermissionService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
    imports: [TranslateModule.forRoot()],
    providers: [PermissionService, DataService, provideHttpClient(withInterceptorsFromDi()), provideHttpClientTesting()]
});
    service = TestBed.inject(PermissionService);
    httpMock = TestBed.inject(HttpTestingController);
    TestBed.inject(DataService).userToken$ = 'test-token';
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('getPermissions should map API permissions', () => {
    service.getPermissions().subscribe(list => {
      expect(list.length).toBe(1);
      expect(list[0].key).toBe('users_view');
    });

    const req = httpMock.expectOne(r => r.url.includes('users/getPermissions'));
    req.flush({
      success: true,
      data: [{
        id: 1,
        name: 'users_view',
        name_en: 'View users',
        name_ar: 'عرض المستخدمين',
        category: { name_en: 'Users & Permissions' },
      }],
    });
  });

  it('hasPermission should check user permission list', () => {
    expect(service.hasPermission(['users_view', 'users_manage'], 'users_view')).toBe(true);
    expect(service.hasPermission(['users_view'], 'users_delete')).toBe(false);
  });

  it('validatePermissionCombination should return valid for empty list', () => {
    const result = service.validatePermissionCombination([]);
    expect(result.isValid).toBe(true);
    expect(result.errors).toEqual([]);
  });

  it('resolveDependencies should not duplicate keys', () => {
    const resolved = service.resolveDependencies(['users_view', 'users_view']);
    expect(resolved.length).toBe(1);
  });
});
