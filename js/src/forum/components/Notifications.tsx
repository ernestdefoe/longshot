import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import username from 'flarum/common/helpers/username';
import { t } from '../../common/t';

/*
 * The three alerts. Small and dependency-free: the notification list renders
 * them on any page, so they cannot wait for the book's chunk.
 */

const amount = (n: number) => n.toLocaleString();

export class BetSettledNotification extends Notification {
  icon() {
    return 'fas fa-ticket';
  }

  href() {
    return app.route('longshot.bets');
  }

  content() {
    const data = this.attrs.notification.content() as { status: string; payout: number; stake: number };

    return t('forum.notifications.bet_' + data.status, { payout: amount(data.payout), stake: amount(data.stake) });
  }

  excerpt() {
    return null;
  }
}

export class OfferMatchedNotification extends Notification {
  icon() {
    return 'fas fa-right-left';
  }

  href() {
    return app.route('longshot.exchange');
  }

  content() {
    const data = this.attrs.notification.content() as { back: number };

    return t('forum.notifications.matched', { username: username(this.attrs.notification.fromUser()), back: amount(data.back) });
  }

  excerpt() {
    return null;
  }
}

export class ChallengeNotification extends Notification {
  icon() {
    return 'fas fa-handshake';
  }

  href() {
    const data = this.attrs.notification.content() as { event: number };

    return app.route('longshot.event', { id: data.event });
  }

  content() {
    return t('forum.notifications.challenge', { username: username(this.attrs.notification.fromUser()) });
  }

  excerpt() {
    return null;
  }
}
