import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { RouterTestingModule } from '@angular/router/testing';
import { TranslateModule } from '@ngx-translate/core';
import { AuthInterceptor } from './auth.interceptor';
import { DataService } from '../services/Api/data.service';
import { provideHttpClient, withInterceptorsFromDi } from '@angular/common/http';

describe('AuthInterceptor', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
    imports: [RouterTestingModule, TranslateModule.forRoot()],
    providers: [AuthInterceptor, DataService, provideHttpClient(withInterceptorsFromDi()), provideHttpClientTesting()]
});
  });

  it('should be created', () => {
    const interceptor = TestBed.inject(AuthInterceptor);
    expect(interceptor).toBeTruthy();
  });
});
