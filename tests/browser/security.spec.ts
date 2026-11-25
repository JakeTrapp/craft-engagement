import { expect, test } from '@playwright/test';
import { fixture, pathOf, postAction } from './support';

test.describe('vote endpoint validation', () => {
  test('rejects votes on disabled, pending, expired, draft and revision entries', async ({ request }) => {
    const data = fixture();
    for (const elementId of [data.entries.disabled.id, data.entries.draft.id, data.entries.revision.id, data.entries.pending.id, data.entries.expired.id]) {
      const response = await postAction(request, 'engagement/likes/cast-like', {
        elementId, fieldId: data.fields.likes, siteId: data.siteId, value: 1,
      });
      expect(response.status(), `element ${elementId}`).toBe(404);
    }
  });

  test('rejects a field that is not in the element field layout', async ({ request }) => {
    const data = fixture();
    const response = await postAction(request, 'engagement/ratings/cast-rating', {
      elementId: data.entries.plain.id, fieldId: data.fields.rating, siteId: data.siteId, rating: 3,
    });
    expect(response.status()).toBe(400);
  });

  test('rejects unknown sites, out-of-range ratings and invalid like values', async ({ request }) => {
    const data = fixture();
    const base = { elementId: data.entries.published.id, siteId: data.siteId };
    expect((await postAction(request, 'engagement/ratings/cast-rating', { ...base, fieldId: data.fields.rating, siteId: 999999, rating: 3 })).status()).toBe(400);
    expect((await postAction(request, 'engagement/ratings/cast-rating', { ...base, fieldId: data.fields.rating, rating: 6 })).status()).toBe(400);
    expect((await postAction(request, 'engagement/likes/cast-like', { ...base, fieldId: data.fields.likes, value: 5 })).status()).toBe(400);
  });

  test('requires a CSRF token', async ({ request }) => {
    const data = fixture();
    const response = await postAction(request, 'engagement/likes/cast-like', {
      elementId: data.entries.published.id, fieldId: data.fields.likes, siteId: data.siteId, value: 1,
    }, false);
    expect(response.status()).toBe(400);
  });

  test('guest favorites are refused server-side when the field is members-only', async ({ request }) => {
    const data = fixture();
    const response = await postAction(request, 'engagement/favorites/toggle-favorite', {
      elementId: data.entries.published.id, fieldId: data.fields.favorites, siteId: data.siteId,
    });
    expect(response.status()).toBe(403);
  });
});

test.describe('editor-supplied content is neutralized', () => {
  for (const mode of ['normal', 'deferred'] as const) {
    test(`malicious SVG, color and heading do not execute (${mode})`, async ({ page }) => {
      const data = fixture();
      const dialogs: string[] = [];
      page.on('dialog', d => { dialogs.push(d.message()); void d.dismiss(); });
      await page.goto(pathOf(data.entries.xss.url) + (mode === 'deferred' ? '?mode=deferred' : ''));

      const widget = page.getByTestId('iconLikes');
      await expect(widget.locator('svg path').first()).toBeAttached();
      expect(await page.evaluate(() => (window as any).__engagementXss)).toBeUndefined();
      await expect(widget.locator('[onload], [onclick], script:not([src])').filter({ hasText: '__engagementXss' })).toHaveCount(0);
      await expect(widget.locator('[onload]')).toHaveCount(0);
      expect(await widget.innerHTML()).not.toContain('evil.example');
      await expect(widget.locator('[data-likes-heading]')).toContainText('</script><script>');
      expect(dialogs).toEqual([]);
    });
  }
});


test('nested entries require a published owner', async ({ request }) => {
  const data = fixture();
  for (const state of ['published', 'disabled', 'pending', 'expired'] as const) {
    const response = await postAction(request, 'engagement/likes/cast-like', {
      elementId: data.nested[state], fieldId: data.fields.likes, siteId: data.siteId, value: 1,
    });
    expect(response.status(), `nested entry with ${state} owner`).toBe(state === 'published' ? 200 : 404);
  }
});
