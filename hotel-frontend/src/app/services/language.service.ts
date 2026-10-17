import { DOCUMENT } from '@angular/common';
import { ApplicationRef, Inject, Injectable, NgZone, OnDestroy, Renderer2, RendererFactory2 } from '@angular/core';
import { LangChangeEvent, TranslateService } from '@ngx-translate/core';
import { BehaviorSubject, filter, map, Observable, of, race, switchMap, take, tap, timer } from 'rxjs';

export type AppLang = 'en' | 'ar';

@Injectable({
  providedIn: 'root',
})
export class LanguageService implements OnDestroy {
  private readonly renderer: Renderer2;
  private readonly languageChangedSubject = new BehaviorSubject<AppLang>('en');
  private translationEventsSub?: { unsubscribe: () => void };

  /** Emits whenever the active UI language is ready to render. */
  readonly changes$ = this.languageChangedSubject.asObservable();

  constructor(
    private translate: TranslateService,
    private appRef: ApplicationRef,
    private ngZone: NgZone,
    rendererFactory: RendererFactory2,
    @Inject(DOCUMENT) private document: Document,
  ) {
    this.renderer = rendererFactory.createRenderer(null, null);
  }

  init(): void {
    if (!this.translationEventsSub) {
      this.translationEventsSub = this.translate.onTranslationChange.subscribe(() => {
        this.runChangeDetection();
      });
    }

    this.translate.addLangs(['en', 'ar']);
    this.translate.setDefaultLang('en');

    const saved = localStorage.getItem('language');
    const browser = this.translate.getBrowserLang();
    const lang: AppLang = saved === 'ar' || saved === 'en'
      ? saved
      : browser?.startsWith('ar')
        ? 'ar'
        : 'en';

    this.apply(lang, { persist: false }).subscribe();
  }

  get current(): AppLang {
    return this.languageChangedSubject.value;
  }

  toggle(): Observable<AppLang> {
    const next: AppLang = this.current === 'en' ? 'ar' : 'en';
    return this.apply(next);
  }

  apply(lang: AppLang, options?: { persist?: boolean }): Observable<AppLang> {
    if (this.translate.currentLang === lang) {
      return of(lang).pipe(
        tap(() => this.onLanguageApplied(lang, options)),
      );
    }

    const langReady$ = this.translate.onLangChange.pipe(
      filter((event) => this.toAppLang(event.lang) === lang),
      take(1),
    );

    return this.translate.use(lang).pipe(
      switchMap((pending) => {
        if (pending && typeof (pending as { subscribe?: unknown }).subscribe === 'function') {
          return (pending as Observable<unknown>).pipe(
            take(1),
            switchMap(() => race(
              langReady$,
              timer(50).pipe(map(() => ({ lang } as LangChangeEvent))),
            )),
          );
        }

        return race(
          langReady$,
          timer(50).pipe(map(() => ({ lang } as LangChangeEvent))),
        );
      }),
      tap((event) => this.onLanguageApplied(this.toAppLang(event.lang), options)),
      map(() => lang),
    );
  }

  ngOnDestroy(): void {
    this.translationEventsSub?.unsubscribe();
  }

  private onLanguageApplied(lang: AppLang, options?: { persist?: boolean }): void {
    if (options?.persist !== false) {
      localStorage.setItem('language', lang);
    }
    this.applyDirection(lang);
    this.languageChangedSubject.next(lang);
    this.runChangeDetection();
  }

  private runChangeDetection(): void {
    this.ngZone.run(() => this.appRef.tick());
  }

  private applyDirection(lang: AppLang): void {
    const dir = lang === 'ar' ? 'rtl' : 'ltr';
    this.renderer.setAttribute(this.document.documentElement, 'dir', dir);
    this.renderer.setAttribute(this.document.documentElement, 'lang', lang);
  }

  private toAppLang(lang: string): AppLang {
    return lang === 'ar' ? 'ar' : 'en';
  }
}
