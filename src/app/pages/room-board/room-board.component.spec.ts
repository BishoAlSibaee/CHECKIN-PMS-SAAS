import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, Router } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { of } from 'rxjs';
import { RoomBoardComponent } from './room-board.component';
import { HotelService } from '../../services/hotel.service';
import { DataService } from '../../services/Api/data.service';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { RealtimeService } from '../../services/realtime.service';

describe('RoomBoardComponent realtime', () => {
  let component: RoomBoardComponent;
  let fixture: ComponentFixture<RoomBoardComponent>;
  let scopeHandler: (() => void) | undefined;
  let loadBoardSpy: jasmine.Spy;

  beforeEach(async () => {
    scopeHandler = undefined;

    await TestBed.configureTestingModule({
      declarations: [RoomBoardComponent],
      imports: [TranslateModule.forRoot()],
      providers: [
        {
          provide: HotelService,
          useValue: {
            fetchBuildings: () => of({ success: true, data: [] }),
            getBuildingsSync: () => [],
            getRoomOccupancyBoard: () => of({ success: true, data: { summary: {}, rooms: [] } }),
          },
        },
        { provide: DataService, useValue: {} },
        { provide: AuthPermissionService, useValue: { has: () => true } },
        { provide: ConfirmDialogService, useValue: { confirm: () => Promise.resolve(true) } },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: { queryParamMap: convertToParamMap({}) },
          },
        },
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

    fixture = TestBed.createComponent(RoomBoardComponent);
    component = fixture.componentInstance;
    loadBoardSpy = spyOn(component, 'loadBoard').and.stub();
    component.ngOnInit();
  });

  it('subscribes to occupancy_board and soft reloads on event', () => {
    expect(scopeHandler).toBeDefined();
    scopeHandler!();
    expect(loadBoardSpy).toHaveBeenCalledWith({ soft: true });
  });
});
