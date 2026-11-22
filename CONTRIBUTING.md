# Contributing

Thanks for helping. This SDK is one of seven Didit server SDKs that share one design: read [docs/DESIGN.md](docs/DESIGN.md) first. When this code and the design disagree, the code is wrong.

## Setup

```bash
composer install          # PHP 8.1 or newer, ext-json and ext-openssl
python3 --version         # 3.10 or newer, for the generator (standard library only)
```

## Layout

| Path | What | Edited by hand |
|---|---|---|
| `openapi/verification.json`, `openapi/account.json`, `openapi/authentication.json`, `openapi/webhook-vectors.json` | Upstream copies | Never (spec sync only) |
| `openapi/breaking-vectors.json` | The breaking-change rules of DESIGN.md section 3.1 as cases, generated in sdk-python; `codegen/tests/test_breaking_vectors.py` runs every case | Never: copy it byte for byte from sdk-python and update its SHA-256 in `openapi/SOURCE.json` |
| `openapi/overlay.json` | SDK naming (`x-didit-sdk`) for operations that do not carry it upstream yet | Yes |
| `openapi/deprecated-operations.json` | Operations removed upstream, renamed, or no longer server operations, kept as deprecated methods for one minor version | Spec sync only |
| `codegen/` | The generator (Python) and its tests | Yes |
| `src/Resources/`, `src/Types/`, `src/DiditClient.php`, `src/Params.php`, `tests/Generated/` | Generated layer | Never |
| `src/Core/`, `src/Exception/`, `src/Webhooks/`, `src/Authentication/`, `src/File.php`, `src/RequestOptions.php`, `src/Version.php` | Hand-written core | Yes |

## Commands

```bash
composer generate         # regenerate the generated layer from openapi/
composer generate:check   # fail when the committed generated code is stale (CI runs this)
composer test             # unit, generated, contract, webhook vector and Authentication tests
composer analyse          # PHPStan level max over src, tests and examples
composer lint             # PHP-CS-Fixer (composer lint:fix to fix)
python3 -m unittest discover -s codegen/tests   # generator tests
python3 codegen/spec_sync.py                    # fetch the upstream specs and summarise changes
DIDIT_SANDBOX_API_KEY=... composer test:live     # optional smoke tests against a sandbox application
```

Unit tests never touch the network. Live smoke tests run only when `DIDIT_SANDBOX_API_KEY` is set and never in default CI.

## Changing the API surface

The API surface comes from the OpenAPI contract published at docs.didit.me (mintlify-docs).
To add or change an endpoint, change the backend and the OpenAPI document there; the daily spec sync (`.github/workflows/spec-sync.yml`) then opens a `<type>(spec): sync API spec YYYY-MM-DD` pull request here and in the other six SDKs.
Push any hand-written change the new spec needs to that pull request: once it holds a commit from someone else, later syncs add their commit on top (and list their changes in a comment) instead of rebuilding the branch from `main`.
A new server operation without SDK naming fails the generator with a message that names it: add an entry to `openapi/overlay.json` (or `x-didit-sdk` upstream).
The type follows DESIGN.md section 13: `feat(spec)` when callers can observe the change (a minor release), `chore(spec)` when only documentation changed (no release on its own), and never `!` or a `BREAKING CHANGE:` footer, because an API change is never a major version.
The description opens with "Breaking changes", every change that can break typed code, and ends with a `BEGIN_COMMIT_OVERRIDE` block: Release Please reads it instead of the commits, so the type and one `api(spec)` line per breaking change (the release notes section "API changes that can break typed code") survive a squash merge with the title.
Keep that block when you edit the description, and merge with "Squash and merge": after a merge commit the release notes repeat each line for the merge commit and for every commit of the pull request.
The release notes get every breaking change, with no cap: when the lines do not fit a description, it says so and has no block, and Release Please reads the squashed commit, whose sync commit messages hold every line; keep the commit messages GitHub proposes when you squash.
A later sync on top of your commits adds its breaking changes to "Breaking changes" and its lines to the block, keeps `feat(spec)` once any sync on the branch needs it, and lists its other changes in a comment.
`openapi/authentication.json` describes the hand-written `src/Authentication` module, which is never generated: when a sync adds, removes or renames one of its operations, `tests/Contract/AuthenticationContractTest.php` fails until you update the module and that test in the same pull request.

A change to hand-written core behaviour that DESIGN.md describes (client options, errors, retries, pagination, webhooks, Authentication) is made in all seven SDK repositories, one pull request each, and DESIGN.md is updated in all seven in the same words.

## Pull requests

- Conventional commit titles; the type sets the version bump: `fix:` patch, `feat:` minor, `feat!:` or `BREAKING CHANGE:` major, which is only for a change to the SDK's own design (DESIGN.md section 13). `chore:` (dependency updates too), `docs:`, `test:`, `ci:` and `build:` release nothing on their own.
- Tests for every behaviour change, README updates for public API changes.
- Never commit secrets. Test webhook secrets start with `whsec_test_`; test keys and tokens are generated at test time. CI scans the full history with gitleaks.
- `CHANGELOG.md` is generated by Release Please; never edit it by hand.

## Releasing

1.0.0 was released on 2026-10-29. Merging the release pull request is the release approval (Alex, 2026-10-29); nobody tags or publishes by hand.

1. Release Please (`.github/workflows/release-please.yml`) keeps a release pull request open with the next version and changelog. It runs only when the repository variable `RELEASES_ENABLED` is `true`, and it never creates tags: Packagist publishes every tag it sees.
2. Merging that pull request creates no tag yet. The merged pull request stays labelled `autorelease: pending`, and no new release pull request opens until it is tagged.
3. `.github/workflows/publish.yml` (also gated on `RELEASES_ENABLED`) checks that `Didit\Version::VERSION` equals the release manifest, runs the tests and PHPStan on the merge commit, then, in the `packagist` environment (it holds the Packagist secrets; no reviewers), creates the `vX.Y.Z` tag and the GitHub release, attest the build provenance of a source archive (GitHub artifact attestations), attach it to the release and ask Packagist to update. If the checks fail, nothing is tagged: fix `main` or run Publish again from the Actions tab.

### One-time registry setup (done for 1.0.0 on 2026-10-29)

1. Make the repository public (Packagist reads public GitHub repositories).
2. The package is registered on packagist.org under the Didit account `didit`, which submitted `https://github.com/didit-protocol/sdk-php`; the package name `didit-protocol/sdk-php` comes from `composer.json`. Add the Didit maintainers to the package.
3. Install the Packagist GitHub integration (or add the Packagist webhook) so new tags update the package automatically.
4. Optional fallback: store `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` (the safe API token from the Packagist profile) as secrets of the `packagist` environment; `publish.yml` then calls the Packagist update API itself.
5. The `packagist` environment holds `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` and has no required reviewers; the Packagist webhook on this repository updates the package on every push.
6. Set the repository variable `RELEASES_ENABLED` to `true`.
7. Verify a release: `gh attestation verify sdk-php-vX.Y.Z.zip --repo didit-protocol/sdk-php`.
