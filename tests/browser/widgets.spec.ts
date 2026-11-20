import { expect, test } from '@playwright/test';
import { ACTIONS, captureVote, fixture, frontEndLogin, pathOf } from './support';

/**
 * Every widget must render on the front end and send the request shape the controllers expect,
 * in both inline ("normal") and Blitz-safe ("deferred") rendering modes.
 */
for (const mode of ['normal', 'deferred'] as const) {
  test.describe(`${mode} rendering`, () => {
    const url = () => pathOf(fixture().entries.published.url) + (mode === 'deferred' ? '?mode=deferred' : '');

    test('all three widgets render with their fields', async ({ page }) => {
      const data = fixture();
      await page.goto(url());

      const rating = page.getByTestId('engagementRating');
      await expect(rating.getByRole('radio')).toHaveCount(5);
      await expect(rating.getByRole('radio', { name: 'Rate 4 of 5' })).toBeAttached();

      const likes = page.getByTestId('engagementLikes');
      await expect(likes.locator('[data-like-btn="1"]')).toBeVisible();
      await expect(likes.locator('[data-like-btn="-1"]')).toBeVisible();

      const favorites = page.getByTestId('engagementFavorites');
      await expect(favorites.locator('[data-favorite-btn]')).toBeVisible();

      if (mode === 'deferred') {
        // Deferred widgets carry token-free config and no inline script.
        for (const section of [rating, likes, favorites]) {
          const root = section.locator('[data-engagement-deferred]');
          await expect(root).toHaveCount(1);
          const config = JSON.parse((await root.getAttribute('data-engagement-config')) ?? '{}');
          expect(config.elementId).toBe(data.entries.published.id);
          expect(config.siteId).toBe(data.siteId);
          expect(JSON.stringify(config)).not.toMatch(/csrf.*token.*value/i);
        }
        await expect(page.locator('section script')).toHaveCount(0);
      }
    });

    test('guest rating posts the expected payload and persists', async ({ page }) => {
      const data = fixture();
      await page.goto(url());
      const rating = page.getByTestId('engagementRating');

      const vote = await captureVote(page, ACTIONS.rating, () => rating.getByRole('radio', { name: 'Rate 4 of 5' }).click());

      expect(vote.body).toEqual({ elementId: data.entries.published.id, fieldId: data.fields.rating, siteId: data.siteId, rating: 4 });
      expect(vote.headers['x-csrf-token']).toBeTruthy();
      expect(vote.headers['accept']).toContain('application/json');
      expect(vote.status).toBe(200);
      expect(vote.json.success).toBe(true);
      expect(vote.json.vote.rating).toBe(4);
      expect(vote.json.aggregate.voteCount).toBeGreaterThanOrEqual(1);

      // Same guest session: the saved rating comes back on reload.
      await page.reload();
      await expect(rating.getByRole('radio', { name: 'Rate 4 of 5' })).toHaveAttribute('aria-checked', 'true');

      // Changing the rating updates rather than adds a vote.
      const change = await captureVote(page, ACTIONS.rating, () => rating.getByRole('radio', { name: 'Rate 2 of 5' }).click());
      expect(change.json.vote.id).toBe(vote.json.vote.id);
      expect(change.json.aggregate.voteCount).toBe(vote.json.aggregate.voteCount);
    });

    test('guest like toggles on and off, and switches to dislike', async ({ page }) => {
      const data = fixture();
      await page.goto(url());
      const likes = page.getByTestId('engagementLikes');

      const like = await captureVote(page, ACTIONS.likes, () => likes.locator('[data-like-btn="1"]').click());
      expect(like.body).toEqual({ elementId: data.entries.published.id, fieldId: data.fields.likes, siteId: data.siteId, value: 1 });
      expect(like.json.vote.value).toBe(1);
      const likesAfter = like.json.aggregate.likeCount;
      await expect(likes.locator('[data-like-btn="1"]')).toHaveAttribute('aria-pressed', 'true');
      await expect(likes.locator('[data-like-label]')).toHaveText(`Like (${likesAfter})`);

      const dislike = await captureVote(page, ACTIONS.likes, () => likes.locator('[data-like-btn="-1"]').click());
      expect(dislike.body.value).toBe(-1);
      expect(dislike.json.aggregate.likeCount).toBe(likesAfter - 1);
      await expect(likes.locator('[data-like-btn="-1"]')).toHaveAttribute('aria-pressed', 'true');

      const undo = await captureVote(page, ACTIONS.likes, () => likes.locator('[data-like-btn="-1"]').click());
      expect(undo.json.vote.value).toBeNull();
      expect(undo.json.aggregate.dislikeCount).toBe(dislike.json.aggregate.dislikeCount - 1);
      await expect(likes.locator('[data-like-btn="-1"]')).toHaveAttribute('aria-pressed', 'false');
    });

    test('guests see the login message on a members-only favorite, with no request', async ({ page }) => {
      await page.goto(url());
      const favorites = page.getByTestId('engagementFavorites');
      let posted = false;
      page.on('request', r => { if (r.method() === 'POST' && ACTIONS.favorites.test(new URL(r.url()).pathname)) posted = true; });

      await favorites.locator('[data-favorite-btn]').click();

      await expect(favorites.locator('[data-guest-inline-message]')).toContainText('Please log in or register to interact.');
      expect(posted).toBe(false);
    });

    test('logged-in member can favorite and unfavorite', async ({ page }) => {
      const data = fixture();
      await frontEndLogin(page, data.users.member, data.password);
      await page.goto(url());
      const favorites = page.getByTestId('engagementFavorites');
      const button = favorites.locator('[data-favorite-btn]');

      const on = await captureVote(page, ACTIONS.favorites, () => button.click());
      expect(on.body).toEqual({ elementId: data.entries.published.id, fieldId: data.fields.favorites, siteId: data.siteId });
      expect(on.json.entry.isFavorited).toBe(true);
      await expect(button).toHaveAttribute('aria-pressed', 'true');
      await expect(favorites.locator('[data-favorite-label]')).toHaveText('Unfavorite');

      await page.reload();
      await expect(button).toHaveAttribute('aria-pressed', 'true');

      const off = await captureVote(page, ACTIONS.favorites, () => button.click());
      expect(off.json.entry.isFavorited).toBe(false);
      expect(off.json.aggregate.favoriteCount).toBe(on.json.aggregate.favoriteCount - 1);
      await expect(favorites.locator('[data-favorite-label]')).toHaveText('Favorite');
    });
  });
}
