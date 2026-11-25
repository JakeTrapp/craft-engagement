import { expect, test } from '@playwright/test';
import { csrfToken, fixture, frontEndLogin, postAction } from './support';

// Separate login sessions for one user avoid PHP's per-session serialization.
test('concurrent rating changes, like switches and favorites preserve aggregate totals', async ({ browser }) => {
  const data = fixture();
  const contexts = await Promise.all([browser.newContext({ baseURL: test.info().project.use.baseURL }), browser.newContext({ baseURL: test.info().project.use.baseURL })]);
  try {
    for (const context of contexts) {
      const page = await context.newPage();
      await frontEndLogin(page, data.users.member, data.password);
    }
    const requests = contexts.map(context => context.request);
    const base = { elementId: data.entries.published.id, siteId: data.siteId };
    const ratingData = { ...base, fieldId: data.fields.rating, rating: 4 };
    const initial = await (await postAction(requests[0], 'engagement/ratings/cast-rating', ratingData)).json();
    const tokens = await Promise.all(requests.map(request => csrfToken(request)));
    const simultaneous = async (action: string, payloads: Record<string, unknown>[]) => {
      const responses = await Promise.all(payloads.map((payload, i) => requests[i % 2].post(`/actions/${action}`, {
        headers: { Accept: 'application/json', 'X-CSRF-Token': tokens[i % 2] }, data: payload,
      })));
      for (const response of responses) expect(response.status(), await response.text()).toBe(200);
    };
    await simultaneous('engagement/ratings/cast-rating', Array.from({ length: 12 }, (_, i) => ({ ...ratingData, rating: i % 2 ? 2 : 5 })));
    const final = await (await postAction(requests[0], 'engagement/ratings/cast-rating', ratingData)).json();
    expect(final.aggregate.voteCount).toBe(initial.aggregate.voteCount);
    expect(final.aggregate.average).toBe(initial.aggregate.average);

    const likesData = { ...base, fieldId: data.fields.likes, value: 1 };
    let initialLike = await (await postAction(requests[0], 'engagement/likes/cast-like', likesData)).json();
    if (initialLike.vote.value === null) initialLike = await (await postAction(requests[0], 'engagement/likes/cast-like', likesData)).json();
    await simultaneous('engagement/likes/cast-like', Array.from({ length: 12 }, (_, i) => ({ ...likesData, value: i % 2 ? -1 : 1 })));
    let finalLike = await (await postAction(requests[0], 'engagement/likes/cast-like', likesData)).json();
    if (finalLike.vote.value === null) finalLike = await (await postAction(requests[0], 'engagement/likes/cast-like', likesData)).json();
    expect(finalLike.aggregate.likeCount).toBe(initialLike.aggregate.likeCount);
    expect(finalLike.aggregate.dislikeCount).toBe(initialLike.aggregate.dislikeCount);

    const favoriteData = { ...base, fieldId: data.fields.favorites };
    let initialFavorite = await (await postAction(requests[0], 'engagement/favorites/toggle-favorite', favoriteData)).json();
    if (!initialFavorite.entry.isFavorited) initialFavorite = await (await postAction(requests[0], 'engagement/favorites/toggle-favorite', favoriteData)).json();
    await simultaneous('engagement/favorites/toggle-favorite', Array.from({ length: 12 }, () => favoriteData));
    const removed = await (await postAction(requests[0], 'engagement/favorites/toggle-favorite', favoriteData)).json();
    expect(removed.entry.isFavorited).toBe(false);
    expect(removed.aggregate.favoriteCount).toBe(initialFavorite.aggregate.favoriteCount - 1);

  } finally {
    await Promise.all(contexts.map(context => context.close()));
  }
});
