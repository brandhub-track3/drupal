# Drupal BrandHub Setup and Process

This handover describes the implementation currently present in this
repository. It distinguishes code that exists, behavior verified locally, and
configuration or integration that still requires another team. It contains no
real credentials or deployment-specific AWS values.

Run the PowerShell commands from the repository root unless noted. The
`C:\xampp83\php\php.exe` path is specific to the machine used for this review;
replace it if PHP is installed elsewhere.

## 1. Overview

The Drupal integration supports the existing `product`, `article`, and `faq`
node bundles:

```text
Drupal Product / Article / FAQ
  -> Drupal entity lifecycle hooks and moderation checks
  -> brandhub_sync
  -> Drupal-to-unified-content mapping
  -> content.changed envelope and deterministic content hash
  -> inline SQS message, or S3 claim-check object plus pointer
  -> SQS transport (currently FakePublisher by default)
  -> AWS consumer pipeline (outside this repository)
```

The message mapper, moderation logic, SQS publisher, S3 claim-check storage,
and AWS SDK client are implemented. The service alias currently selects the
fake transport, so ordinary saves do not send SQS messages unless the
deployment explicitly selects the SQS publisher.

Media is a separate path. S3FS uses Drupal's public file stream when explicitly
enabled, while CloudFront is configured as the browser-facing hostname:

```text
Drupal image/file field
  -> S3FS public stream (opt-in)
  -> private S3 media bucket
  -> CloudFront distribution with AWS-owned origin access
  -> browser / portal
```

S3FS and its environment settings are present. AWS bucket policy, IAM, CloudFront
OAC, deployment values, and end-to-end media delivery have not been verified
from this repository.

## 2. Prerequisites

Verified in the current Windows/XAMPP workspace:

| Tool or service | Current value / status |
| --- | --- |
| PHP CLI | PHP 8.3.36 at `C:\xampp83\php\php.exe` |
| Composer | 2.8.10 |
| Drupal | 11.0.0 |
| Drush | 13.8.0 |
| Database driver | MySQL, as reported by Drush; the server version could not be read because the MySQL CLI is not installed |
| AWS SDK for PHP | `aws/aws-sdk-php` 3.402.5 |
| S3FS | `drupal/s3fs` 3.12.0 |
| PHPUnit | 10.5.66 |
| PHPCS | 3.13.6; `drupal/coder` 8.3.31 supplies Drupal and DrupalPractice standards |
| PHPStan | 1.12.34; `mglaman/phpstan-drupal` 1.3.9 |
| Node.js / npm | Not required by the current Composer project; no front-end package manifest/build was found |
| LocalStack | No checked-in LocalStack service/configuration was found; endpoint override support exists |

The current Composer runtime requirements passed `composer check-platform-reqs`.
The active PHP build has the needed database, image, XML, JSON, crypto, and
Composer/test extensions. Recheck requirements after a PHP or dependency
upgrade:

```powershell
& 'C:\xampp83\php\php.exe' -v
& 'C:\xampp83\php\php.exe' -m
& 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar --version
& 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar check-platform-reqs
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php status
```

The shared PHP version must satisfy the installed Drush and AWS SDK constraints;
PHP 8.3 is the verified project runtime. MySQL is the observed database driver.
Obtain the required MySQL/MariaDB version and database provisioning details from
the environment owner before creating a new environment.

## 3. Fresh Installation Guide

### Clone and install dependencies

```powershell
git clone <repository-url> <project-directory>
Set-Location <project-directory>
& 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar install
```

Composer installs Drupal core, Drush, S3FS, the AWS SDK, and the development
tools from `composer.lock`. Do not copy `vendor/` or Drupal core from another
checkout.

### Database and Drupal settings

Start the local MySQL service and create an empty development database. Create
the local `web/sites/default/settings.php` from Drupal's
`web/sites/default/default.settings.php` only if `settings.php` does not already
exist. Configure database connection values and the hash salt in that
machine-local settings file. The file is ignored by Git; do not paste its
contents into documentation or commit it.

The active local site uses the MySQL driver. `settings.php` sets the
configuration sync directory to `../config/sync` relative to Drupal's
`web/` root and includes `settings.s3fs.php`.

The repository does **not** currently contain a complete Drupal site
configuration export. In particular, the Product/Article/FAQ content model,
the moderation workflow, and the active listing Views are not all reproducible
from `config/sync`. A fresh standard Drupal install alone therefore does not
recreate the working BrandHub site. Before a production-like fresh install,
obtain an approved full site configuration release or a sanitized database
backup and its migration instructions from the Drupal site owner. Do not
attempt a full configuration import from this partial sync directory.

For an intentionally disposable local database, once its database settings
have been configured in the ignored `settings.php`, Drupal's standard profile
can be installed with:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php site:install standard --site-name='BrandHub local' -y
```

This creates a standard Drupal site, **not** the existing BrandHub content
types, fields, workflow, taxonomy setup, or Views.

### Apply the scoped S3FS configuration and enable BrandHub Sync

S3FS is a Composer dependency. On a new installation, enable the module before
importing its scoped configuration:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php en s3fs -y
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php cim --partial -y
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php en brandhub_sync -y
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php cr
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php pml --filter=brandhub_sync
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:media-config
```

`--partial` is intentional: this repository's sync directory is not a full
configuration export. Review every proposed config change before importing.
Do not run a normal full `drush cim` or full config export against the current
site without first reconciling its active configuration.

Verify the available integration commands:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php list --filter=brandhub
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php status
```

### Files that must not be committed

The repository ignore rules cover Composer `vendor/`, Composer-managed Drupal
core and contributed code, local settings, `.env` files, AWS credential
locations, generated public/private uploads, database dumps, SQLite files,
logs, and local tool output. Never commit:

- `vendor/` or Composer-managed `web/core/`.
- `web/sites/*/settings.php`, `settings.local.php`, credentials, or access keys.
- `.env` or other environment-specific secret files.
- AWS access keys, secret keys, session tokens, or credential profiles.
- Database dumps containing real site data.
- Generated uploads under `web/sites/*/files/` or private file directories.

The current `.gitignore` changes and several BrandHub files were still
modified/untracked at review time. Review `git status --short` and intentionally
commit the required source/configuration before expecting a new clone to
contain them.

## 4. Environment Variables and Configuration

The source reads the variables below. Set runtime variables in the process
environment available to PHP/Apache and Drush. Setting a variable only in an
interactive shell does not necessarily make it available to an already-running
Apache service. There is no `.env` loader configured in this project; an
ignored `.env` file is not automatically read.

| Variable | Purpose / required status | Consumer and precedence | Safe placeholder |
| --- | --- | --- | --- |
| `AWS_REGION` | Preferred shared AWS region; required for a real SQS client and S3 storage unless the service-specific region is set | `SqsClientFactory`, `S3ClaimCheckStorage`, `settings.s3fs.php`, and the media validator; service-specific Drupal config/env takes precedence | `<provided-by-AWS-owner>` |
| `AWS_DEFAULT_REGION` | Fallback region; optional if `AWS_REGION` or a service-specific region is set | Same region readers, after `AWS_REGION` | `<provided-by-AWS-owner>` |
| `SQS_QUEUE_URL` | Queue URL; required only when the SQS publisher is selected | `SqsPublisher`; fallback to `brandhub_sync.settings:sqs_queue_url` | `<provided-by-AWS-owner>` |
| `BRANDHUB_SYNC_CLAIM_CHECK_ENABLED` | Optional boolean override; claim-check defaults to disabled | `ClaimCheckMessageProcessor`; overrides `brandhub_sync.settings:claim_check_enabled` | `false` |
| `BRANDHUB_SYNC_ENVIRONMENT` | Optional object-key prefix; defaults to `local` | `ClaimCheckMessageProcessor`; overrides `brandhub_sync.settings:environment` | `<local-or-approved-environment>` |
| `BRANDHUB_SYNC_S3_BUCKET` | Claim-check bucket; required for an oversized event when claim-check is enabled | `S3ClaimCheckStorage`; fallback to `brandhub_sync.settings:s3_bucket` | `<provided-by-AWS-owner>` |
| `BRANDHUB_SYNC_S3_REGION` | Claim-check bucket region; optional if shared region is set | `S3ClaimCheckStorage`; fallback to `brandhub_sync.settings:s3_region`, then shared AWS region | `<provided-by-AWS-owner>` |
| `BRANDHUB_SYNC_S3_ENDPOINT` | Optional S3-compatible endpoint, for example a local test endpoint | `S3ClaimCheckStorage`; fallback to `brandhub_sync.settings:s3_endpoint` | `<local-endpoint-only>` |
| `BRANDHUB_MEDIA_S3_ENABLED` | Explicit opt-in; required and must be true to use S3 for public files | `settings.s3fs.php` and `brandhub:media-config`; default is local public files | `false` |
| `BRANDHUB_MEDIA_S3_BUCKET` | Private media bucket; required when media offload is enabled | `settings.s3fs.php` / media validator; fallback to S3FS `bucket` config | `<provided-by-AWS-owner>` |
| `BRANDHUB_MEDIA_S3_REGION` | Media bucket region; required when enabled unless shared region is set | `settings.s3fs.php` / media validator; fallback to shared AWS region | `<provided-by-AWS-owner>` |
| `BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN` | Browser-facing hostname, without scheme or path; required when enabled | `settings.s3fs.php` / media validator; fallback to S3FS `domain` config | `<provided-by-AWS-owner>` |
| `BRANDHUB_MEDIA_ENVIRONMENT` | Required as `local` or `test` when a media S3 endpoint override is used | `settings.s3fs.php` and media validator | `local` |
| `BRANDHUB_MEDIA_S3_ENDPOINT` | Optional S3-compatible endpoint for local/test media storage | `settings.s3fs.php`; accepted only for `local` or `test` environment | `<local-endpoint-only>` |
| `AWS_ACCESS_KEY_ID` | Sensitive SDK credential; not required when using an approved role/credential provider | AWS SDK default credential chain; never configure in Drupal config or Git | **Do not put a real value here** |
| `AWS_SECRET_ACCESS_KEY` | Sensitive SDK credential; not required when using an approved role/credential provider | AWS SDK default credential chain; never configure in Drupal config or Git | **Do not put a real value here** |
| `AWS_SESSION_TOKEN` | Sensitive temporary credential; not required when using an approved role/credential provider | AWS SDK default credential chain; never configure in Drupal config or Git | **Do not put a real value here** |
| `SIMPLETEST_DB` | Optional PHPUnit/Drupal kernel-test database DSN; the kernel test selects in-memory SQLite if it is unset | `BrandhubSyncKernelTest` / Drupal PHPUnit configuration | `<test-database-dsn>` |

`BRANDHUB_SYNC_CLAIM_CHECK_THRESHOLD_BYTES` is set by one boundary test to
confirm that it cannot change the limit. Production code does not read it; the
threshold is fixed at 204800 bytes.

The non-secret config structure is in `config/sync/s3fs.settings.yml` and the
module's install/schema configuration. Environment-specific values may be
supplied to the PHP runtime or local ignored settings. Do not export populated
environment-specific active config into Git.

**Safe to commit:** service definitions, empty config structure, schema,
source code, and documentation.

**Do not commit:** credentials, tokens, private keys, `.env` files, or
environment-specific secret-bearing settings.

## 5. Information required from AWS owner

### Information required from AWS owner

Obtain only the values and approvals needed by the integration:

| Item | Needed for | Where it is used |
| --- | --- | --- |
| AWS region | SQS and S3 clients | `AWS_REGION` or `AWS_DEFAULT_REGION`; optional service-specific `BRANDHUB_SYNC_S3_REGION` / `BRANDHUB_MEDIA_S3_REGION` |
| Content-events SQS queue URL | Sending content events | `SQS_QUEUE_URL` or `brandhub_sync.settings:sqs_queue_url` |
| Queue ARN | IAM policy / AWS-side validation, not read by Drupal publisher | AWS policy and owner documentation only |
| Private claim-check bucket name | Oversized content event storage | `BRANDHUB_SYNC_S3_BUCKET` or `brandhub_sync.settings:s3_bucket` |
| Private media bucket name | S3FS public-file storage | `BRANDHUB_MEDIA_S3_BUCKET` or S3FS `bucket` configuration |
| CloudFront hostname | Browser/portal media URLs | `BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN` or S3FS `domain` |
| IAM role/credential-provider approach | SQS sends and S3 access | AWS runtime identity / standard AWS SDK credential chain; no credentials belong in the repository |
| IAM policy scope, encryption, retention, versioning | Security and lifecycle decisions | AWS-side policies; Drupal cannot establish these |
| LocalStack endpoint and test account details, if LocalStack is provided | Local S3-compatible testing | `BRANDHUB_SYNC_S3_ENDPOINT` and/or `BRANDHUB_MEDIA_S3_ENDPOINT`; no LocalStack container is included here |
| Environment/account indicator, if operationally required | Deployment operations | No AWS account ID setting is read by this code. `BRANDHUB_SYNC_ENVIRONMENT` is an object-key prefix, not an account selector. |

The Drupal SQS publisher requires `sqs:SendMessage` on the selected queue.
Claim-check storage requires S3 object write access; the downstream consumer
needs read access to retrieve the object. S3FS needs the S3 operations required
for its configured upload/read/delete behavior. The AWS owner must define and
scope the actual role and bucket/queue policies, including CloudFront OAC read
access. Do not infer or broaden IAM permissions from this application code.

Do not configure a public S3 bucket or public-read object ACLs.

## 6. SQS Configuration

### Service flow

`PublisherInterface` is transport-independent. `FakePublisher` stores messages
in memory. `SqsPublisher` JSON-encodes the message and sends it with the AWS SDK.
`SqsClientFactory` creates the AWS client only when the SQS publisher is called.
`ClaimCheckMessageProcessor` is the public `brandhub_sync.publisher` alias; it
chooses inline or claim-check delivery, then delegates to the transport.

Relevant files:

- `web/modules/custom/brandhub_sync/src/Publisher/PublisherInterface.php`
- `.../FakePublisher.php`
- `.../SqsPublisher.php`
- `.../SqsClientFactory.php`
- `web/modules/custom/brandhub_sync/brandhub_sync.services.yml`
- `web/modules/custom/brandhub_sync/config/schema/brandhub_sync.schema.yml`

`SqsPublisher` reads the URL from `brandhub_sync.settings:sqs_queue_url`, then
`SQS_QUEUE_URL`. The client region is read from
`brandhub_sync.settings:aws_region`, `AWS_REGION`, then
`AWS_DEFAULT_REGION`. The AWS SDK uses its standard secure credential provider
chain; the code does not embed credentials.

### Current selection and local/test behavior

The service YAML currently aliases `brandhub_sync.publisher.transport` to
`brandhub_sync.publisher.fake`. This is the safe default. Tests inject fake
publisher/storage implementations and do not call AWS. There is **no automatic
environment-variable switch** from fake to SQS.

To select real SQS in a reviewed deployment, explicitly change the transport
alias in `brandhub_sync.services.yml` to
`brandhub_sync.publisher.sqs`, deploy that reviewed change, configure the
queue/region and approved AWS identity, then rebuild Drupal caches. Do not make
that change merely to test locally. A `brandhub:resync` normal run uses the
same configured transport and can send real messages if SQS has been selected.

SQS and S3 exceptions are not converted into successful sends. The SQS
publisher does not log message bodies. Resync catches per-item failures,
logs the entity ID and safe exception class, continues, and returns a failing
exit code if an item failed. Lifecycle hook errors can occur after Drupal has
saved the node; use resync to reconcile a missed event rather than assuming
Drupal save and SQS send are one transaction.

## 7. Unified Schema

The schema is `docs/schemas/unified-content.schema.json`; actual mappings are
also documented in `docs/mappings/drupal-to-unified.md`.

| Unified field | Actual Drupal source | Mapping / fallback |
| --- | --- | --- |
| `id` | Node `nid` | `dr:<nid>`; node ID must be positive |
| `source` | Integration metadata | Always `drupal` |
| `type` | Node bundle | `product`, `article`, or `faq` |
| `title` | Core node title | String title |
| `summary` | Bundle-specific field below | Trimmed summary; if missing/empty, trimmed `plainText` |
| `bodyHtml` | Bundle-specific body field below | Drupal-rendered field markup; `null` if absent or empty |
| `plainText` | Same body field | Strip tags/decode entities/trim; `""` if absent or empty |
| `tags` | Bundle-specific tag entity reference | List of `{id, name}` terms; `[]` if absent or empty |
| `product` | Bundle-specific product term reference | First referenced term `{id, name}`; `null` if absent |
| `author` | Core node owner (`uid`) | `{id, name}` for resolvable owner; else `null` |
| `heroImage` | Bundle-specific image field | Absolute file URL of first file; `null` if field/file is absent |
| `publishedAt` | Core node `created` | ISO 8601/RFC 3339 when the current record is published; otherwise `null` |
| `updatedAt` | Core node `changed` | ISO 8601/RFC 3339; missing changed timestamp prevents mapping |
| `version` | Core node revision ID (`vid`) | Revision integer |
| `hash` | Integration metadata | Lowercase SHA-256 over deterministic JSON; hash property excluded |
| `status` | Publication/moderation decision | Only `published` or explicit tombstone `removed` |
| `schemaVersion` | Integration metadata | Always `"1.0"` |

Bundle fields confirmed by the mapper:

| Bundle | Summary | Body | Tags | Product term | Topic reference (not in unified schema) | Hero image |
| --- | --- | --- | --- | --- | --- | --- |
| Product | `field_summary` | `field_body` | `field_tag` | `field_product` | None | `field_hero_image` |
| Article | `field_a_summary` | Core `body` | `field_tags` | `field_a_product` | `field_a_topic` | `field_a_hero_image` |
| FAQ | No summary field | `field_answer` | `field_faq_tags` | `field_faq_product` | `field_faq_topic` | No field; `null` |

The referenced vocabularies are `product`, `tags`, and `topic`. Topic fields are
not represented in the current unified schema. The mapper does not create
Drupal fields or modify content types.

## 8. brandhub_sync Module Architecture

The module is at `web/modules/custom/brandhub_sync/`.

| File/class | Responsibility |
| --- | --- |
| `brandhub_sync.info.yml` | Declares the Drupal 11 custom module and node dependency |
| `brandhub_sync.module` | Minimal generic entity insert/update/delete hook adapter; no mapping/business policy |
| `brandhub_sync.services.yml` | Dependency injection and service aliases |
| `drush.services.yml` | Registers the resync, seed, and media validation commands |
| `config/install/brandhub_sync.settings.yml` | Safe module defaults: claim-check disabled, `local` environment, empty AWS/S3 values |
| `config/schema/brandhub_sync.schema.yml` | Typed schema for module configuration |
| `src/Mapper/NodeToUnifiedContentMapper.php` | Maps supported node fields to the unified record |
| `src/Mapper/ContentHasher.php` | Canonicalizes JSON object keys and calculates SHA-256 |
| `src/Moderation/ModerationStatusResolver.php` | Resolves currently publishable moderation state |
| `src/Moderation/ContentChangeDecider.php` | Compares current/original state to decide publish/remove/skip |
| `src/Handler/EntityChangeHandler.php` | Coordinates supported bundles, change decision, event creation, and publishing |
| `src/Publisher/ContentChangedEventFactory.php` | Creates the event envelope and validates required content fields |
| `src/Publisher/PublisherInterface.php` | AWS-independent publishing contract |
| `src/Publisher/FakePublisher.php` | In-memory publisher for tests |
| `src/Publisher/SqsPublisher.php` / `SqsClientFactory.php` | AWS SDK SQS transport and lazy client creation |
| `src/Publisher/ClaimCheckMessageProcessor.php` | Serializes complete event, checks size, uploads oversized JSON, sends pointer |
| `src/Publisher/ClaimCheckStorageInterface.php` | Storage contract for claim-check objects |
| `src/Publisher/S3ClaimCheckStorage.php` | AWS SDK S3 implementation |
| `src/Publisher/FakeClaimCheckStorage.php` | In-memory test storage |
| `src/Resync/ContentResyncService.php` | Bounded published-node re-emission |
| `src/Seed/SeedContentGenerator.php` / `SeedContentService.php` | Deterministic realistic seed records, taxonomy handling, reset safety |
| `src/Seed/SeedExecutionContext.php` | Suppresses ordinary lifecycle publishing during seed actions |
| `src/Drush/Commands/BrandhubResyncCommands.php` | `brandhub:resync` command options and exit code |
| `src/Drush/Commands/BrandhubSeedCommands.php` | `brandhub:seed` command options and exit code |
| `src/Drush/Commands/BrandhubMediaConfigCommands.php` | Validates media settings without AWS requests |

Service dependencies are described in the module's two YAML service files.
The lifecycle hook adapter calls `entity_change_handler`; that service uses
the mapper, decider, event factory, and `brandhub_sync.publisher` alias.
`brandhub_sync.publisher` points to the claim-check processor, which uses
`brandhub_sync.publisher.transport` and the claim-check storage interface.

## 9. Publishing Process

For an eligible node save:

1. Drupal runs generic entity lifecycle hooks after the entity save operation.
2. The adapter ignores non-node entities and seed-command saves.
3. The handler rejects unsupported bundles and asks the decider to compare
   current and original values.
4. Non-default revision updates are ignored.
5. The mapper renders the source fields and builds the unified content record.
6. `ContentHasher` sorts JSON object keys recursively, preserves list order,
   excludes the `hash` property, and calculates SHA-256.
7. `ContentChangedEventFactory` adds `content.changed`, UUID event and
   correlation IDs, current ISO timestamp, schema version, and source.
8. The claim-check processor encodes the complete event and measures its UTF-8
   bytes.
9. At or below 204800 bytes, it passes the complete event to the configured
   transport. Above that size, if claim-check is enabled, it stores the full
   JSON event before forwarding an envelope containing the pointer.
10. The selected transport is currently `FakePublisher`; a deployment must
    explicitly select SQS for a real queue send.

## 10. Moderation Rules

| Drupal operation/state | Current behavior |
| --- | --- |
| New Draft | No message |
| Draft update | No message |
| In review save | No published message |
| Draft or In review -> Published | One `published` event |
| Published -> Published update on default revision | One `published` event |
| Published -> unpublished/non-published moderation state | One `removed` event |
| Delete a previously published node | One `removed` event |
| Delete a node that was never published | No message |
| Unsupported bundle | No message |
| Non-default revision update | No message |

The adapter uses generic entity hooks. `EntityChangeHandler` keeps completed
operation signatures in a `WeakMap` keyed by entity object, including operation,
entity ID, revision, changed timestamp, status, and hash. This prevents
duplicate handling of the same lifecycle callback signature during that
request. It is not a cross-request, queue, or downstream idempotency guarantee.

## 11. Claim-Check / Large Message Process

The fixed cutoff is **204800 bytes (200 KiB)** of the complete event JSON,
encoded with `JSON_UNESCAPED_SLASHES` and `JSON_UNESCAPED_UNICODE`. `strlen()`
measures the serialized UTF-8 byte length. Exactly 204800 bytes stays inline;
only a larger event uses claim-check.

```text
At/below 204800 bytes: Drupal -> selected transport (SQS only when selected)
Above 204800 bytes: Drupal -> S3 full JSON -> selected transport with pointer
```

The object key is deterministic:

```text
{environment}/{source}/{numeric-entity-id}/{revision}/{payload-sha256}.json
```

The pointer contains `bucket`, `objectKey`, optional `versionId` and/or `etag`,
`contentType` (`application/json`), `originalPayloadSize`, `sha256`, and
`schemaVersion`. Its checksum is SHA-256 of the exact full JSON bytes uploaded.
The S3 upload completes before any transport publish. An upload failure stops
the send; a subsequent SQS failure may leave an uploaded object for lifecycle
cleanup. The bucket must be private.

`brandhub_sync.settings` and environment values configure the claim-check
enable flag, environment key prefix, bucket, region, and optional S3 endpoint.
Claim-check is disabled by default. Local tests use fake storage and transport.
The test suite includes a body fixture larger than 500 KB and verifies that the
fake storage path is used; tests do not contact AWS.

## 12. Drush Resync

`brandhub:resync` re-emits a `published` content event for currently published
Product, Article, and FAQ nodes. It pages IDs, loads one batch at a time, and
reuses the mapper, event factory, claim-check processor, and configured
transport. It does not publish drafts or unsupported bundles.

Options: `--bundle=product|article|faq`, `--batch-size` (1–1000; default 100),
`--dry-run`, and `--limit`.

```powershell
# Safe preview of at most 10 nodes, five at a time.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --dry-run --batch-size=5 --limit=10

# Preview a single bundle.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --bundle=product --dry-run --limit=10

# Real send only after the SQS transport and AWS configuration are approved.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --bundle=faq --batch-size=5 --limit=10
```

Dry-run maps and reports content ID, revision, and hash; it does not create an
event, publish to SQS, or upload to S3. Normal runs print processed, sent,
skipped, and failed counts. Per-item failures are logged safely and do not hide
later results; a non-zero exit code indicates failure.

Repeated unchanged content has stable content ID, revision/version, and hash.
Event UUIDs and timestamps are intentionally new. The downstream
Lambda/DynamoDB consumer must ignore identical or older versions; Drupal alone
does not make the consumer idempotent.

## 13. Seed Data

The deterministic `brandhub:seed` command targets up to 10 Product, 20 Article,
and 15 FAQ records, defaulting to those counts. Content is Draft unless
`--publish` is supplied. It uses stable seed identifiers and a private state
registry to update/skip rather than duplicate its own records. Taxonomy terms
are reused by vocabulary/name or created; only terms registered as seed-created
and no longer referenced can be removed by reset. No images are downloaded;
optional image fields stay empty.

```powershell
# One draft of each type for manual testing.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --products=1 --articles=1 --faqs=1

# Default final dataset: 10 Products, 20 Articles, 15 FAQs.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed

# Preview without saving.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --dry-run

# Explicitly publish seed nodes (lifecycle SQS/S3 publishing is suppressed by
# the seed execution context).
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --publish

# Preview reset, then reset only registered seed content.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --reset --dry-run
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --reset
```

The last verified local database contained exactly 10 Product, 20 Article, and
15 FAQ seed records; a seed dry-run reported 45 skipped and no creates,
updates, or failures. This is a point-in-time database observation, not a
fresh-clone guarantee.

## 14. Testing

Run from the repository root. Composer scripts are configured in
`composer.json`:

```powershell
& 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar test
& 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar phpcs
& 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar phpstan
& 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar quality
```

`test` runs PHPUnit using `web/core/phpunit.xml.dist` and the module test tree.
The kernel test uses in-memory SQLite if `SIMPLETEST_DB` is not set. Claim-check
and publishing tests use fake publisher/storage classes.

Focused examples:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\phpunit -c web\core\phpunit.xml.dist web\modules\custom\brandhub_sync\tests\src\Kernel\BrandhubSyncKernelTest.php
& 'C:\xampp83\php\php.exe' vendor\bin\phpunit -c web\core\phpunit.xml.dist web\modules\custom\brandhub_sync\tests\src\Unit\Mapper\NodeToUnifiedContentMapperTest.php
& 'C:\xampp83\php\php.exe' vendor\bin\phpunit -c web\core\phpunit.xml.dist web\modules\custom\brandhub_sync\tests\src\Unit\Moderation\ContentChangeDeciderTest.php
& 'C:\xampp83\php\php.exe' vendor\bin\phpunit -c web\core\phpunit.xml.dist web\modules\custom\brandhub_sync\tests\src\Unit\Publisher\ClaimCheckMessageProcessorTest.php
& 'C:\xampp83\php\php.exe' vendor\bin\phpunit -c web\core\phpunit.xml.dist web\modules\custom\brandhub_sync\tests\src\Unit\Resync\ContentResyncServiceTest.php
& 'C:\xampp83\php\php.exe' vendor\bin\phpunit -c web\core\phpunit.xml.dist web\modules\custom\brandhub_sync\tests\src\Unit\Seed\SeedContentGeneratorTest.php
```

Existing tests cover mapper/hash, moderation lifecycle, event construction,
claim-check size/checksum/failure ordering/large fixture, resync, and seed
generation. There are **no listing functional tests** in the module. The active
database has enabled Views named `product`, `articles_by_topic`, and `faq`
(the prior local README records `/product`, `/articles`, and `/faq`), but those
Views are absent from `config/sync`, so clean CI cannot reproduce them. Do not
claim functional listing tests pass or invent a test command for tests that do
not exist.

Quality scripts run PHPCS using both Drupal and DrupalPractice standards and
PHPStan level 5 over `src/`. `phpstan.neon` uses `.phpstan-cache`.

Last recorded project verification: PHPUnit **42 tests / 237 assertions**,
Drupal and DrupalPractice PHPCS passed, PHPStan **0 errors**. These are prior
local results and should be rerun after changes; a PHPUnit warning about the
Drupal browser-output directory not being writable was non-fatal in that run.

## 15. S3FS / Drupal Media

The project has Composer-managed S3FS 3.12.0 and the AWS SDK. S3FS is enabled in
the current database. The exported `config/sync/s3fs.settings.yml` uses S3FS
3.12.0 keys and leaves bucket, region, and domain empty. There is no checked-in
LocalStack service. The current media configuration validator reports local
public files when S3 is not explicitly enabled.

The integration uses image/file fields; the Drupal Media entity module is not
required by this implementation. Existing image fields include
`field_hero_image` on Product and `field_a_hero_image` on Article. FAQ has no
hero image field. The `thumbnail` image style exists, scaling to 100x100 and
converting to WebP.

When all required values are present and `BRANDHUB_MEDIA_S3_ENABLED=true`,
`settings.s3fs.php` enables S3FS for `public://`, sets uploads private, and uses
the CloudFront hostname for browser-facing URLs. S3 object keys include the
`s3fs-public/` prefix. Local development continues to use local `public://`
files by default. An S3 endpoint override is accepted only with
`BRANDHUB_MEDIA_ENVIRONMENT=local` or `test`.

The `brandhub:media-config` command checks required configuration presence and
format but makes no AWS requests and does not validate credentials,
permissions, bucket existence, CloudFront, or LocalStack:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:media-config
```

CloudFront OAC, bucket privacy, encryption, lifecycle, IAM, and cache policy
belong to AWS. No real upload, S3 access-denial check, or CloudFront cache
verification has been performed.

## 16. Complete End-to-End Flow

1. An editor creates or updates an existing Product, Article, or FAQ node.
2. Drupal saves the entity; the generic hooks receive insert/update/delete.
3. `ContentChangeDecider` uses current and original publication/moderation
   values to determine publish, removed, or skip.
4. The mapper reads the actual bundle fields and creates the unified record.
5. The revision is used as `version`; deterministic content JSON is hashed.
6. The event factory adds event ID, correlation ID, timestamp, schema version,
   and source.
7. The claim-check processor measures the full JSON. Small events stay inline;
   larger events are stored in the configured private S3 bucket before a
   pointer message is passed to the transport.
8. The default transport is currently fake. With an explicitly configured SQS
   transport, SQS receives the event/pointer.
9. A Lambda/DynamoDB or other AWS consumer is outside this Drupal repository.
   The AWS consumer must retrieve claim-check objects, validate the event, and
   ignore identical/older versions according to the agreed contract.

The downstream processing steps are architectural integration points, not
verified implementations in this Drupal repository.

## 17. Fresh Environment Verification

- [ ] Clone the repository and review `git status --short`.
- [ ] Install the locked Composer dependencies.
- [ ] Configure a local database in ignored `settings.php`.
- [ ] Obtain the missing complete BrandHub site configuration or approved
  sanitized database source; do not assume `config/sync` is a full export.
- [ ] Configure process environment values only for the services under test.
- [ ] Apply reviewed configuration with the correct partial/full strategy.
- [ ] Enable required modules only after their configuration dependencies are
  present.
- [ ] Rebuild cache and inspect status:

  ```powershell
  & 'C:\xampp83\php\php.exe' vendor\bin\drush.php cr
  & 'C:\xampp83\php\php.exe' vendor\bin\drush.php status
  & 'C:\xampp83\php\php.exe' vendor\bin\drush.php pml --filter=brandhub_sync
  ```

- [ ] Verify `product`, `article`, and `faq` node bundles and their fields
  before creating test content.
- [ ] Verify the `brandhub_content_workflow` workflow and its states.
- [ ] Run `composer test`, `composer phpcs`, and `composer phpstan`.
- [ ] Run `brandhub:seed --dry-run`.
- [ ] Run `brandhub:resync --dry-run --limit=10`.
- [ ] Run `brandhub:media-config`; local fallback should be selected unless
  media offload was explicitly configured.
- [ ] Confirm SQS and claim-check use fake/disabled settings for local tests.
- [ ] Confirm S3FS/CloudFront external settings with the AWS owner before any
  live request.

## 18. AWS Integration Verification

Do not execute the following live checks until the AWS owner approves a
non-production account, queue, private buckets, IAM role, encryption, retention,
and CloudFront OAC. Never run these against production as an initial test.

1. Obtain the values listed in “Information required from AWS owner.”
2. Put region, queue URL, claim-check bucket, media bucket, and CloudFront domain
   into the PHP runtime environment or approved local untracked settings. Use
   credentials only through the approved SDK provider/role. Do not add them to
   Drupal config exports or Git.
3. Run `drush cr`, `drush brandhub:media-config`, and inspect the transport
   alias. The validator does not contact AWS.
4. For one content-event smoke test, explicitly select SQS in the reviewed
   service configuration, rebuild cache, create a temporary non-production
   Product, and publish it through the Drupal UI at `/node/add/product`. Verify
   the queue/consumer through AWS-approved observability. This repository does
   not provide a command that reads the queue or exposes the fake publisher's
   message contents.
5. The safe automated oversized-message check is:

   ```powershell
   & 'C:\xampp83\php\php.exe' vendor\bin\phpunit -c web\core\phpunit.xml.dist web\modules\custom\brandhub_sync\tests\src\Unit\Publisher\ClaimCheckMessageProcessorTest.php --filter testLargeBodyFixtureUsesClaimCheck
   ```

   It uses fake storage and publisher only. There is no dedicated live AWS
   claim-check test command. If the AWS owner requires a real >200 KiB / 500 KiB
   test, agree a disposable non-production node and cleanup/consumer procedure
   first; do not mistake the fake-backed fixture test for an AWS integration.
6. For media, keep content unpublished and upload one approved test image using
   the existing Product image field. With S3 media explicitly enabled, check
   the private S3 object with AWS-owner-approved tooling, request the generated
   `thumbnail` derivative, verify direct S3 access is denied, and request the
   CloudFront URL twice. Confirm expected cache headers (`X-Cache`, `Age`,
   `Via`) with the AWS owner because cache behavior depends on their policy.
7. For a bounded resync, first preview and then, only when approved, run:

   ```powershell
   & 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --bundle=product --dry-run --limit=1
   & 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --bundle=product --limit=1
   ```

   The normal command sends using the selected transport. Confirm that the
   queue/consumer is non-production and that the one eligible Product is
   intended for re-emission.

For an approved image object check, the existing README's AWS CLI command uses
the environment rather than embedding bucket values:

```powershell
$mediaKey = '<object-key-from-approved-test-file>'
aws s3api head-object --bucket $env:BRANDHUB_MEDIA_S3_BUCKET --key "s3fs-public/$mediaKey" --region $env:BRANDHUB_MEDIA_S3_REGION
```

An object HEAD check verifies presence/metadata, not private access or CloudFront
behavior. Do not run `s3fs`'s “Validate Configuration” action casually: S3FS
documents that it makes AWS requests and may write a temporary object.

## 19. Troubleshooting

| Symptom | Safe diagnostic / likely cause |
| --- | --- |
| Drush command not found | Run `& 'C:\xampp83\php\php.exe' vendor\bin\drush.php status` from the repository root; confirm `vendor/` exists and Composer install completed. |
| `brandhub:*` command not found | Confirm the module is enabled with `drush pml --filter=brandhub_sync`, then run `drush cr`; inspect `drush.services.yml` and service compilation errors. |
| Module not found | Confirm `web/modules/custom/brandhub_sync/brandhub_sync.info.yml` exists and dependencies were installed; run `drush cr`. |
| Services YAML error | Validate indentation and class/service names in `brandhub_sync.services.yml` and `drush.services.yml`; run `drush cr` and inspect the Drupal log (`drush ws --count=20`). |
| Config import fails | This sync directory is partial. Use `drush cim --partial -y` only for reviewed scoped config such as S3FS; do not use full import until full config drift is resolved. |
| Required environment value missing | Run `drush brandhub:media-config` for media settings. For SQS/claim-check, compare runtime environment to the table above; the error messages identify variable names, not values. |
| SQS send fails | Confirm SQS is explicitly selected, queue URL and region are available to PHP, and the approved role grants `sqs:SendMessage`. Inspect Drupal logs and AWS-side queue/role diagnostics; never log message bodies or credentials. |
| S3 claim-check fails | Confirm claim-check is enabled, private bucket and region are configured, endpoint is correct for the environment, and AWS role allows the operation. Upload errors stop SQS publishing. |
| Claim-check size error | The limit is fixed at 204800 bytes. At/below it is inline; above requires enabled claim-check and valid entity metadata. The legacy threshold env variable is ignored. |
| S3FS upload fails | Run the no-network `brandhub:media-config` validator first. Then confirm the runtime opt-in, bucket, region, domain, and local/test endpoint gate. AWS credentials, IAM, bucket policy, and CloudFront are external. |
| CloudFront URL fails | Check the hostname and S3FS `domain` value; have AWS verify distribution origin, OAC/bucket policy, path prefix, TLS, and cache policy. Do not make the bucket public as a workaround. |
| PHPUnit fails | Run the focused PHPUnit file first, then `composer test`; confirm PHP 8.3 and the configured Drupal PHPUnit bootstrap. Kernel tests use in-memory SQLite unless `SIMPLETEST_DB` is set. |
| PHPCS fails | Run `composer phpcs`; fix Drupal and DrupalPractice findings in project code rather than weakening standards. |
| PHPStan fails | Run `composer phpstan`; inspect `phpstan.neon` (level 5, module `src/`) and fix typed code issues rather than lowering the level. |

Drupal log inspection:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php ws --count=20
```

## 20. Files Created/Changed

This inventory identifies the current implementation files relevant to Unified
Schema through media integration. It is a workspace inventory, not a claim that
every file is already committed or that Git history records its original
creation.

### Schema and mapping

- `docs/schemas/unified-content.schema.json` — unified record and event schema.
- `docs/mappings/drupal-to-unified.md` — actual source fields and fallbacks.
- `docs/samples/drupal-product.json`, `drupal-article.json`,
  `drupal-faq.json` — representative mapped records.

### brandhub_sync module and services

- `web/modules/custom/brandhub_sync/brandhub_sync.info.yml`
- `web/modules/custom/brandhub_sync/brandhub_sync.module`
- `web/modules/custom/brandhub_sync/brandhub_sync.services.yml`
- `web/modules/custom/brandhub_sync/drush.services.yml`
- `web/modules/custom/brandhub_sync/config/install/brandhub_sync.settings.yml`
- `web/modules/custom/brandhub_sync/config/schema/brandhub_sync.schema.yml`
- `web/modules/custom/brandhub_sync/src/Handler/EntityChangeHandler.php`
- `web/modules/custom/brandhub_sync/src/Mapper/ContentHasher.php`
- `web/modules/custom/brandhub_sync/src/Mapper/NodeToUnifiedContentMapper.php`
- `web/modules/custom/brandhub_sync/src/Mapper/NodeToUnifiedContentMapperInterface.php`
- `web/modules/custom/brandhub_sync/src/Moderation/ContentChangeDecider.php`
- `web/modules/custom/brandhub_sync/src/Moderation/ModerationStatusResolver.php`
- `web/modules/custom/brandhub_sync/src/Publisher/ClaimCheckMessageProcessor.php`
- `web/modules/custom/brandhub_sync/src/Publisher/ClaimCheckStorageInterface.php`
- `web/modules/custom/brandhub_sync/src/Publisher/ContentChangedEventFactory.php`
- `web/modules/custom/brandhub_sync/src/Publisher/FakeClaimCheckStorage.php`
- `web/modules/custom/brandhub_sync/src/Publisher/FakePublisher.php`
- `web/modules/custom/brandhub_sync/src/Publisher/PublisherInterface.php`
- `web/modules/custom/brandhub_sync/src/Publisher/S3ClaimCheckStorage.php`
- `web/modules/custom/brandhub_sync/src/Publisher/SqsClientFactory.php`
- `web/modules/custom/brandhub_sync/src/Publisher/SqsPublisher.php`
- `web/modules/custom/brandhub_sync/src/Resync/ContentResyncService.php`
- `web/modules/custom/brandhub_sync/src/Seed/SeedContentGenerator.php`
- `web/modules/custom/brandhub_sync/src/Seed/SeedContentService.php`
- `web/modules/custom/brandhub_sync/src/Seed/SeedExecutionContext.php`
- `web/modules/custom/brandhub_sync/src/Drush/Commands/BrandhubMediaConfigCommands.php`
- `web/modules/custom/brandhub_sync/src/Drush/Commands/BrandhubResyncCommands.php`
- `web/modules/custom/brandhub_sync/src/Drush/Commands/BrandhubSeedCommands.php`

### Tests

- `web/modules/custom/brandhub_sync/tests/fixtures/large-content-body.php`
- `tests/src/Kernel/BrandhubSyncKernelTest.php`
- Unit tests under `web/modules/custom/brandhub_sync/tests/src/Unit/Mapper/`:
  `ContentHasherTest.php`, `NodeToUnifiedContentMapperTest.php`.
- Unit tests under `web/modules/custom/brandhub_sync/tests/src/Unit/Moderation/`:
  `ContentChangeDeciderTest.php`, `ModerationStatusResolverTest.php`.
- Unit tests under `web/modules/custom/brandhub_sync/tests/src/Unit/Publisher/`:
  `ClaimCheckMessageProcessorTest.php`, `ContentChangedEventFactoryTest.php`,
  `FakePublisherTest.php`.
- `web/modules/custom/brandhub_sync/tests/src/Unit/Resync/ContentResyncServiceTest.php`
- `web/modules/custom/brandhub_sync/tests/src/Unit/Seed/SeedContentGeneratorTest.php`

There are no functional listing test files in the module test tree.

### Configuration and quality tooling

- `composer.json`, `composer.lock` — Drupal/S3FS/AWS dependencies and
  `test`, `phpcs`, `phpstan`, `quality` scripts.
- `phpstan.neon` — module PHPStan level and source path.
- `.gitignore`, `web/.gitignore` — local secrets, generated files, and scaffold
  runtime exclusions.
- `config/sync/s3fs.settings.yml` — exportable S3FS configuration with
  environment-specific values blank.
- `config/sync/.htaccess`, `config/sync/.gitkeep` — sync-directory support.
- `web/sites/default/settings.s3fs.php` — environment-only S3FS overrides;
  contains no credentials.
- `web/modules/custom/brandhub_sync/README.md` — module setup and operations.
- `docs/DRUPAL_BRANDHUB_SETUP_AND_PROCESS.md` — this handover document.

The local `web/sites/default/settings.php` is intentionally ignored and is not
part of the source inventory.

## 21. Security Checklist

- [x] No real AWS credential values are included in this document.
- [x] AWS credentials are not hard-coded in BrandHub module or S3FS settings.
- [x] Runtime AWS identity is delegated to the SDK credential provider chain.
- [x] `.env`, vendor, Drupal core, database dumps, local settings, and generated
  uploads are covered by current ignore rules.
- [x] A tracked-file scan in the preceding final review found no tracked
  `.env`, vendor/core, dump/upload, or credential-file paths.
- [x] Automated publishing/claim-check tests use fake publisher/storage and
  do not call real AWS.
- [x] Claim-check/media buckets are expected to remain private.
- [x] CloudFront is the intended public browser path for media when configured.
- [ ] AWS IAM, bucket policy, encryption, CloudFront OAC, and live access-denial
  behavior require AWS-owner review and external verification.

The current worktree still had modified/untracked source/config files during
review. Re-run `git status --short` and the secret/ignore review before
committing or sharing a clone.

## 22. What Is Complete vs What Is Still External

### COMPLETED IN DRUPAL REPOSITORY

- Unified schema, actual field mapping, sample Product/Article/FAQ records.
- `brandhub_sync` mapper, deterministic hash, event envelope, moderation
  decision, lifecycle adapter, fake and SQS transport implementations.
- Claim-check processing, deterministic object-key/checksum/pointer construction,
  fake storage, and a 204800-byte boundary.
- Resync and seed Drush commands with unit coverage.
- S3FS Composer dependency, blank exportable config, environment-driven
  opt-in, and no-AWS configuration validator.
- Composer test/PHPCS/PHPStan scripts and the module's current kernel/unit
  tests.

### REQUIRES AWS / OTHER TEAM

- Full Drupal site configuration for new-environment reproduction, including
  existing content model, workflow, taxonomy, and listing Views.
- AWS region, queue URL, private claim-check/media bucket names, runtime role,
  policy, encryption/versioning/lifecycle decisions, and CloudFront hostname/OAC.
- Explicit deployment selection of the SQS publisher transport.
- Real SQS, claim-check S3, S3FS upload, private-access, CloudFront delivery, and
  cache verification.
- Downstream consumer behavior, including claim-check retrieval and version/
  hash idempotency.
- Listing functional tests and export/review of the active database Views.

### IMPLEMENTED / TESTED / CONFIGURED STATUS

- **Implemented:** mapping, moderation, claim-check, resync, seed, SQS/S3
  adapters, S3FS settings, validator.
- **Tested locally:** module tests with fakes; PHPCS and PHPStan were previously
  reported passing. Rerun the commands in Section 14 for the current checkout.
- **Configured in the current local database:** Drupal 11.0.0, MySQL driver,
  S3FS and `brandhub_sync` enabled, active listing Views, and a populated
  sample database.
- **Not configured/verified for live AWS:** queue, bucket credentials/role,
  CloudFront access, live publishing, and media offload.
- **Not reproducible from Git alone yet:** full site configuration and listing
  functional test setup.

## 23. New Developer Quick Start

1. Install PHP 8.3 and Composer, clone the repository, and run:

   ```powershell
   & 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar install
   ```

2. Configure MySQL and ignored `web/sites/default/settings.php`. Ask the
   Drupal owner for the full BrandHub site configuration or approved database
   bootstrap; the current `config/sync` is partial.
3. Keep AWS integrations disabled/fake by default. Do not add credentials to
   Git or Drupal config exports.
4. Run safe local checks:

   ```powershell
   & 'C:\xampp83\php\php.exe' vendor\bin\drush.php status
   & 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:media-config
   & 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --dry-run
   & 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --dry-run --limit=10
   & 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar quality
   ```

5. Obtain explicit AWS-owner approval and non-production values before
   selecting the SQS transport or enabling S3 media/claim-check.
