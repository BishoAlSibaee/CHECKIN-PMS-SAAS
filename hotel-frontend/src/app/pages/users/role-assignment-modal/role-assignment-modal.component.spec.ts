import { ComponentFixture, TestBed } from '@angular/core/testing';
import { BehaviorSubject } from 'rxjs';
import { TranslateModule } from '@ngx-translate/core';
import { RoleAssignmentModalComponent } from './role-assignment-modal.component';
import { RoleService } from '../../../services/role.service';
import { PermissionService } from '../../../services/permission.service';
import { Role, UserAccount } from '../../../models/rbac.models';

describe('RoleAssignmentModalComponent', () => {
  let fixture: ComponentFixture<RoleAssignmentModalComponent>;
  let component: RoleAssignmentModalComponent;
  let usersSubject: BehaviorSubject<UserAccount[]>;
  let rolesSubject: BehaviorSubject<Role[]>;

  const roles: Role[] = [
    {
      id: '2',
      name: 'Receptionist',
      name_ar: 'موظف استقبال',
      description: '',
      permissions: ['view reservations'],
      isSystem: true,
      createdAt: '2026-01-01',
      updatedAt: '2026-01-01',
      createdBy: 'Admin',
    },
    {
      id: '5',
      name: 'Accountant',
      name_ar: 'محاسب',
      description: '',
      permissions: ['view revenue'],
      isSystem: false,
      createdAt: '2026-01-01',
      updatedAt: '2026-01-01',
      createdBy: 'Admin',
    },
  ];

  const users: UserAccount[] = [
    {
      id: '10',
      name: 'Sara Ahmed',
      email: 'sara@example.com',
      roleId: '2',
      roleName: 'Receptionist',
      roleName_ar: 'موظف استقبال',
      status: 'active',
      lastActive: 'Now',
      avatar: 'SA',
      createdAt: '2026-01-01',
    },
  ];

  beforeEach(async () => {
    usersSubject = new BehaviorSubject<UserAccount[]>(users);
    rolesSubject = new BehaviorSubject<Role[]>(roles);

    await TestBed.configureTestingModule({
      declarations: [RoleAssignmentModalComponent],
      imports: [TranslateModule.forRoot()],
      providers: [
        {
          provide: RoleService,
          useValue: {
            users$: usersSubject.asObservable(),
            roles$: rolesSubject.asObservable(),
            getSystemRolesSync: () => roles.filter((r) => r.isSystem),
            getUserRolesSync: () => roles.filter((r) => !r.isSystem),
            getRoleByIdSync: (id: string) => roles.find((r) => r.id === id),
            assignRoleToUser: () => usersSubject.asObservable(),
            bulkAssignRole: () => usersSubject.asObservable(),
          },
        },
        {
          provide: PermissionService,
          useValue: {
            getPermissionByKeySync: () => null,
          },
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(RoleAssignmentModalComponent);
    component = fixture.componentInstance;
    component.mode = 'single';
    component.preselectedUserId = '10';
    fixture.detectChanges();
  });

  it('preselects the user current role when opening the modal', () => {
    expect(component.selectedUserId).toBe('10');
    expect(component.selectedRoleId).toBe('2');
    expect(component.selectedRole?.name).toBe('Receptionist');
  });

  it('keeps current role selected after roles catalog refreshes', () => {
    rolesSubject.next([...roles]);
    expect(component.selectedRoleId).toBe('2');
    expect(component.selectedRole?.id).toBe('2');
  });
});
