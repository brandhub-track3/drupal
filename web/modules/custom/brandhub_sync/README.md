# BrandHub Sync

This guide documents the current Drupal project setup and the `brandhub_sync`
module. It is written for this Windows/XAMPP workspace and uses the installed
project paths and commands.

## Project setup

| Item | Current project value |
| --- | --- |
| Drupal | 11.0.0 |
| Drush | 13.8.0 |
| PHP used by the project | `C:\xampp83\php\php.exe` (PHP 8.3) |
| Drupal document root | `web/` |
| Custom module | `web/modules/custom/brandhub_sync/` |
| Config export directory | `config/sync/` |
| Local settings | `web/sites/default/settings.php` (machine-specific; do not commit) |
| Database | Local Drupal database configured by `settings.php` |

The project is Composer-managed. Its current direct dependencies include Drupal
Core 11, Drush 13, Search API, and the AWS SDK for PHP. The Composer manifest
and lock file are in the repository root.

### First-time setup on this computer

Open PowerShell in the repository root:

```powershell
Set-Location C:\xampp83\htdocs\bbc-project\drupal
```

Install the exact dependency versions recorded in `composer.lock`:

```powershell
composer install
```

Start Apache and MySQL from XAMPP and make sure this site's database exists and
is configured in your local `web/sites/default/settings.php`. Do not replace
an existing settings file or copy database credentials into this README.
Configure the web server's document root to the project's `web/` directory.
The installed site uses that local settings file; Drupal's database was
connected when this guide was last verified.

The local settings file points Drupal's configuration sync directory to
`../config/sync`, relative to `web/`. Keep local database credentials in
ignored settings/environment files. Do not commit credentials, `.env` files,
database backups/dumps, or generated files.

### Drush on Windows

Use the PHP 8.3 executable and `vendor\bin\drush.php` from the project root:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php status
```

This avoids two local issues: `vendor\bin\drush.bat` tries to call `sh`, which
is not normally installed on Windows, and an unqualified `php` command may
select another PHP installation. If XAMPP is installed elsewhere, change the
PHP executable path in the commands below.

Useful project checks:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php status
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php pml --filter=brandhub_sync
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php cr
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php ws --count=20
```

### Drupal configuration in Git

The sync directory is `config/sync/`; it contains Drupal configuration YAML,
not site content. Exporting configuration does not export or alter nodes:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php config:status
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php config:export
git status --short
git diff -- config/sync
```

Review the export before adding or committing it. Import is a separate,
intentional operation that changes active Drupal configuration:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php config:import
```

Do not import configuration unless the proposed changes are understood and
intended. Detailed export/import guidance is in the repository-root
[`drush-read.me`](../../../../drush-read.me).

## What this module does

`brandhub_sync` maps the existing `product`, `article`, and `faq` Drupal node
bundles to the project's unified content schema. It uses field machine names
already present on this site; it does not create or alter content types,
fields, workflows, or existing content.

The schema and mapping details are documented in
[`docs/schemas/unified-content.schema.json`](../../../../docs/schemas/unified-content.schema.json)
and
[`docs/mappings/drupal-to-unified.md`](../../../../docs/mappings/drupal-to-unified.md).
The module is currently enabled in the local site, but enabling it is not
automatic on a newly set-up environment.

### Existing content and moderation configuration

The site's Content Moderation workflow is `brandhub_content_workflow` with
states `draft`, `in_review`, and `published`. The module only reacts to entity
insert, update, and delete hooks:

- New or updated Draft/In review content produces no event.
- Publishing a supported node, or saving an already-published default
  revision, produces one `content.changed` event with `published` status.
- Unpublishing a previously published node or deleting a published node
  produces one `content.changed` event with `removed` status.
- Deleting a node that was never published, non-default revision saves, and
  unsupported bundles produce no event.

The module neither exports Drupal nodes into `config/sync` nor deletes or
modifies existing site content.

## Phase 17: full content resync

The Drush command `brandhub:resync` re-emits a `content.changed` event for
currently published `product`, `article`, and `faq` nodes. It queries only
published supported bundles, pages node IDs, and loads no more than one batch
at a time. The existing mapper, event factory, claim-check processor, and
configured publisher transport are reused; the command does not implement a
second content mapping or AWS client.

Options:

| Option | Meaning |
| --- | --- |
| `--bundle=product\|article\|faq` | Restrict resync to one supported bundle. |
| `--batch-size=N` | Number of node IDs loaded per batch; defaults to 100 and accepts 1–1000. |
| `--dry-run` | Map and report candidates, including stable content ID, revision, and hash, without creating an event or publishing. |
| `--limit=N` | Maximum eligible published nodes to examine; useful for safe local runs. |

From the project root, preview a few nodes before a live resync:

```powershell
# Preview at most 10 eligible nodes, loading 5 per batch.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --dry-run --batch-size=5 --limit=10

# Preview a single bundle.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --bundle=product --dry-run --limit=10

# Resend the first 10 published FAQ nodes.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --bundle=faq --batch-size=5 --limit=10

# Resend all published supported nodes in batches of 100.
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:resync --batch-size=100
```

Dry-run never calls the publisher, SQS, or S3. Normal execution emits one
published event for each eligible node using the configured publisher. The
final summary reports `processed`, `sent`, `skipped`, and `failed`; an item
failure is logged with its entity ID and safe exception class, processing
continues, and the command exits non-zero if any item failed. Draft and
unpublished nodes are excluded without loading them; unsupported bundles are
also excluded. The skipped count includes these excluded nodes.

Resyncing unchanged content produces the same Drupal content ID, revision, and
content hash. Event IDs and occurrence timestamps are intentionally new for
each send. This does **not** make downstream delivery idempotent by itself.
The Lambda/DynamoDB consumer must deduplicate or ignore messages with identical
content hashes and reject versions that are identical to or older than the
stored entity version.

### Enable, verify, and roll back

From the project root, enable the module and rebuild caches:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php en brandhub_sync -y
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php cr
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php pml --filter=brandhub_sync
```

To uninstall it if rollback is needed:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php pm:uninstall brandhub_sync -y
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php cr
```

Uninstalling a module removes its installed configuration. Review any
module-owned configuration before uninstalling; this module is not intended
to own or remove editorial content.

## Services

- `brandhub_sync.mapper` maps a node to the unified record.
- `brandhub_sync.content_hasher` hashes the mapped record using recursively
  sorted JSON object keys and SHA-256; list order is preserved and the `hash`
  property itself is excluded.
- `brandhub_sync.content_changed_event_factory` adds UUID event/correlation
  identifiers and an ISO 8601 event timestamp.
- `brandhub_sync.moderation_status_resolver` publishes only published nodes,
  skips drafts and in-review nodes, and marks explicit deletions as `removed`.
- `brandhub_sync.content_change_decider` compares saved nodes with Drupal's
  original entity values to decide whether to publish, remove, or skip.
- `brandhub_sync.entity_change_handler` coordinates mapping and publishing.
- `brandhub_sync.publisher.fake` collects messages in memory and makes no
  external requests.
- `brandhub_sync.publisher.sqs` sends JSON records to Amazon SQS when explicitly
  selected.
- `brandhub_sync.sqs_client_factory` creates the AWS client lazily, only when
  SQS publishing is requested.

The `hook_entity_insert()`, `hook_entity_update()`, and `hook_entity_delete()`
implementations in `brandhub_sync.module` are the only lifecycle adapters used
by this module. Drupal invokes entity-type-specific hooks as well as generic
entity hooks for a node save; using only the generic hooks avoids handling the
same operation twice. The adapter passes the original entity to the handler for
updates. The handler also guards repeated callbacks for the same entity
revision/change signature.

Eligible changes are published as an envelope with `event: content.changed`,
`eventId`, `occurredAt`, `correlationId`, `schemaVersion`, `source`, and the
unified record under `content`. The content payload's SHA-256 hash is calculated
from deterministic JSON before publishing. Draft and in-review nodes are skipped;
published updates send `status: published`, and published-to-unpublished
transitions or published deletions send `status: removed`. Deleting an
unpublished node does not send an event.
The envelope contract is defined in the schema's
`$defs.contentChangedEvent`.

Entity insert/update hooks run after storage save hooks. Updates that do not
become the default revision are ignored, so unpublished revision saves are not
sent as public content.

The `brandhub_sync.publisher` service runs messages through the claim-check
processor. Its downstream `brandhub_sync.publisher.transport` uses the fake
publisher by default. The AWS SDK is installed, but SQS is not selected by
default. To enable SQS, configure its region, queue URL, and AWS credentials
securely, change the transport alias in `brandhub_sync.services.yml` to
`brandhub_sync.publisher.sqs`, and rebuild caches. Do not change the alias
merely to test the module.

## Phase 18: Repeatable local seed content

Use `brandhub:seed` to create the project sample dataset. The defaults are 10
Product, 20 Article, and 15 FAQ nodes. New records are drafts unless
`--publish` is explicitly supplied. The command uses deterministic UUIDs and a
private Drupal state registry, so repeated runs update changed seed records or
skip unchanged ones rather than duplicating them. It validates required Drupal
fields before saving and prints created, updated, skipped, failed, and deleted
counts.

The generated records populate the existing fields: Product uses
`field_summary`, `field_body`, `field_product`, `field_published_date`, and
`field_tag`; Article uses `body`, `field_a_summary`, `field_a_product`,
`field_a_topic`, and `field_tags`; FAQ uses `field_answer`, `field_faq_product`,
`field_faq_topic`, and `field_faq_tags`. Product, topic, and tag terms are
reused by vocabulary and name, or created if missing. The product and article
hero-image fields and optional FAQ image fields are left empty because no local
fixture image was present; the command never downloads images.

From the project root, create one item of each type for manual testing:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --products=1 --articles=1 --faqs=1
```

Create the final 10 Product, 20 Article, and 15 FAQ dataset:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed
```

Preview the final dataset without creating taxonomy terms or nodes:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --dry-run
```

Only publish Drupal seed nodes when explicitly requested:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --publish
```

Safely preview or reset only content and taxonomy terms registered by this
seed command:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --reset --dry-run
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:seed --reset
```

Reset does not delete unregistered nodes or reused taxonomy terms. A
seed-created term is retained if any node still references it. Seed saves and
deletes suppress this module's outbound entity lifecycle publishing, including
for `--publish`; the seed command does not call SQS or S3. Editing a seeded
published node later uses the normal lifecycle publishing behavior.

## Phase 16: S3 claim-check delivery

The claim-check processor JSON-serializes the complete event using
`JSON_UNESCAPED_SLASHES` and `JSON_UNESCAPED_UNICODE`, then measures its exact
UTF-8 byte length. Events of 204800 bytes (200 KiB) or smaller are passed
through unchanged. Claim-check is used only when the complete serialized event
is larger than 204800 bytes; the threshold is fixed and is not configurable.

An event larger than the threshold is uploaded as the original JSON to S3
before the SQS transport is called. SQS receives the event metadata plus a
`claimCheck` pointer; the large `content` field is not included in that
message. The pointer includes bucket, object key, S3 version ID and/or ETag
when returned, content type, original byte size, SHA-256 checksum, and schema
version. The checksum is calculated over the exact uploaded UTF-8 bytes.

Object keys are deterministic:

```text
{environment}/{source}/{numeric-entity-id}/{revision}/{sha256}.json
```

For example:

```text
staging/drupal/42/12/<payload-sha256>.json
```

The SHA-256 suffix prevents different payloads for a reused entity revision
from overwriting each other. Keep the configured bucket private with S3 Block
Public Access and an appropriate bucket policy. The module does not set a
public ACL or log message bodies, credentials, or AWS secrets. S3 upload
failures stop processing before SQS publication; if the later SQS send fails,
the already-uploaded object may need lifecycle cleanup.

### Claim-check settings

Configuration values live in `brandhub_sync.settings`. For each listed
environment variable, a non-empty environment value takes precedence over its
Drupal configuration value.

| Purpose | Drupal setting | Environment variable | Default |
| --- | --- | --- | --- |
| Enable oversized-message handling | `claim_check_enabled` | `BRANDHUB_SYNC_CLAIM_CHECK_ENABLED` | `false` |
| Environment path segment | `environment` | `BRANDHUB_SYNC_ENVIRONMENT` | `local` |
| S3 bucket | `s3_bucket` | `BRANDHUB_SYNC_S3_BUCKET` | unset |
| S3 region | `s3_region` | `BRANDHUB_SYNC_S3_REGION` | AWS region variables below |
| S3-compatible endpoint | `s3_endpoint` | `BRANDHUB_SYNC_S3_ENDPOINT` | AWS default endpoint |
| SQS region | `aws_region` | `AWS_REGION`, then `AWS_DEFAULT_REGION` | unset |
| AWS shared credentials profile | `aws_profile` | none | SDK default profile |
| SQS queue URL | `sqs_queue_url` | `SQS_QUEUE_URL` | unset |

Set the claim-check enable flag only after a private bucket, bucket region, and
AWS credentials provider are configured. Credentials are not Drupal settings:
the AWS SDK uses its standard secure credential provider chain, such as an IAM
role or local AWS profile. Never commit access keys or production endpoints.

For LocalStack, set `BRANDHUB_SYNC_S3_ENDPOINT` to the local S3 endpoint
(commonly `http://localhost:4566`), configure a LocalStack bucket and region,
and use test-only credentials supported by that LocalStack instance. The S3
client enables path-style addressing when a custom endpoint is supplied.
Automated tests use `FakeClaimCheckStorage` and `FakePublisher` instead; they
never instantiate an AWS client or make network requests.

The claim-check feature is disabled by default. If an event exceeds the
threshold while it is disabled, publishing fails explicitly rather than
sending an oversized message or silently dropping its content.

## AWS configuration

The optional SQS publisher reads its region from, in order:

1. `brandhub_sync.settings:aws_region`
2. `AWS_REGION`
3. `AWS_DEFAULT_REGION`

It reads the queue URL from `brandhub_sync.settings:sqs_queue_url`, falling back
to `SQS_QUEUE_URL`. Set values in Drupal configuration or in the environment.
The AWS SDK uses its standard credential provider chain; credentials, queue
URLs, and other secrets are not hard-coded in this module. The AWS SDK is
already present in this project's `composer.json` and `composer.lock`, so no
additional Composer command is required for S3 support.

AWS configuration is currently empty in Drupal (`aws_region` and
`sqs_queue_url` are blank). Set the queue URL and region only in the
environment-specific Drupal configuration or environment. Provide AWS
credentials through the AWS SDK's supported secure credential chain (such as
an IAM role or local credential profile). Never put credentials or production
queue URLs in this README, source code, exported configuration, or Git.

The default fake transport and fake claim-check store make no network
requests. For a live site smoke check, the publisher can be confirmed without
changing the transport alias or sending to AWS:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php php:eval "`$publisher = \Drupal::service('brandhub_sync.publisher.transport'); `$publisher->publish(['event' => 'local.dry-run']); echo get_class(`$publisher) . ': ' . count(`$publisher->getMessages()) . ' in-memory message(s)' . PHP_EOL;"
```

This live-site check is for small messages only because claim-check is disabled
by default. Automated claim-check tests explicitly use the fake transport and
fake storage and cover large bodies without S3/SQS calls.

## Unified mapping

| Bundle | Summary | Body | Tags | Product term | Topic | Hero image |
| --- | --- | --- | --- | --- | --- | --- |
| `product` | `field_summary` | `field_body` | `field_tag` | `field_product` | Not mapped | `field_hero_image` |
| `article` | `field_a_summary` | `body` | `field_tags` | `field_a_product` | `field_a_topic` (not in unified schema) | `field_a_hero_image` |
| `faq` | Plain-text fallback | `field_answer` | `field_faq_tags` | `field_faq_product` | `field_faq_topic` (not in unified schema) | Missing (`null`) |

`uid` maps to the Drupal account display name, `created` supplies the published
timestamp, `changed` supplies the update timestamp, and the entity revision ID
supplies `version`. The FAQ bundle has no summary field. Topic fields are not
part of the unified schema. The site vocabularies are `product`, `tags`, and
`topic`. See the mapping document for all field behavior, defaults, and
unmapped values.

## Tests and coding standards

The Composer development dependencies provide PHPUnit 10, Drupal Coder, and
PHPStan with Drupal support. Run checks from the repository root:

```powershell
composer test
composer phpcs
composer phpstan
composer quality
```

On this Windows/XAMPP machine, use PHP 8.3 explicitly if the global Composer
launcher selects another PHP installation:

```powershell
& 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar quality
```

`composer test` runs both unit and kernel tests. The kernel test uses isolated
in-memory SQLite when `SIMPLETEST_DB` is not set; it does not connect to or
modify the configured Drupal database. Claim-check and publishing tests use
fake storage and publishers and never contact SQS or S3. PHPCS runs both
Drupal and DrupalPractice standards, and PHPStan analyzes `src/` at level 5.

The active database currently has listing Views `product` (`/product`),
`articles_by_topic` (`/articles`), and `faq` (`/faq`). Anonymous HTTP smoke
checks returned 200 for all three routes. Those View configurations are not
present in `config/sync`, so clean CI installations cannot reproduce or
functionally test the live Views yet. `drush config:status` also reports
numerous other active-only configuration items; do not run a full configuration
import as part of testing. Export and review the site's actual Views and their
field dependencies before adding reproducible functional listing tests.

## Phase 20: private S3 media storage

The project uses the Composer-managed S3 File System module (`drupal/s3fs`
3.12.0), which supports this site's Drupal 11.0.0 version. The AWS SDK is
already installed. The S3FS configuration uses only keys present in that
release's `s3fs.settings` schema. Drupal's `public` stream remains the default;
when explicitly enabled, S3FS takes over `public://` and stores objects under
its `s3fs-public/` prefix. Image-style derivatives use S3FS's image-style
route and public stream, so derivatives can also be stored in S3.

The S3 bucket must remain private. S3FS uploads objects without public ACLs,
and CloudFront must use Origin Access Control (OAC) for read access. Configure
the CloudFront origin at the bucket root: generated object keys include the
`s3fs-public/` prefix. Do not add a public-read bucket policy. Drupal code
cannot configure or verify AWS bucket policy, OAC, encryption, or CDN behavior.

S3 is opt-in. The committed
[`settings.s3fs.php`](../../sites/default/settings.s3fs.php) contains no secrets
and reads environment variables. The local ignored `settings.php` includes
that file. On another environment, add this include after the regular Drupal
settings have initialized:

```php
$s3fs_settings_file = $app_root . '/' . $site_path . '/settings.s3fs.php';
if (is_file($s3fs_settings_file)) {
  require $s3fs_settings_file;
}
```

With no enable flag and no S3 variables, Drupal continues using its existing
local `public://` directory. Setting
`BRANDHUB_MEDIA_S3_ENABLED=true` does not activate S3 unless the bucket, region,
and public URL domain are also present. The setting file keeps takeover off
when those values are incomplete.

| Purpose | Environment variable | Required |
| --- | --- | --- |
| Enable public-file takeover | `BRANDHUB_MEDIA_S3_ENABLED` | Explicit `true` to enable |
| Private S3 media bucket | `BRANDHUB_MEDIA_S3_BUCKET` | Yes when enabled |
| Bucket region | `BRANDHUB_MEDIA_S3_REGION` | Yes; falls back to `AWS_REGION` or `AWS_DEFAULT_REGION` |
| CloudFront hostname (no scheme/path) | `BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN` | Yes when enabled |
| Runtime environment for endpoint overrides | `BRANDHUB_MEDIA_ENVIRONMENT` | Must be `local` or `test` when endpoint override is used |
| Local/S3-compatible API endpoint | `BRANDHUB_MEDIA_S3_ENDPOINT` | Optional; leave unset in AWS environments |
| AWS credentials | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, or an approved SDK credential provider | Never store in Drupal config or Git |

`BRANDHUB_MEDIA_S3_ENDPOINT` is honored only when
`BRANDHUB_MEDIA_ENVIRONMENT` is explicitly `local` or `test`; it maps to the S3FS-supported
`use_customhost`, `hostname`, and `use_path_style_endpoint` settings. It is
ignored and disables S3 takeover in other environments. For LocalStack, set it
only in the local process environment and set
`BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN` to the local HTTP gateway/proxy hostname
used by the browser. Do not set these local values in dev/prod. The AWS SDK's
normal credential chain is used; this project does not set access keys or
session tokens in S3FS settings.

The environment-safe validation command checks required value presence and
formats without making AWS requests or printing configured values:

```powershell
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:media-config
```

When S3 is disabled it reports the local-file fallback. When enabled with
missing/invalid values it exits non-zero. A successful result does not verify
AWS credentials, permissions, the bucket, CloudFront, or LocalStack.

### Enable and import the scoped configuration

Install the contributed module through Composer (already done for this
checkout), enable it, then import the S3FS configuration:

```powershell
& 'C:\xampp83\php\php.exe' -d extension=zip C:\ProgramData\ComposerSetup\bin\composer.phar require 'drupal/s3fs:^3.12'
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php en s3fs -y
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php cim --partial -y
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php cr
& 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:media-config
```

The sync directory contains the scoped `s3fs.settings.yml`; `--partial` avoids
deleting this site's unrelated active-only configuration. Do not use a normal
full `drush cim` on this checkout: its sync directory is not a full export of
the existing site. Normal full config export is likewise not appropriate until
the site's existing configuration drift has been reviewed.

The exported S3FS config leaves bucket, region, and domain empty. Environment
overrides provide them at runtime. The PHP setting
`s3fs.upload_as_private` is enabled to avoid public ACLs, and
`s3fs.use_s3_for_public` is enabled only when the explicit flag and required
values are valid. Twig compiled templates remain on local disk outside the
document root while S3 takeover is active.

### Approved non-production verification

Do not set the enable flag or use real AWS credentials until the AWS owner
approves the bucket, IAM role/policy, encryption, and CloudFront OAC. These
steps perform real writes/reads and are intentionally manual:

1. Set the non-production environment values and run `brandhub:media-config`.
2. Upload a test image through the Product form at `/node/add/product` using
   the existing `field_hero_image` field. Save a draft test node.
3. Confirm the file entity URI remains `public://...`, then set the
   environment-specific object key (without the `s3fs-public/` prefix) and
   verify the original object:

   ```powershell
   $mediaKey = 'path/from/the/file-entity-uri'
   aws s3api head-object --bucket $env:BRANDHUB_MEDIA_S3_BUCKET --key "s3fs-public/$mediaKey" --region $env:BRANDHUB_MEDIA_S3_REGION
   ```

   Bucket and region are read from the approved environment; do not paste them
   into tracked files.
4. Request the image's `thumbnail` style URL (the site's `thumbnail` style
   already exists). For a source URI `public://path/to/test.jpg`, Drupal's
   S3FS image-style route is
   `/s3/files/styles/thumbnail/public/path/to/test.jpg`. The first request
   creates the derivative through Drupal; verify its corresponding
   `s3fs-public/styles/thumbnail/...` object exists afterward.
5. Inspect the CMS `web/sites/default/files` tree and the temporary upload
   directory. Confirm there is no permanent duplicate after a successful S3
   save; the exact temporary-file lifecycle is S3FS/PHP-version dependent.
6. Make an unauthenticated direct HTTPS request to the S3 object URL and
   confirm access is denied. Then request the matching CloudFront URL twice
   and inspect `X-Cache`, `Age`, and `Via` response headers:

   ```powershell
   $s3Url = "https://$($env:BRANDHUB_MEDIA_S3_BUCKET).s3.$($env:BRANDHUB_MEDIA_S3_REGION).amazonaws.com/s3fs-public/$mediaKey"
   curl.exe -sSI $s3Url | Select-String 'HTTP/|x-amz-request-id'

   $cdnUrl = "https://$($env:BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN)/s3fs-public/$mediaKey"
   1..2 | ForEach-Object {
     curl.exe -sSI $cdnUrl | Select-String 'HTTP/|X-Cache:|Age:|Via:'
   }
   ```

   Direct S3 access must be denied; the CloudFront URL must succeed. The
   expected cache hit on the second request depends on the AWS-owned cache
   policy and object cache headers, which must be supplied and confirmed by
   the AWS owner.

S3FS provides a **Validate Configuration** action at
`/admin/config/media/s3fs/actions`; it makes AWS requests and can write a
temporary test object. Use it only after explicit approval and with valid
non-production values. Do not run migration or copy commands against
production without a separate approved migration plan.

### Rollback

1. Stop new uploads and keep the S3 objects intact.
2. Before disabling S3 takeover, copy and verify every required S3 object back
   into the existing local public directory while file references still point
   to `public://`. Do not delete either copy during rollback.
3. Remove `BRANDHUB_MEDIA_S3_ENABLED` (or set it to `false`), then rebuild cache:

   ```powershell
   & 'C:\xampp83\php\php.exe' vendor\bin\drush.php cr
   & 'C:\xampp83\php\php.exe' vendor\bin\drush.php brandhub:media-config
   ```

4. Verify existing image URLs and derivatives against local files before
   considering the rollback complete. Uninstall S3FS only after confirming no
   Drupal file references depend on objects remaining in S3:

   ```powershell
   & 'C:\xampp83\php\php.exe' vendor\bin\drush.php pm:uninstall s3fs -y
   ```
