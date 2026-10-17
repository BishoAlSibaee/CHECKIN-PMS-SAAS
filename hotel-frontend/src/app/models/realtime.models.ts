export type HotelLiveScope =
  | 'occupancy_board'
  | 'dashboard'
  | 'reservations'
  | 'reservation_detail'
  | 'collections'
  | 'property'
  | 'airbnb_requests'
  | 'notifications';

export interface HotelLiveEntity {
  type: string;
  id: number | string;
}

export interface HotelLivePayload {
  board_version: number;
  scopes: HotelLiveScope[];
  action: string;
  entity?: HotelLiveEntity | null;
  occurred_at?: string;
}

export type HotelLiveHandler = (payload: HotelLivePayload) => void;
