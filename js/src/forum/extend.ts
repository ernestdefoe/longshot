import Extend from 'flarum/common/extenders';
import { BetSettledNotification, ChallengeNotification, OfferMatchedNotification } from './components/Notifications';

// Every page is in one chunk, fetched the first time the book is opened.
const page = (name: string) => () => import('./pages/Book').then((module: any) => ({ default: module[name] }));

export default [
  new Extend.Notification()
    .add('longshotBetSettled', BetSettledNotification)
    .add('longshotOfferMatched', OfferMatchedNotification)
    .add('longshotChallenge', ChallengeNotification),

  new Extend.Routes()
    .add('longshot.board', '/sportsbook', page('BoardPage'))
    .add('longshot.results', '/sportsbook/results', page('BoardPage'))
    .add('longshot.bets', '/sportsbook/bets', page('BetsPage'))
    .add('longshot.exchange', '/sportsbook/exchange', page('ExchangePage'))
    .add('longshot.leaderboard', '/sportsbook/leaderboard', page('LeaderboardPage'))
    .add('longshot.wallet', '/sportsbook/wallet', page('WalletPage'))
    .add('longshot.event', '/sportsbook/event/:id', page('EventPage'))
    .add('user.longshot', '/u/:username/bets', page('UserBetsPage')),
];
