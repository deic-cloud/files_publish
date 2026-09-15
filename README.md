# files_publish — publish research outputs from Nextcloud

Publish a file or folder from Nextcloud to a **research data repository**,
making it findable and giving it a **citable DOI**. A "Publish…" action in the
Files app collects metadata (authors prefilled from the user's profile and
connected ORCID), authorizes with the repository, uploads the data as a
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

## The publication record = the repository schema (meta_data)

After a successful deposit every published item is tagged with the target's
schema — **`Zenodo`**, or **`data.dtu.dk`** for Figshare — and the schema's
fields are filled with what the user entered plus the repository's answer.
For Zenodo that is the old service's bookkeeping, with the same meanings:

| field | meaning |
|---|---|
| `deposition_id` | the Zenodo deposit this item belongs to |
| `bucket` | the deposit's upload URL (files are added there) |
| `url` | the deposit's page at Zenodo |
| `uploaded` | `yes` once this item's data was uploaded into the deposit (unset for a link deposit) |
| `publication_date` | date of the deposit |

Opening *Publish…* on such an item prefills the form from the recorded values
and **publishes into the same deposit again**: files are added, the metadata
updated; if the deposit has already been published at Zenodo, a new version of
it is created (`actions/newversion`) rather than an unrelated record. If the
deposit is gone at Zenodo, a new one is created.

The schema is the design authority: each target's form and its key map
(`PublishTarget::getMetadataTag/getMetadataKeyMap`) are written to match the
deployment's schema (Zenodo's required + conditionally required attributes,
`communities`, `keywords`, and the bookkeeping above), and
`Service/MetadataRecorder` never creates tags or fields — a value with no
matching field is skipped and logged. Changing what is stored means changing
the seeded schema in meta_data (`predefined_schemas.php` + the tags on the
nodes) and the form together. The seeded `data.dtu.dk` schema has no fields
for the repository's answer, so Figshare deposits record only the entered
metadata until such fields are added to it.

The Zenodo form: Title, Description, Authors, Type (suggested from the file
extension — `PublishTarget::defaultsFor()`), Publication type / Image type
(shown only for those Types, as Zenodo requires them then) and Keywords. Access
right and license are left to Zenodo's defaults (open, CC0 / CC-BY) and remain
editable in the metadata editor. The admin setting **Default communities**
submits every deposit to the given Zenodo communities (the community's
curators accept or reject), as the old service did.
No per-user preferences are written (the earlier `state_<fileid>` rows are gone).

## Dependencies

- **meta_data** — required for the publication record above; without it a
  deposit still works, just leaves no record on the item.
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
- Version-update of an existing deposit (today a second publish creates a new record).
- Dataverse, media and ScienceNotebooks adapters (design accommodates them).
