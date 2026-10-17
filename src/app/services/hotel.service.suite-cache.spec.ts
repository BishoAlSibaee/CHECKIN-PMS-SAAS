import { TestBed } from '@angular/core/testing';
import { provideHttpClient, withInterceptorsFromDi } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HotelService } from './hotel.service';
import { DataService } from './Api/data.service';

describe('HotelService suite cache refresh', () => {
  let service: HotelService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        HotelService,
        provideHttpClient(withInterceptorsFromDi()),
        provideHttpClientTesting(),
        {
          provide: DataService,
          useValue: {
            Url: '/api/',
            getHeaders: () => ({ Accept: 'application/json' }),
            showToast: jasmine.createSpy('showToast'),
          },
        },
      ],
    });

    service = TestBed.inject(HotelService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('refreshes rooms after adding a suite so assigned rooms leave the standalone list', () => {
    const params = { building_id: 1, floor_id: 9 };
    let currentRooms: any[] = [];
    let addResult: any;

    service.getRooms(params).subscribe((rooms) => {
      currentRooms = rooms;
    });

    service.addSuite({
      building_id: 1,
      floor_id: 9,
      number: 'S-901',
      rooms: [{ id: 901 }],
    }).subscribe((res) => {
      addResult = res;
    });

    httpMock.expectOne('/api/users/addSuite').flush({ success: true, message: 'Suite added' });

    const suitesRequest = httpMock.expectOne((req) =>
      req.method === 'GET' &&
      req.url === '/api/users/suites' &&
      req.params.get('building_id') === '1' &&
      req.params.get('floor_id') === '9'
    );
    suitesRequest.flush({
      success: true,
      data: {
        data: [
          { id: 1, number: 'S-901', rooms: [{ id: 901, suite_id: 1 }] },
        ],
      },
    });

    const roomsRequest = httpMock.expectOne((req) =>
      req.method === 'GET' &&
      req.url === '/api/users/rooms' &&
      req.params.get('building_id') === '1' &&
      req.params.get('floor_id') === '9'
    );
    roomsRequest.flush({
      success: true,
      data: {
        data: [
          { id: 902, number: '902', suite_id: null },
        ],
      },
    });

    expect(addResult.success).toBeTrue();
    expect(currentRooms.map((room) => room.id)).toEqual([902]);
  });
});
