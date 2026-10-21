import css from './generated/pagesCss';

/** The book's styles, added once, the first time one of its pages renders. */
export default function useStyles(): void {
  if (document.getElementById('longshot-styles')) return;
  const style = document.createElement('style');
  style.id = 'longshot-styles';
  style.textContent = css;
  document.head.appendChild(style);
}
