# files_publish — publish research outputs from Nextcloud

Publish a file or folder from Nextcloud to a **research data repository**,
making it findable and giving it a **citable DOI**. A "Publish…" action in the
Files app collects metadata (authors prefilled from the user's profile and
connected ORCID iD), authorizes with the repository, uploads the data as a
**draft**, and hands the user the repository's review/submit page.

**Author:** Frederik Orellana, Technical University of Denmark (fror@dtu.dk).
NC34 rework of the ownCloud `files_zenodo` app (Lars Næsbye Christensen, DeIC).
**License:** AGPL-3.0

---

## Targets

A pluggable **target-adapter** design (`OCA\FilesPublish\Target\PublishTarget`):
each destination implements authorize / metadata-schema / publish, and the
app shell is otherwise target-agnostic. Adding a destination = registering an
adapter in `TargetRegistry`.

| Target | Status | Mechanism |
|--------|--------|-----------|
| **Zenodo** (zenodo.org, sandbox, or a self-hosted Invenio) | built | OAuth2, deposition + bucket upload, DOI on submit |
| **Figshare** (figshare.com, data.dtu.dk) | built | OAuth2, article + chunked (MD5) upload, `reserve_doi` |
| Dataverse | planned | — |
| Media / streaming platform | planned | upload adapter, config-swap URL; supports controlled-audience video |
| ScienceNotebooks | planned (with user_pods) | native: public link + share with a group, no external API |

Both repository targets leave the deposit as a **draft** — publishing (which
mints the DOI and goes irreversibly public) is one review-click away on the
repository, never done automatically on the user's behalf.

## How it works

1. **Files → "Publish…"** (single or multi-select) → pick a target → fill its
   metadata form (authors prefilled from profile + `\OCA\UserOrcid\Lib`).
2. The dialog `POST`s to `/api/v1/publish`, which parks the job and returns an
   OAuth authorize URL; a popup runs the repository authorization.
3. The repository redirects to `/oauth/{target}/callback`, which exchanges the
   code for a token and forwards to `/publish/{target}/run`.
4. The run resolves the file ids to local paths (zipping a folder on the fly),
   the adapter creates the record and uploads, and the result page shows the
   DOI + the review/submit link. The deposition id/DOI/URL are stored on the
   file (per-user config) for later reference.

## Admin configuration

Settings → Administration → Additional settings → **Data publishing**: per
target, the API base URL, client ID and secret. Each target shows the
**redirect URI** to register with the repository's developer/application
settings (one per node in a multi-node setup, as with user_orcid). A default
author affiliation can be set globally.

App config keys (`oc_appconfig`, app `files_publish`), namespaced by target:
`zenodo.baseUrl`, `zenodo.clientAppID`, `zenodo.clientSecret`,
`figshare.baseUrl`, `figshare.authBaseUrl`, `figshare.clientAppID`,
`figshare.clientSecret`, `figshare.defaultCategory`, `figshare.defaultLicense`;
global `defaultAffiliation`.

## OCS API

Base `/ocs/v2.php/apps/files_publish/api/v1` (`OCS-APIREQUEST: true`).

| Method | Path | Description |
|--------|------|-------------|
| GET | `/targets` | Configured targets for the file action |
| GET | `/targets/{target}/schema` | Metadata schema + author prefill |
| POST | `/publish` | Begin: `target`, `fileids[]`, `metadata[...]` → next step (oauth url) |
| GET/POST | `/config` | Admin: per-target credentials |

## Dependencies

- **meta_data** — present in the stack; this app currently records publish
  state in per-user config (deposition id/DOI/URL), to migrate onto meta_data
  tags for richer re-publish/versioning later.
- **user_orcid** (optional) — author ORCID prefill, guarded with
  `class_exists(\OCA\UserOrcid\Lib::class)`.
- The **file action** is a small webpack bundle (`src/files-action.js` →
  `js/files-action.js`) importing `registerFileAction` from
  `@nextcloud/files` — that API is not exposed to plain JS. The build reuses
  `../user_group_admin/node_modules` (no separate install); run
  `node ../user_group_admin/node_modules/webpack/bin/webpack.js --node-env production`.
  The metadata **dialog** stays vanilla (no npm deps). Both are injected into
  the Files app via `LoadAdditionalScriptsEvent`.

## Not yet done

- Upload progress reporting (v1 runs the upload in one request; large uploads
  rely on the PHP-FPM timeout).
- meta_data-backed state + re-publish/version-update of an existing deposit.
- Dataverse, media and ScienceNotebooks adapters (design accommodates them).
