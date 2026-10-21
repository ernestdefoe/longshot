import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import type { Person } from '../../common/types';

/** A member's avatar and name, linked to their profile, from the book's own payload. */
export default function person(p: Person | null): Mithril.Children {
  if (!p) return <span className="Longshot-person">{app.translator.trans('core.lib.username.deleted_text')}</span>;

  const initial = (p.displayName || p.username || '?').charAt(0).toUpperCase();

  return (
    <Link className="Longshot-person" href={app.route('user', { username: p.slug })}>
      {p.avatarUrl ? (
        <img className="Avatar" src={p.avatarUrl} alt="" loading="lazy" decoding="async" />
      ) : (
        <span className="Avatar" aria-hidden="true">
          {initial}
        </span>
      )}
      <span className="username">{p.displayName}</span>
    </Link>
  );
}
