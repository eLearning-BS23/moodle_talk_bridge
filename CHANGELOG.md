# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/eLearning-BS23/moodle_talk_bridge/releases/tag/v1.0.0
