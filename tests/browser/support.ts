import { expect, type APIRequestContext, type Page, type Request } from '@playwright/test';
import fs from 'node:fs';

export type Fixture = {
  siteId: number;
  nested: { published: number; disabled: number; pending: number; expired: number };
  adminUsername: string;
  password: string;
  users: { member: string; moderator: string; deleter: string };
  fields: { rating: number; likes: number; favorites: number; iconLikes: number };
  entries: {
    published: { id: number; url: string };
    disabled: { id: number };
    xss: { id: number; url: string };
    plain: { id: number; url: string };
    draft: { id: number };
    revision: { id: number };
    pending: { id: number };
    expired: { id: number };
  };
};

export function fixture(): Fixture {
  const file = process.env.ENGAGEMENT_BROWSER_FIXTURE;
  if (!file || !fs.existsSync(file)) {
    throw new Error('ENGAGEMENT_BROWSER_FIXTURE is missing; run through `npm run test:browser` so global setup seeds db_test.');
  }
  return JSON.parse(fs.readFileSync(file, 'utf8')) as Fixture;
}

/** Path portion of a seeded entry URL (the seed knows the absolute site URL). */
export function pathOf(url: string): string {
  return new URL(url).pathname;
}

export const ACTIONS = {
  rating: /\/actions\/engagement\/ratings\/cast-rating$/,
  likes: /\/actions\/engagement\/likes\/cast-like$/,
  favorites: /\/actions\/engagement\/favorites\/toggle-favorite$/,
};

/** Craft supports both path URLs and index.php?p=actions/... URLs. */
export function actionPath(url: string): string {
  const parsed = new URL(url);
  return parsed.searchParams.has('p') ? '/' + parsed.searchParams.get('p')!.replace(/^\//, '') : parsed.pathname;
}

/** Wait for the widget's POST and return its JSON body together with the parsed response. */
export async function captureVote(page: Page, action: RegExp, trigger: () => Promise<void>) {
  const [request] = await Promise.all([
    page.waitForRequest((r: Request) => r.method() === 'POST' && action.test(actionPath(r.url()))),
    trigger(),
  ]);
  const response = await request.response();
  expect(response, 'vote request got a response').not.toBeNull();
  return {
    request,
    body: request.postDataJSON() as Record<string, unknown>,
    headers: request.headers(),
    status: response!.status(),
    json: (await response!.json()) as any,
  };
}

/** Log a front-end user in through Craft's JSON login action so cookies land in the page context. */
export async function frontEndLogin(page: Page, username: string, password: string) {
  await page.goto('/');
  const result = await page.evaluate(async ({ username, password }) => {
    const info = await (await fetch('/actions/users/session-info', { headers: { Accept: 'application/json' } })).json();
    const response = await fetch('/actions/users/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': info.csrfTokenValue },
      body: JSON.stringify({ loginName: username, password }),
    });
    return { status: response.status, body: await response.text() };
  }, { username, password });
  expect(result.status, `login as ${username}: ${result.body}`).toBe(200);
}

export async function cpLogin(page: Page, username: string, password: string) {
  await page.goto('/admin/login');
  await page.getByLabel('Username or Email').first().fill(username);
  await page.getByLabel('Password', { exact: true }).first().fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(url => !url.pathname.startsWith('/admin/login'));
  await expect(page.getByRole('button', { name: /sign in/i })).toHaveCount(0);
}

/** A CSRF token tied to the given request context's session. */
export async function csrfToken(request: APIRequestContext): Promise<string> {
  const response = await request.get('/actions/users/session-info', { headers: { Accept: 'application/json' } });
  expect(response.ok()).toBeTruthy();
  return (await response.json()).csrfTokenValue;
}

export async function postAction(request: APIRequestContext, action: string, data: Record<string, unknown>, withCsrf = true) {
  const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' };
  if (withCsrf) headers['X-CSRF-Token'] = await csrfToken(request);
  return request.post(`/actions/${action}`, { headers, data });
}
