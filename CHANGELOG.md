# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.5] - 2026-08-25

### Fixed

- The Moodle plugin address and the support email were plain text in the App
  Store listing, so neither was clickable — the store's Markdown renderer does
  not autolink a bare URL or address. Both are now explicit Markdown links, and
  the plugin is linked from the requirements list as well as from its own
  section.

## [1.0.4] - 2026-08-25

### Changed

- App Store listing rewritten for a non-technical audience. It now leads with
  what teachers and students experience, gives a four-step browser-only setup,
  and no longer offers a command-line route — the `occ` keys stay documented in
  the README, where the reader is an administrator.
- The companion Moodle plugin is now named and linked
  (<https://elearning23.com/nextcloud-talk-for-moodle/>) instead of only
  pointing at an email address, and the same page is registered as the app's
  admin documentation link.

### Note on 1.0.3

`v1.0.3` was tagged with the listing rewrite, but `appinfo/info.xml` still
declared `1.0.2`. The App Store keys a release by that value rather than by the
git tag, so the upload was accepted as a re-upload of 1.0.2 and the listing text
was never refreshed. 1.0.3 was never published to the App Store; this release
carries the same content under a version number the store will accept.

## [1.0.2] - 2026-08-24

### Added

- Admin configuration panel at **Administration settings → Moodle Talk Bridge**.
  All six keys are editable in the browser; the two secrets are write-only and
  render as placeholders, so leaving a secret field blank keeps the stored
  value. Every key can still be applied with `occ` for scripted installs.

### Fixed

- The admin panel was unreachable as first merged. `appinfo/info.xml` declared
  `OCA\MoodleTalkBridge\Settings\AdminSection`, but the class did not exist, so
  the section never registered and the form — which targets that section id —
  had no navigation entry to render under. `getAdminSections()` silently
  omitted it, with nothing written to the log. Added the missing class.
- Opening the panel threw `QueryNotFoundException`. `templates/admin.php`
  resolved `OCP\Security\CSRF\CsrfTokenManager`, which does not exist in any
  Nextcloud version — the class lives in the private `OC\Security\CSRF`
  namespace. The template now uses the `requesttoken` value that
  `OC\Template\Base` already assigns, which needs no container lookup.

### Changed

- The setup screenshot and the `info.xml` and `README.md` configuration
  sections no longer state that there is no admin UI.

## [1.0.1] - 2026-08-24

### Fixed

- Clean installs aborted during `occ app:enable`. Nextcloud runs its Oracle
  constraint checks for apps that declare no `<database>` dependency, and two
  rules were violated: `talkbridge_user_map.provisioned` was a `NOT NULL`
  boolean, and `talkbridge_event_dedupe` (23 characters) relied on the default
  primary-key name, which caps the table name at 22. The column is now nullable
  and the key is named `tb_dedupe_pk`; both edits sit behind
  `hasTable()` guards, so existing deployments are untouched.

### Changed

- New app icon: a redrawn monochrome `img/app.svg` that stays legible at 16 px
  in the app menu and carries detail at store size, plus a colour brand logo at
  `img/logo.svg`.
- App Store listing artwork — a poster-style cover plus four screenshots, each
  with a small thumbnail, declared in `appinfo/info.xml` and served from
  `screenshots/`. Without them the store listing fell back to its generic
  `no-screenshot.svg` placeholder; the cover is first because the store card
  scales the whole image into a 200 px box, where a detail-heavy screenshot is
  unreadable.
- `.claude/`, `RELEASING.md` and `screenshots/` are excluded from the release
  tarball. The staging step copies the working tree rather than tracked files,
  so these were being packaged and hashed into `appinfo/signature.json`.

## [1.0.0] - 2026-07-28

First public release.

### Added

- HMAC-signed webhook endpoint that creates, updates and archives Nextcloud
  Talk conversations on behalf of a Moodle `mod_nextcloudtalk` activity.
- Automatic provisioning of Nextcloud accounts for Moodle participants, with
  a persistent Moodle-user to Nextcloud-user mapping.
- Roster synchronisation from Moodle course enrolment, granting teachers
  moderator rights and students participant rights.
- Signed single-use ticket SSO endpoint, so following the activity link from
  Moodle lands the user in the conversation already signed in.
- Health endpoint reporting Talk reachability and bot authentication, backing
  the "Test connection" button in the Moodle plugin.
- Replay protection via a nonce table and a webhook event de-duplication table.

[1.0.5]: https://github.com/eLearning-BS23/moodle_talk_bridge/releases/tag/v1.0.5
[1.0.4]: https://github.com/eLearning-BS23/moodle_talk_bridge/releases/tag/v1.0.4
[1.0.2]: https://github.com/eLearning-BS23/moodle_talk_bridge/releases/tag/v1.0.2
[1.0.1]: https://github.com/eLearning-BS23/moodle_talk_bridge/releases/tag/v1.0.1
[1.0.0]: https://github.com/eLearning-BS23/moodle_talk_bridge/releases/tag/v1.0.0
