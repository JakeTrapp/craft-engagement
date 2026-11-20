import { expect, test } from '@playwright/test';
import { cpLogin, fixture, frontEndLogin, postAction } from './support';

/** Create one guest like through the public endpoint and return its aggregate id. */
async function seedLike(request: import('@playwright/test').APIRequestContext) {
  const data = fixture();
  const response = await postAction(request, 'engagement/likes/cast-like', {
    elementId: data.entries.published.id, fieldId: data.fields.likes, siteId: data.siteId, value: 1,
  });
  expect(response.status()).toBe(200);
  const json = await response.json();
  return { aggregateId: json.aggregate.id as number, voteId: json.vote.id as number };
}

test('front-end members cannot reach moderation actions', async ({ page, playwright }) => {
  const data = fixture();
  const guest = await playwright.request.newContext({ baseURL: test.info().project.use.baseURL });
  const { aggregateId, voteId } = await seedLike(guest);
  await guest.dispose();

  await frontEndLogin(page, data.users.member, data.password);
  const status = await page.evaluate(async ({ aggregateId, voteId }) => {
    const info = await (await fetch('/actions/users/session-info', { headers: { Accept: 'application/json' } })).json();
    const body = new URLSearchParams({ type: 'likes', id: String(aggregateId), 'rowIds[]': String(voteId), [info.csrfTokenName]: info.csrfTokenValue });
    const response = await fetch('/actions/engagement/moderation/delete-rows', { method: 'POST', body, headers: { Accept: 'application/json' } });
    return response.status;
  }, { aggregateId, voteId });

  expect([400, 403]).toContain(status);
});

test('moderators without the delete permission can view but not delete', async ({ page, playwright }) => {
  const data = fixture();
  const guest = await playwright.request.newContext({ baseURL: test.info().project.use.baseURL });
  const { aggregateId } = await seedLike(guest);
  await guest.dispose();

  await cpLogin(page, data.users.moderator, data.password);
  await page.goto(`/admin/engagement/moderation/likes/${aggregateId}`);
  await expect(page.locator('table.data tbody tr').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Delete Selected' })).toHaveCount(0);
  await expect(page.locator('[data-eng-row-checkbox]')).toHaveCount(0);

  // Guest identifiers are hashed and shown truncated.
  await expect(page.locator('td .code').first()).toHaveText(/^[0-9a-f]{12}…$/);

  const status = await page.evaluate(async aggregateId => {
    const response = await (window as any).Craft.sendActionRequest('POST', 'engagement/moderation/delete-rows', {
      data: { type: 'likes', id: aggregateId, rowIds: [1] },
    }).catch((e: any) => e.response);
    return response.status;
  }, aggregateId);
  expect(status).toBe(403);
});

test('moderators with the delete permission can delete a vote', async ({ page, playwright }) => {
  const data = fixture();
  const guest = await playwright.request.newContext({ baseURL: test.info().project.use.baseURL });
  const { aggregateId, voteId } = await seedLike(guest);
  await guest.dispose();

  await cpLogin(page, data.users.deleter, data.password);
  await page.goto(`/admin/engagement/moderation/likes/${aggregateId}`);
  const row = page.locator(`[data-eng-row-checkbox][value="${voteId}"]`);
  await expect(row).toBeVisible();
  await row.check();
  page.once('dialog', d => d.accept());
  await page.getByRole('button', { name: 'Delete Selected' }).click();

  await expect(page.getByText('1 rows deleted.')).toBeVisible();
  await expect(page.locator(`[data-eng-row-checkbox][value="${voteId}"]`)).toHaveCount(0);
});
