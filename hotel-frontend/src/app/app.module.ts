import { NgModule, provideZoneChangeDetection } from '@angular/core';
import { BrowserModule } from '@angular/platform-browser';
import { BrowserAnimationsModule } from '@angular/platform-browser/animations';
import { ReactiveFormsModule } from '@angular/forms';
import { AppRoutingModule } from './app-routing.module';
import { AppComponent } from './app.component';
import { SidebarComponent } from './components/sidebar/sidebar.component';
import { ConfirmDialogComponent } from './components/confirm-dialog/confirm-dialog.component';
import { HttpClient, HTTP_INTERCEPTORS, provideHttpClient, withInterceptorsFromDi } from '@angular/common/http';
import { TranslateModule, TranslateLoader } from '@ngx-translate/core';
import { TranslateHttpLoader } from '@ngx-translate/http-loader';
import { NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { provideCharts, withDefaultRegisterables } from 'ng2-charts';
import { registerLocaleData } from '@angular/common';
import localeAr from '@angular/common/locales/ar';
import { AuthInterceptor } from './interceptors/auth.interceptor';
import { ZoneHttpInterceptor } from './interceptors/zone-http.interceptor';

registerLocaleData(localeAr);

import { environment } from '../environments/environment';

export function HttpLoaderFactory(http: HttpClient) {
  const suffix = environment.i18nVersion ? `.json?v=${environment.i18nVersion}` : '.json';
  return new TranslateHttpLoader(http, './assets/i18n/', suffix);
}

@NgModule({ declarations: [
        AppComponent,
        SidebarComponent,
        ConfirmDialogComponent
    ],
    bootstrap: [AppComponent], imports: [BrowserModule,
        BrowserAnimationsModule,
        ReactiveFormsModule,
        AppRoutingModule,
        NgbModule,
        TranslateModule.forRoot({
            defaultLanguage: 'en',
            loader: {
                provide: TranslateLoader,
                useFactory: HttpLoaderFactory,
                deps: [HttpClient]
            }
        })], providers: [
        provideZoneChangeDetection({ eventCoalescing: true }),
        provideCharts(withDefaultRegisterables()),
        {
            provide: HTTP_INTERCEPTORS,
            useClass: ZoneHttpInterceptor,
            multi: true
        },
        {
            provide: HTTP_INTERCEPTORS,
            useClass: AuthInterceptor,
            multi: true
        },
        provideHttpClient(withInterceptorsFromDi())
    ] })
export class AppModule { }
