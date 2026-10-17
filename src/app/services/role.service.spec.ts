import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TranslateModule } from '@ngx-translate/core';
import { RoleService } from './role.service';
import { PermissionService } from './permission.service';
import { DataService } from './Api/data.service';
import { provideHttpClient, withInterceptorsFromDi } from '@angular/common/http';

describe('RoleService', () => {
  let service: RoleService;
  let httpMock: HttpTestingController;
  let dataService: DataService;

  beforeEach(() => {
    TestBed.configureTestingModule({
    imports: [TranslateModule.forRoot()],
    providers: [
        RoleService,
        PermissionService,
        DataService,
        provideHttpClient(withInterceptorsFromDi()),
        provideHttpClientTesting(),
    ]
});
    service = TestBed.inject(RoleService);
    httpMock = TestBed.inject(HttpTestingController);
    dataService = TestBed.inject(DataService);
    dataService.userToken$ = 'test-token';
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('getRoles should map API response to Role[]', () => {
    service.getRoles().subscribe(roles => {
      expect(roles.length).toBe(1);
      expect(roles[0].name).toBe('Admin');
      expect(roles[0].isSystem).toBe(true);
    });

    const req = httpMock.expectOne(r => r.url.includes('users/getRoles'));
    expect(req.request.method).toBe('GET');
    req.flush({
      success: true,
      data: [{
        id: 1,
        name: 'Admin',
        name_ar: 'مدير',
        description: 'System admin',
        is_system: 1,
        permissions: [{ name: 'manage users' }],
        created_at: '2026-01-01',
        updated_at: '2026-01-01',
      }],
    });
  });

  it('deleteRole should POST to deleteRole endpoint', () => {
    service.deleteRole('5', 'u1').subscribe(res => {
      expect(res.success).toBe(true);
    });

    const req = httpMock.expectOne(r => r.url.includes('users/deleteRole'));
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ id: '5' });
    req.flush({ success: true });
  });
});
