import { Injectable } from '@angular/core';
import { CanDeactivate } from '@angular/router';
import { from, isObservable, Observable } from 'rxjs';

export interface ComponentCanDeactivate {
  canDeactivate: () => boolean | Promise<boolean> | Observable<boolean>;
}

@Injectable({
  providedIn: 'root'
})
export class PendingChangesGuard implements CanDeactivate<ComponentCanDeactivate> {
  canDeactivate(component: ComponentCanDeactivate): boolean | Observable<boolean> {
    if (!component?.canDeactivate) {
      return true;
    }

    const result = component.canDeactivate();
    if (typeof result === 'boolean') {
      return result;
    }
    if (isObservable(result)) {
      return result;
    }
    return from(Promise.resolve(result));
  }
}
