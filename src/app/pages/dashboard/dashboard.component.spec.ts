import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { of } from 'rxjs';
import { DashboardComponent } from './dashboard.component';
import { HotelService } from '../../services/hotel.service';
import { DataService } from '../../services/Api/data.service';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { RealtimeService } from '../../services/realtime.service';

describe('DashboardComponent realtime', () => {
  let component: DashboardComponent;
  let fixture: ComponentFixture<DashboardComponent>;
  let scopeHandler: (() => void) | undefined;
  let loadSummarySpy: jasmine.Spy;

  beforeEach(async () => {
    scopeHandler = undefined;

    await TestBed.configureTestingModule({
      declarations: [DashboardComponent],
      imports: [TranslateModule.forRoot()],
      providers: [
        {
          provide: HotelService,
          useValue: {
            fetchDashboardSummary: () => of({ success: true, data: {} }),
            fetchCollectionsSummary: () => of({ success: true, data: null }),
          },
        },
        { provide: DataService, useValue: {} },
        { provide: AuthPermissionService, useValue: { has: () => true } },
        { provide: ConfirmDialogService, useValue: { confirm: () => Promise.resolve(true) } },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        {
          provide: RealtimeService,
          useValue: {
            onScope: (_scope: string, handler: () => void) => {
              scopeHandler = handler;
              return () => undefined;
            },
          },
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(DashboardComponent);
    component = fixture.componentInstance;
    loadSummarySpy = spyOn<any>(component, 'loadSummary').and.stub();
    fixture.detectChanges();
  });

  it('subscribes to dashboard and soft reloads on event', () => {
    expect(scopeHandler).toBeDefined();
    scopeHandler!();
    expect(loadSummarySpy).toHaveBeenCalledWith({ soft: true });
  });
});
