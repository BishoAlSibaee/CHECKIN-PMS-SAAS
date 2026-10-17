import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { TranslateModule } from '@ngx-translate/core';
import { LoginComponent } from '../../pages/login/login.component';
import { GuestGuard } from '../../guards/guest.guard';

const routes: Routes = [
  { path: '', component: LoginComponent, canActivate: [GuestGuard] }
];

@NgModule({
  declarations: [LoginComponent],
  imports: [
    CommonModule,
    FormsModule,
    TranslateModule,
    RouterModule.forChild(routes)
  ]
})
export class AuthModule {}
