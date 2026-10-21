import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import { t } from '../common/t';
import LeaguePicker from './components/LeaguePicker';
import BookManager from './components/BookManager';

const facts = () =>
  (app.forum.attribute('longshotAdmin') ?? { points: false, picks: false, leagues: {} }) as {
    points: boolean;
    picks: boolean;
    leagues: Record<string, string>;
  };
const s = (k: string) => t('admin.settings.' + k);

export default [
  new Extend.Admin()
    .customSetting(() => <p className="Longshot-adminNote">{t('admin.points_only')}</p>)
    .setting(() => ({ setting: 'ernestdefoe-longshot.show_nav', type: 'boolean', label: s('show_nav') }))
    .setting(() => ({
      setting: 'ernestdefoe-longshot.currency',
      type: 'select',
      label: s('currency'),
      help: facts().points ? s('currency_help') : s('currency_help_unavailable'),
      options: facts().points ? { own: s('currency_own'), points: s('currency_points') } : { own: s('currency_own') },
      default: 'own',
    }))
    .setting(() => ({
      setting: 'ernestdefoe-longshot.currency_name',
      type: 'text',
      label: s('currency_name'),
      help: s('currency_name_help'),
      placeholder: s('currency_name_placeholder'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-longshot.starting_balance',
      type: 'number',
      min: 0,
      label: s('starting_balance'),
      help: s('starting_balance_help'),
    }))
    .setting(() => ({ setting: 'ernestdefoe-longshot.allowance', type: 'number', min: 0, label: s('allowance'), help: s('allowance_help') }))
    .setting(() => ({
      setting: 'ernestdefoe-longshot.allowance_mode',
      type: 'select',
      label: s('allowance_mode'),
      options: { add: s('allowance_add'), topup: s('allowance_topup') },
      default: 'add',
    }))
    .setting(() => ({ setting: 'ernestdefoe-longshot.min_stake', type: 'number', min: 1, label: s('min_stake') }))
    .setting(() => ({ setting: 'ernestdefoe-longshot.max_stake', type: 'number', min: 1, label: s('max_stake'), help: s('max_stake_help') }))
    .setting(() => ({ setting: 'ernestdefoe-longshot.daily_limit', type: 'number', min: 0, label: s('daily_limit'), help: s('daily_limit_help') }))
    .setting(() => ({ setting: 'ernestdefoe-longshot.max_payout', type: 'number', min: 1, label: s('max_payout'), help: s('max_payout_help') }))
    .setting(() => ({ setting: 'ernestdefoe-longshot.max_legs', type: 'number', min: 2, max: 12, label: s('max_legs') }))
    .setting(() => ({
      setting: 'ernestdefoe-longshot.accept_better_odds',
      type: 'boolean',
      label: s('accept_better_odds'),
      help: s('accept_better_odds_help'),
    }))
    .setting(() => ({ setting: 'ernestdefoe-longshot.public_history', type: 'boolean', label: s('public_history'), help: s('public_history_help') }))
    .customSetting(() =>
      facts().picks ? <h3 className="Longshot-adminHeading">{s('picks_heading')}</h3> : <p className="helpText">{s('picks_missing')}</p>
    )
    .setting(
      () =>
        (facts().picks
          ? { setting: 'ernestdefoe-longshot.picks_import', type: 'boolean', label: s('picks_import'), help: s('picks_import_help') }
          : null) as any
    )
    .customSetting(() => (facts().picks ? <LeaguePicker leagues={facts().leagues} /> : null))
    .setting(
      () =>
        (facts().picks ? { setting: 'ernestdefoe-longshot.horizon_days', type: 'number', min: 1, max: 30, label: s('horizon_days') } : null) as any
    )
    .setting(
      () =>
        (facts().picks
          ? { setting: 'ernestdefoe-longshot.feed_odds', type: 'boolean', label: s('feed_odds'), help: s('feed_odds_help') }
          : null) as any
    )
    .setting(
      () =>
        (facts().picks
          ? {
              setting: 'ernestdefoe-longshot.auto_void_days',
              type: 'number',
              min: 0,
              max: 60,
              label: s('auto_void_days'),
              help: s('auto_void_days_help'),
            }
          : null) as any
    )
    .customSetting(() => <BookManager />)
    .permission(() => ({ icon: 'fas fa-ticket', label: t('admin.permissions.view'), permission: 'longshot.view', allowGuest: true }), 'view')
    .permission(() => ({ icon: 'fas fa-coins', label: t('admin.permissions.bet'), permission: 'longshot.bet' }), 'reply')
    .permission(() => ({ icon: 'fas fa-right-left', label: t('admin.permissions.exchange'), permission: 'longshot.exchange' }), 'reply')
    .permission(() => ({ icon: 'fas fa-scale-balanced', label: t('admin.permissions.manage'), permission: 'longshot.manage' }), 'moderate'),
];
