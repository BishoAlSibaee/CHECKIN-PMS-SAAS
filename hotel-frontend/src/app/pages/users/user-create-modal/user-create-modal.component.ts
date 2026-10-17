import { Component, EventEmitter, OnInit, Output } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { RoleService } from '../../../services/role.service';
import { HotelService } from '../../../services/hotel.service';
import { Role } from '../../../models/rbac.models';
import { TranslateService } from '@ngx-translate/core';

@Component({
    selector: 'app-user-create-modal',
    templateUrl: './user-create-modal.component.html',
    styleUrls: ['./user-create-modal.component.css'],
    standalone: false
})
export class UserCreateModalComponent implements OnInit {
  @Output() closeModal = new EventEmitter<void>();
  @Output() userCreated = new EventEmitter<any>();

  userForm!: FormGroup;
  roles: Role[] = [];
  jobTitles: any[] = [];
  departments: any[] = [];
  discounts: any[] = [];
  isSubmitting = false;
  errorMessage = '';
  currentLang: string;

  constructor(
    private fb: FormBuilder,
    private roleService: RoleService,
    private hotelService: HotelService,
    private translate: TranslateService
  ) {
    this.currentLang = this.translate.currentLang || 'en';
    this.translate.onLangChange.subscribe(event => {
      this.currentLang = event.lang;
    });
  }

  ngOnInit(): void {
    this.userForm = this.fb.group({
      name: ['', [Validators.required, Validators.minLength(2)]],
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required, Validators.minLength(6)]],
      role_id: ['', Validators.required],
      job_number: ['', Validators.required],
      mobile: ['', [Validators.required]],
      jobtitle_id: ['', Validators.required],
      department_id: ['', Validators.required],
      discount_id: [null]
    });

    this.loadRoles();
    this.loadInitialData();
  }

  loadRoles(): void {
    this.roleService.getRoles().subscribe(roles => {
      this.roles = roles;
    });
  }

  loadInitialData(): void {
    forkJoin([
      this.hotelService.fetchJobTitles(),
      this.hotelService.fetchDepartments(),
      this.hotelService.fetchDiscounts()
    ]).subscribe({
      next: () => {
        this.jobTitles = this.hotelService.getJobTitlesSync();
        this.departments = this.hotelService.getDepartmentsSync();
        this.discounts = this.hotelService.getDiscountsSync();
      },
      error: () => {
        this.jobTitles = this.hotelService.getJobTitlesSync();
        this.departments = this.hotelService.getDepartmentsSync();
        this.discounts = this.hotelService.getDiscountsSync();
      }
    });
  }

  onSubmit(): void {
    if (this.userForm.invalid) {
      this.userForm.markAllAsTouched();
      return;
    }

    this.isSubmitting = true;
    this.errorMessage = '';

    this.roleService.createUser(this.userForm.value).subscribe({
      next: (res) => {
        this.isSubmitting = false;
        if (res.success) {
          this.userCreated.emit(res.data);
          this.closeModal.emit();
        } else {
          this.errorMessage = res.message || this.translate.instant('USERS.MESSAGES.FAILED_CREATE_USER');
        }
      },
      error: (err) => {
        this.isSubmitting = false;
        this.errorMessage = err.error?.message || this.translate.instant('USERS.MESSAGES.CREATE_USER_ERROR');
      }
    });
  }
}
