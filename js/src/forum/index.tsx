import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import { t } from '../common/t';

export { default as extend } from './extend';

/*
 * Everything here runs on every page for every visitor, so it is only the
 * wiring: a nav link, a profile tab and the alert types. The book is a chunk.
 */
app.initializers.add('ernestdefoe-longshot', () => {
  const canView = () => !!app.forum.attribute('canViewLongshot');

  extend(IndexSidebar.prototype, 'navItems', (items) => {
    if (!canView() || app.forum.attribute('longshotShowNav') === false) return;

    items.add(
      'longshot',
      <LinkButton href={app.route('longshot.board')} icon="fas fa-ticket">
        {t('forum.nav')}
      </LinkButton>,
      4
    );
  });

  extend(UserPage.prototype, 'navItems', function (this: UserPage, items) {
    if (!canView() || !this.user) return;

    items.add(
      'longshot',
      <LinkButton href={app.route('user.longshot', { username: this.user.slug() })} icon="fas fa-ticket">
        {t('forum.profile_tab')}
      </LinkButton>,
      5
    );
  });

  // By module name: NotificationGrid is in a lazy chunk in Flarum 2.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', (items: any) => {
    if (!canView()) return;

    items.add('longshotBetSettled', { name: 'longshotBetSettled', icon: 'fas fa-ticket', label: t('forum.settings.notify_settled') });
    items.add('longshotOfferMatched', { name: 'longshotOfferMatched', icon: 'fas fa-right-left', label: t('forum.settings.notify_matched') });
    items.add('longshotChallenge', { name: 'longshotChallenge', icon: 'fas fa-handshake', label: t('forum.settings.notify_challenge') });
  });
});
