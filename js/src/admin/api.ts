import app from 'flarum/admin/app';

const base = () => `${app.forum.attribute('apiUrl')}/longshot/admin`;

export function adminGet<T = any>(path: string, params: Record<string, any> = {}): Promise<T> {
  return app.request<T>({ method: 'GET', url: base() + path, params });
}

export function adminSend<T = any>(method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: any): Promise<T> {
  return app.request<T>({ method, url: base() + path, body });
}
