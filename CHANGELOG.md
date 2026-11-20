# Release Notes for Engagement
## Unreleased

### Security
- Moderation actions now require a control panel request and the “Access Engagement” permission. Previously any logged-in front-end user could reach them through `actions/engagement/moderation/*`, including deleting votes.
- Added a new “Delete ratings, likes and favorites from moderation” user permission, required to delete rows. Admins have it automatically; grant it to any non-admin moderators.
- Custom SVG icons are now passed through Craft’s SVG sanitizer before rendering, on the front end and in control panel previews. Editor-supplied SVGs could previously run scripts.
- Icon colors are now limited to plain CSS color values, so style attributes can’t be injected.
- Inline widget scripts now always hex-encode `<`, `>`, `&` and quotes in JSON values.
- Vote endpoints now reject drafts, revisions, and disabled, pending or expired elements. They also check that the site exists and that the field belongs to the element’s field layout.
- Guest votes are now keyed by an HMAC of the session ID rather than the raw PHP session ID. A migration converts existing rows, so guests keep their votes.
- The Login URL setting must be a relative path or an http(s) URL.

### Fixed
- Fixed a race where concurrent un-like/un-favorite requests could decrement counters twice.
- Vote endpoints now resolve the field through the element’s field layout, so fields with a layout-specific handle work.

## 1.0.2 - 2026-08-07
- Added opt-in `mode: 'deferred'` rendering for Blitz-safe Ratings, Likes, and Favorites widgets.
- Added the external `Engagement.init(root)` initializer and the bubbling `engagement:ready` browser event.
- Added `craft.engagement.registerDeferredAssets()` for loading the initializer from a cached outer template.
- Deferred widgets now carry token-free client configuration and fetch a fresh session CSRF token only when an allowed interaction is submitted.
- HTML and CSS template overrides run in normal and deferred modes; JS overrides remain unchanged in normal mode and are intentionally omitted in deferred mode.

## 1.0.1 - 2026-04-24

### Fixed
- Fixed entry editor icon mode panel visibility in side-panel editing contexts for Ratings, Likes, and Favorites (emoji/custom icon sections now reliably show and hide when switching modesas well as color selections).

### Improved
- Improved plugin settings and field settings help text.
- Added documentation links and clearer tooltip messaging for guest interactions, icon modes, editor overrides, and widget previews.
- Changed Default Enabled State for the 3 fields to true
- Accessibility Updates for front end rendered widgets
