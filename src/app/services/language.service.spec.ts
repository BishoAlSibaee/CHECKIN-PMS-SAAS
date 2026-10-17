import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { TranslateModule, TranslateService } from '@ngx-translate/core';
import { of } from 'rxjs';
import { LanguageService } from './language.service';

describe('LanguageService', () => {
  let service: LanguageService;
  let translate: TranslateService;

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      imports: [TranslateModule.forRoot()],
      providers: [LanguageService],
    });

    service = TestBed.inject(LanguageService);
    translate = TestBed.inject(TranslateService);
    spyOn(translate, 'use').and.returnValue(of({}));
    spyOn(translate, 'addLangs');
    spyOn(translate, 'setDefaultLang');
    spyOn(translate, 'getBrowserLang').and.returnValue('en');
    Object.defineProperty(translate, 'currentLang', { get: () => 'en', configurable: true });
  });

  it('persists and applies toggled language after onLangChange', fakeAsync(() => {
    let resolved = '';
    service.toggle().subscribe((lang) => {
      resolved = lang;
    });

    translate.onLangChange.emit({ lang: 'ar', translations: {} });
    tick(50);

    expect(resolved).toBe('ar');
    expect(localStorage.getItem('language')).toBe('ar');
    expect(document.documentElement.getAttribute('dir')).toBe('rtl');
  }));
});
