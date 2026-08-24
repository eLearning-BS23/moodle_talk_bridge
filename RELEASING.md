# Releasing `moodle_talk_bridge` to the Nextcloud App Store

Internal runbook for publishing the Nextcloud companion app. Covers the first
release (1.0.0) and every release after it.

| | |
| --- | --- |
| **App ID** | `moodle_talk_bridge` |
| **Public repo** | <https://github.com/eLearning-BS23/moodle_talk_bridge> |
| **Store listing** | <https://apps.nextcloud.com/apps/moodle_talk_bridge> (after first upload) |
| **Licence** | AGPL-3.0-or-later |
| **Compatibility** | Nextcloud 30–35 |
| **Maintainer contact** | elearning@brainstation-23.com |

---

## 1. How this works (read once)

The App Store never receives your code directly. It receives a **URL to a
tarball** plus a **signature proving the tarball came from you**. Three ideas
carry the whole process:

**1. Your identity is a private key.** You generate an RSA keypair. Nextcloud
signs the public half and returns a certificate that says *"this key belongs to
the app `moodle_talk_bridge`"*. Every Nextcloud server can then verify your
releases, because the Nextcloud root CA ships built into the server.

**2. Signing happens on a directory, packaging happens after.**
`occ integrity:sign-app` writes `appinfo/signature.json` **into** the app folder.
Only then do you create the `.tar.gz`. Reverse the order and you ship an
unsigned app that every server flags as tampered.

**3. The tarball is fetched, not uploaded.** You host it (a GitHub release
asset) and give the store the URL.

```
   keypair  ──►  CSR  ──►  Nextcloud signs  ──►  certificate
      │                                              │
      │                                              ▼
      └────────►  sign app dir  ──►  tar.gz  ──►  upload URL + signature
```

> **The rule that governs everything:** signing is the *last* thing that touches
> the code. Any edit afterwards — even fixing a typo in the README — invalidates
> the signature and sends you back to Phase 4.

---

## 2. Prerequisites

| Where | Needs |
| --- | --- |
| Your workstation | Git Bash, `tar`, `openssl`, Docker Desktop |
| A Nextcloud install | `occ` — required for signing only (see [Phase 4](#phase-4--build-and-sign)) |
| GitHub | Write access to `eLearning-BS23`, and a **public email on your profile** (the certificate PR requires it) |

`occ` is not a standalone binary. It is an entrypoint into a full Nextcloud
installation and needs `config.php` plus database access — a local PHP/XAMPP
install cannot run it.

---

## 3. Release checklist

Phases 1 and 2 should happen the same day. Phase 2 has **no service-level
agreement** and gates everything after it, so start it early.

- [ ] **Phase 1** — Publish the source to GitHub
- [ ] **Phase 2** — Request the signing certificate ⏳ *days to weeks*
- [ ] **Phase 3** — Pre-release checks *(do while waiting)*
- [ ] **Phase 4** — Build and sign
- [ ] **Phase 5** — Publish the tarball and submit
- [ ] **Phase 6** — Verify

---

## Phase 1 — Publish the source

The app's contents must sit at the **repository root**. A nested folder breaks
the tarball layout the store expects.

```
✅ repo root:  appinfo/  lib/  README.md  COPYING  Makefile
❌ repo root:  moodle_talk_bridge/appinfo/ ...
```

Use a fresh `git init`. Do **not** subtree-split from the monorepo — that would
carry across history containing the vendored `nextcloud/` development TLS
certificates.

```bash
cd /d/moodle-mod_nextcloudtalk
cp -r nextcloud-app/moodle_talk_bridge /d/moodle_talk_bridge-public
cd /d/moodle_talk_bridge-public

git init -b main
git add -A
git commit -m "Initial public release (1.0.0)"
git remote add origin https://github.com/eLearning-BS23/moodle_talk_bridge.git
git push -u origin main
```

**Verify:**

```bash
git ls-files | wc -l          # expect 48
git ls-files | head -3        # must NOT be prefixed with moodle_talk_bridge/
```

48 files is correct. It includes `tests/` and `composer.json`, which belong in
the repository but are excluded from the release tarball — two different things.

---

## Phase 2 — Request the signing certificate ⏳

### 2.1 Generate the keypair

> **Windows/Git Bash:** the `MSYS_NO_PATHCONV=1` prefix is **required for this
> one command**. Without it, MSYS rewrites the `-subj` value `/CN=…` into
> `C:/Program Files/Git/CN=…` and openssl fails.
>
> Use it **only here**. It applies to `-subj` because that argument starts with
> `/` but is not a path. Adding it to commands that take real file paths breaks
> them. On Linux or macOS, drop the prefix entirely.

```bash
mkdir -p ~/.nextcloud/certificates && cd ~/.nextcloud/certificates

MSYS_NO_PATHCONV=1 openssl req -nodes -newkey rsa:4096 \
  -keyout moodle_talk_bridge.key -out moodle_talk_bridge.csr \
  -subj "/CN=moodle_talk_bridge"
```

What the flags mean:

| Flag | Purpose |
| --- | --- |
| `-newkey rsa:4096` | Generate a fresh 4096-bit RSA keypair |
| `-keyout ….key` | The **private** half — secret |
| `-out ….csr` | The request, containing the **public** half — safe to publish |
| `-nodes` | No passphrase. Required: `occ` reads the key non-interactively |
| `-subj "/CN=…"` | Common Name must be **exactly** the app id |

### 2.2 Verify before submitting

```bash
openssl req -in moodle_talk_bridge.csr -noout -subject
# must print exactly:  subject=CN=moodle_talk_bridge
```

If you see a `C:/Program Files/...` prefix, delete both files and rerun with the
`MSYS_NO_PATHCONV=1` prefix. A wrong CN means rejection and another wait.

### 2.3 Open the pull request

Submit the **`.csr`** to <https://github.com/nextcloud/app-certificate-requests>,
linking the repository from Phase 1.

> **Never** commit, PR, or email the `.key`.

### 2.4 Back up the private key — now

`moodle_talk_bridge.key` is the app's identity for its entire lifetime, and
`-nodes` means it sits on disk unencrypted. Anyone who copies it can publish
releases as Brain Station 23.

Put it in the company password manager or secrets vault immediately.

> **Losing this key is unrecoverable.** The certificate is bound to it. Without
> it you can never ship an update under this app id again.

---

## Phase 3 — Pre-release checks *(while waiting)*

### 3.1 Run the test suite

Most tests extend Nextcloud's `Test\TestCase`, so they only run **inside** a
Nextcloud installation. Running them on the host fails with
`Class "Test\TestCase" not found`.

```bash
docker exec -u www-data master-nextcloud-1 bash -c \
  "cd /var/www/html/apps-extra/moodle_talk_bridge && \
   composer install && composer exec phpunit -- -c phpunit.xml"
```

### 3.2 Validate `info.xml`

```bash
curl -sL https://apps.nextcloud.com/schema/apps/info.xsd -o /tmp/info.xsd
php -r '$d=new DOMDocument(); $d->load("appinfo/info.xml");
        echo $d->schemaValidate("/tmp/info.xsd") ? "VALID\n" : "INVALID\n";'
```

### 3.3 Confirm the version was bumped

`appinfo/info.xml` `<version>` must be higher than the last published release.
Semantic versioning. Update `CHANGELOG.md` in the same commit.

**Fix everything now.** After Phase 4, any change forces a re-sign.

---

## Phase 4 — Build and sign

Once the certificate arrives, save it as
`~/.nextcloud/certificates/moodle_talk_bridge.crt`, trimming stray whitespace.

Only **one** of the three build steps needs Nextcloud:

| Step | Needs | Runs on Windows? |
| --- | --- | --- |
| Stage a clean copy | `tar` | ✅ |
| Sign → `appinfo/signature.json` | **`occ`** | ❌ container |
| Package + upload signature | `tar`, `openssl` | ✅ |

### Option A — Linux release box

If you have a machine with both `make` and a Nextcloud install:

```bash
make appstore OCC=/var/www/html/occ
```

### Option B — Windows workstation *(current setup)*

`make` is not installed on the workstation and `occ` lives in a container, so
run the three steps directly. Start Docker Desktop first.

```bash
cd /d/moodle_talk_bridge-public
CERT=~/.nextcloud/certificates
NC=master-nextcloud-1          # a LOCAL container, not the shared server

# 1 — stage a clean copy
rm -rf build && mkdir -p build/sign/moodle_talk_bridge
tar --exclude='.git' --exclude='.github' --exclude='.gitignore' \
    --exclude='build' --exclude='Makefile' --exclude='tests' \
    --exclude='phpunit.xml' --exclude='.phpunit.result.cache' \
    --exclude='composer.json' --exclude='composer.lock' --exclude='vendor' \
    --exclude='.claude' --exclude='RELEASING.md' --exclude='screenshots' \
    -cf - -C . . | tar -xf - -C build/sign/moodle_talk_bridge

# 2 — sign inside the container
docker cp build/sign/moodle_talk_bridge $NC:/tmp/
docker cp $CERT/moodle_talk_bridge.key  $NC:/tmp/
docker cp $CERT/moodle_talk_bridge.crt  $NC:/tmp/
docker exec $NC php /var/www/html/occ integrity:sign-app \
  --privateKey=/tmp/moodle_talk_bridge.key \
  --certificate=/tmp/moodle_talk_bridge.crt \
  --path=/tmp/moodle_talk_bridge
docker cp $NC:/tmp/moodle_talk_bridge/appinfo/signature.json \
          build/sign/moodle_talk_bridge/appinfo/

# 3 — package, then compute the upload signature
tar -czf build/moodle_talk_bridge.tar.gz -C build/sign moodle_talk_bridge
openssl dgst -sha512 -sign $CERT/moodle_talk_bridge.key \
  build/moodle_talk_bridge.tar.gz | openssl base64 -A > build/signature.txt
```

> Keep this exclude list identical to `exclude=` in the `Makefile`. The staging
> step copies the **working tree**, not tracked files, so anything left out of
> the list is packaged and hashed into `appinfo/signature.json` — including
> `screenshots/`, which the App Store fetches from GitHub by URL and must never
> ship inside the archive.

> **Do not** add `MSYS_NO_PATHCONV=1` here. That variable is needed only for
> `-subj "/CN=…"` in Phase 2. Using it with real file paths breaks them —
> openssl receives `/c/Users/…` instead of `C:\Users\…` and reports
> *"Could not open file or uri for loading private key"*.

### Getting the signature text

Step 3 deliberately writes the signature to `build/signature.txt` instead of the
terminal. It is **684 characters on one line with no trailing newline**, so on
screen the shell prompt would run straight into its last character — there is no
visual cue where it ends, and selecting it by hand across wrapped lines is easy
to get wrong.

Copy it from the file:

```bash
wc -c < build/signature.txt     # must be exactly 684
clip  < build/signature.txt     # now on the Windows clipboard — just paste
```

On Linux or macOS use `xclip -selection clipboard` or `pbcopy` instead of `clip`.

Paste it straight into the *Signature* field in Phase 5.3. Do not add line
breaks and do not let an editor append a newline.

> This signature belongs to **that exact tarball**. If you rebuild for any
> reason, the old signature is void — regenerate it.

> Step 2 requires the private key inside the container. Use a **local**
> container, not the shared on-prem server — do not put your signing key on a
> box other people can shell into. Remove the copies afterwards:
> `docker exec $NC rm -rf /tmp/moodle_talk_bridge*`

### Verify the archive

```bash
tar -tzf build/moodle_talk_bridge.tar.gz | cut -d/ -f1 | sort -u
#   → exactly one line: moodle_talk_bridge

tar -tzf build/moodle_talk_bridge.tar.gz | grep signature.json
#   → moodle_talk_bridge/appinfo/signature.json

tar -tzf build/moodle_talk_bridge.tar.gz | grep -E "tests|vendor|composer"
#   → no output
```

Expect roughly 30 files. If `signature.json` is missing, the store will reject
the archive as tampered.

---

## Phase 5 — Publish and submit

### 5.1 First release only — register the app

The form at <https://apps.nextcloud.com/developer/apps/new> needs two values.
Write each to a file, then copy with `clip` rather than selecting in the
terminal.

```bash
cd ~/.nextcloud/certificates

# value 1 — the certificate, pasted verbatim including the BEGIN/END lines
clip < moodle_talk_bridge.crt

# value 2 — a signature over the app id itself (not the tarball)
echo -n "moodle_talk_bridge" | \
  openssl dgst -sha512 -sign moodle_talk_bridge.key | \
  openssl base64 > appid-signature.txt
clip < appid-signature.txt
```

Note `-n` on the `echo`: without it a trailing newline is signed and the
signature will not verify.

This signature is over the **app id string**, and is different from the tarball
signature in Phase 4. This step claims the app id permanently and is only done
once, for the first release.

### 5.2 Host the tarball

The store **fetches** the file; there is no upload box. Create a GitHub release
tagged `v1.0.0` and attach `build/moodle_talk_bridge.tar.gz` as an asset. Use
that asset URL.

### 5.3 Submit

At <https://apps.nextcloud.com/developer/apps/releases/new>, provide:

- the download URL from 5.2
- the base64 signature — `build/signature.txt` from Phase 4 (`clip < build/signature.txt`)

The store validates the archive structure and `info.xml` on submit.

---

## Phase 6 — Verify

1. The listing appears at <https://apps.nextcloud.com/apps/moodle_talk_bridge>.
2. On a **clean** Nextcloud, install from **Apps → Integration**.
3. Confirm Nextcloud Talk (`spreed`) is enabled.
4. Apply the configuration keys documented in the app's `README.md`.
5. Probe the health endpoint:

```bash
curl -s https://cloud.example.org/apps/moodle_talk_bridge/health
# expect: talk_reachable = true, bot_authenticated = true
```

---

## Shipping an update later

Phase 2 is one-time — reuse the same key and certificate.

1. Bump `<version>` in `appinfo/info.xml`, update `CHANGELOG.md`
2. Push and tag
3. Phase 3 — tests
4. Phase 4 — build and sign
5. Phase 5.2 and 5.3 — new GitHub release, new store release *(skip 5.1)*

Compatibility rule: at release time an app may target the **latest Nextcloud
release plus one version ahead**. Bump `max-version` as new versions ship.

---

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `This name is not in that format: 'C:/Program Files/Git/CN=…'` | Git Bash rewrote the `-subj` value | Prefix `MSYS_NO_PATHCONV=1` (Phase 2 only) |
| `Could not open file or uri for loading private key from /c/Users/…` | `MSYS_NO_PATHCONV=1` used on a command taking file paths | Remove the prefix — it belongs only on the `-subj` command |
| `Class "Test\TestCase" not found` | Tests run on the host | Run inside a Nextcloud install (Phase 3.1) |
| Store rejects the archive | Wrong root folder, or missing `appinfo/info.xml` | Root folder must be exactly `moodle_talk_bridge` |
| Server reports the app as tampered | Packaged before signing, or edited after | Redo Phase 4 in order |
| `occ: command not found` locally | `occ` needs a full Nextcloud install | Use Option B |
| Certificate PR is quiet | Human review, no SLA | Ensure your GitHub profile shows a public email |

## Reference

- [App Store developer documentation](https://nextcloudappstore.readthedocs.io/en/latest/developer.html) — registration and upload
- [Code signing](https://docs.nextcloud.com/server/latest/developer_manual/app_publishing_maintenance/code_signing.html)
- [App store rules](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html)
- [Certificate requests](https://github.com/nextcloud/app-certificate-requests)
