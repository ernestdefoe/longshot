import type { OddsFormat } from '../common/odds';

/** How this viewer likes odds shown. Per browser, and harmless if storage is blocked. */
export const prefs = {
  get odds(): OddsFormat {
    try {
      return localStorage.getItem('longshot.odds') === 'decimal' ? 'decimal' : 'american';
    } catch (e) {
      return 'american';
    }
  },
  set odds(value: OddsFormat) {
    try {
      localStorage.setItem('longshot.odds', value);
    } catch (e) {
      // Not remembered; shown for this page only.
    }
  },
};
