import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { of } from 'rxjs';
import { CollectionsComponent } from './collections.component';
import { HotelService } from '../../services/hotel.service';
import { DataService } from '../../services/Api/data.service';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { RealtimeService } from '../../services/realtime.service';

describe('CollectionsComponent realtime', () => {
  let component: CollectionsComponent;
  let fixture: ComponentFixture<CollectionsComponent>;
  let scopeHandler: (() => void) | undefined;

  beforeEach(async () => {
    scopeHandler = undefined;

    await TestBed.configureTestingModule({
      declarations: [CollectionsComponent],
      imports: [TranslateModule.forRoot()],
      providers: [
        { provide: ActivatedRoute, useValue: { queryParams: of({}) } },
        { provide: Router, useValue: { events: of(), url: '/booking/collections' } },
        {
          provide: HotelService,
          useValue: {
            fetchCollectionsSummary: () => of({ success: true, data: null }),
            fetchCollections: () => of({ success: true, data: [], total: 0, lastPage: 1 }),
          },
        },
        { provide: DataService, useValue: {} },
        { provide: AuthPermissionService, useValue: { has: () => true } },
        { provide: ConfirmDialogService, useValue: { confirm: () => Promise.resolve(true) } },
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

    fixture = TestBed.createComponent(CollectionsComponent);
    component = fixture.componentInstance;
    spyOn<any>(component, 'loadSummary').and.stub();
    spyOn<any>(component, 'loadList').and.stub();
    spyOn(component, 'ngOnInit').and.callFake(function (this: CollectionsComponent) {
      (this as any).realtimeOff = TestBed.inject(RealtimeService).onScope('collections', () => {
        (this as any).loadSummary(true);
        (this as any).loadList(true);
      });
    });
    component.ngOnInit();
  });

  it('subscribes to collections and soft reloads summary and list', () => {
    expect(scopeHandler).toBeDefined();
    scopeHandler!();
    expect((component as any).loadSummary).toHaveBeenCalledWith(true);
    expect((component as any).loadList).toHaveBeenCalledWith(true);
  });
});
