import app from 'flarum/common/app';

/** This extension's strings, by the part of the key after `ernestdefoe-longshot.`. */
export const t = (k: string, p?: any): any => app.translator.trans('ernestdefoe-longshot.' + k, p);
