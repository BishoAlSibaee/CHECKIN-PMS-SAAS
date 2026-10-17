import { HotelService } from './hotel.service';

describe('HotelService.extractCreatedReservationId', () => {
  let service: HotelService;

  beforeEach(() => {
    service = Object.create(HotelService.prototype) as HotelService;
  });

  it('reads id from detail payload', () => {
    expect(service.extractCreatedReservationId({ data: { reservation: { id: 42 } } })).toBe('42');
  });

  it('reads legacy flat id', () => {
    expect(service.extractCreatedReservationId({ data: { id: 7 } })).toBe('7');
  });

  it('returns null when id is missing', () => {
    expect(service.extractCreatedReservationId({ data: {} })).toBeNull();
    expect(service.extractCreatedReservationId({})).toBeNull();
  });
});
