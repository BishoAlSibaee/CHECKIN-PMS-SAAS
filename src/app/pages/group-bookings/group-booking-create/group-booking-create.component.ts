import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { HotelService } from '../../../services/hotel.service';
import { GroupBookingService } from '../../../services/group-booking.service';
import { DataService } from '../../../services/Api/data.service';
import { Client } from '../../../models/client.interface';

interface MemberFormState {
  client_id: number | null;
  start_date: string;
  expire_date: string;
  stay_reason_id: number | null;
  reservation_source_id: number | null;
  buildingId: number | null;
  availableRooms: any[];
  selectedRoomIds: number[];
  isLoadingRooms: boolean;
}

/**
 * Reuses the same underlying building/date -> available-rooms lookup the
 * single-reservation create screen uses (GET booking-room-availability), but
 * with a simpler flat room list per member instead of the full floor/suite
 * grouped multi-select — a group's members are typically named individually
 * rather than bulk-selected by floor.
 */
@Component({
    selector: 'app-group-booking-create',
    templateUrl: './group-booking-create.component.html',
    styleUrls: ['./group-booking-create.component.css'],
    standalone: false
})
export class GroupBookingCreateComponent implements OnInit {
  name = '';
  companyName = '';
  contactName = '';
  contactMobile = '';
  contactEmail = '';
  notes = '';
  groupDiscountPercent = 0;

  clients: Client[] = [];
  buildings: any[] = [];
  stayReasons: any[] = [];
  reservationSources: any[] = [];

  members: MemberFormState[] = [];

  isSubmitting = false;
  submitError: string | null = null;

  constructor(
    private hotelService: HotelService,
    private groupBookingService: GroupBookingService,
    private translate: TranslateService,
    private dataService: DataService,
    private router: Router,
  ) {}

  ngOnInit(): void {
    this.clients = this.hotelService.getClientsSync();
    this.hotelService.fetchClients().subscribe((clients) => (this.clients = clients));

    this.buildings = this.hotelService.getBuildingsSync();
    this.hotelService.getBuildings().subscribe((res) => {
      this.buildings = res.data?.data || res.data || res;
    });

    this.hotelService.getStayReasons().subscribe((res) => {
      this.stayReasons = res.data?.data || res.data || res;
    });

    this.hotelService.getReservationSources().subscribe((res) => {
      this.reservationSources = res.data?.data || res.data || res;
    });

    this.addMember();
  }

  addMember(): void {
    this.members.push({
      client_id: null,
      start_date: '',
      expire_date: '',
      stay_reason_id: this.stayReasons[0]?.id ?? null,
      reservation_source_id: this.reservationSources[0]?.id ?? null,
      buildingId: this.buildings[0]?.id ?? null,
      availableRooms: [],
      selectedRoomIds: [],
      isLoadingRooms: false,
    });
  }

  removeMember(index: number): void {
    if (this.members.length <= 1) return;
    this.members.splice(index, 1);
  }

  loadRoomsForMember(index: number): void {
    const member = this.members[index];
    if (!member.buildingId || !member.start_date || !member.expire_date) return;

    member.isLoadingRooms = true;
    member.availableRooms = [];
    this.hotelService.getBookingRoomAvailability({
      building_id: member.buildingId,
      start_date: member.start_date,
      expire_date: member.expire_date,
    }).subscribe({
      next: (res) => {
        member.isLoadingRooms = false;
        member.availableRooms = (res.data?.rooms ?? res.data ?? []).filter((r: any) => r.available_for_period);
      },
      error: () => {
        member.isLoadingRooms = false;
      }
    });
  }

  toggleRoom(index: number, roomId: number): void {
    const member = this.members[index];
    const pos = member.selectedRoomIds.indexOf(roomId);
    if (pos >= 0) {
      member.selectedRoomIds.splice(pos, 1);
    } else {
      member.selectedRoomIds.push(roomId);
    }
  }

  isRoomSelected(index: number, roomId: number): boolean {
    return this.members[index].selectedRoomIds.includes(roomId);
  }

  get canSubmit(): boolean {
    if (!this.name.trim() || this.members.length === 0) return false;
    return this.members.every((m) =>
      m.client_id && m.start_date && m.expire_date && m.selectedRoomIds.length > 0
      && m.stay_reason_id && m.reservation_source_id
    );
  }

  submit(): void {
    if (!this.canSubmit || this.isSubmitting) return;

    this.isSubmitting = true;
    this.submitError = null;

    const payload = {
      name: this.name.trim(),
      company_name: this.companyName.trim() || null,
      contact_name: this.contactName.trim() || null,
      contact_mobile: this.contactMobile.trim() || null,
      contact_email: this.contactEmail.trim() || null,
      notes: this.notes.trim() || null,
      group_discount_percent: this.groupDiscountPercent || 0,
      members: this.members.map((m) => ({
        client_id: m.client_id,
        rooms: m.selectedRoomIds.map((id) => ({ room_id: id })),
        start_date: m.start_date,
        expire_date: m.expire_date,
        stay_reason_id: m.stay_reason_id,
        reservation_source_id: m.reservation_source_id,
        rent_type: 0,
        price_calculation_mode: 0,
        reservation_status: 1,
      })),
    };

    this.groupBookingService.create(payload).subscribe({
      next: (res) => {
        this.isSubmitting = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('GROUP_BOOKINGS.CREATE_SUCCESS'), 'success');
          this.router.navigate(['/booking/group-bookings', res.data.group.id]);
        } else {
          this.submitError = res.message || this.translate.instant('GROUP_BOOKINGS.CREATE_ERROR');
        }
      },
      error: (err) => {
        this.isSubmitting = false;
        this.submitError = err.error?.message || this.translate.instant('GROUP_BOOKINGS.CREATE_ERROR');
      }
    });
  }

  cancel(): void {
    this.router.navigate(['/booking/group-bookings']);
  }
}
