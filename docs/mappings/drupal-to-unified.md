# Drupal to unified-content mapping

This mapping reflects the Drupal bundles and fields present in this project:
Drupal 11 node bundles `product`, `article`, and `faq`; vocabularies `product`,
`tags`, and `topic`; and the `brandhub_content_workflow` Content Moderation
workflow.

The mapping is descriptive only. It does not add fields, change content types,
edit configuration, or import/export Drupal configuration.

## Common fields

| Unified property | Drupal source | Mapping and missing-value fallback |
| --- | --- | --- |
| `id` | Node `nid` | Prefix the positive node ID with `dr:` (for example, `dr:42`). |
| `url` | Node canonical route | Generate the absolute canonical view URL for the node. |
| `source` | Integration metadata | Always `drupal`. |
| `type` | Node bundle ID (`type`) | Use the machine name: `product`, `article`, or `faq`. |
| `title` | Core node `title` | Copy the title; use an empty string only if the source value is absent. |
| `summary` | Bundle-specific summary field, when present | Trim and use its value. If absent or empty, use trimmed `plainText`; this also applies to the FAQ bundle, which has no summary field. |
| `bodyHtml` | Bundle-specific body field, when present | Use Drupal-rendered field markup, not raw, unfiltered stored text. Use `null` when the field is absent or empty. FAQ's `field_answer` is its answer/body source. |
| `plainText` | Same body source as `bodyHtml` | Extract text from the rendered body, trim it, and use `""` when the field is absent or empty. |
| `tags` | Bundle-specific entity-reference field to vocabulary `tags` | Map each referenced term to `{ "id": tid, "name": name }`. Use `[]` when there are no references or the field is absent. |
| `product` | Bundle-specific entity-reference field to vocabulary `product` | Map the first referenced term to `{ "id": tid, "name": name }`; use `null` when unset or absent. |
| `author` | Core node `uid` (“Authored by”) | Map the referenced user to `{ "id": uid, "name": name }`; use `null` when no resolvable author exists. |
| `heroImage` | Bundle-specific image field, when present | Resolve the referenced image file to an absolute URL. Use `null` when the field is absent, unset, or the file cannot be resolved. |
| `publishedAt` | Core node `created` | For a currently published node, serialize the creation timestamp as ISO 8601/RFC 3339. Use `null` when unpublished or unavailable; `created` is not a publication-transition timestamp. |
| `updatedAt` | Core node `changed` | Serialize the modification timestamp as ISO 8601/RFC 3339. If unavailable, do not emit a record until a valid timestamp is available. |
| `version` | Core node revision ID (`vid`) | Use the current revision ID as an integer; use `null` if unavailable. |
| `hash` | Integration metadata | Lowercase SHA-256 digest of deterministic JSON: recursively sort object keys, preserve list order, and exclude the `hash` property itself. The Drupal module calculates this for every event payload. |
| `status` | Core node `status`, plus deletion/tombstone context | Use `published` only for an existing published node. Use `removed` only for an explicit deletion/tombstone event; do not treat an unpublished/draft node as removed. Omit unpublished nodes from a published-content feed unless the consumer has a separate draft policy. |
| `schemaVersion` | Integration metadata | Always `"1.0"` for this initial schema version. |

`tags` defaults to an empty array. Missing summary falls back to trimmed
`plainText`. Missing/empty body content uses `bodyHtml: null` and
`plainText: ""`. Missing hero images and product references use `null`.

## Bundle-specific fields

| Drupal bundle | Unified property | Drupal machine name | Drupal field/vocabulary |
| --- | --- | --- | --- |
| `product` | `summary` | `field_summary` | String summary |
| `product` | `bodyHtml`, `plainText` | `field_body` | Text-long description |
| `product` | `tags` | `field_tag` | Entity reference to `tags` |
| `product` | `product` | `field_product` | Entity reference to `product` |
| `product` | `heroImage` | `field_hero_image` | Image |
| `article` | `summary` | `field_a_summary` | String summary |
| `article` | `bodyHtml`, `plainText` | `body` | Core text-with-summary body |
| `article` | `tags` | `field_tags` | Entity reference to `tags` |
| `article` | `product` | `field_a_product` | Entity reference to `product` |
| `article` | Topic (not in unified schema) | `field_a_topic` | Entity reference to `topic`; no unified `topic` property is defined |
| `article` | `heroImage` | `field_a_hero_image` | Image |
| `faq` | `summary` | Missing | Fall back to trimmed `plainText` |
| `faq` | `bodyHtml`, `plainText` | `field_answer` | Text-long answer |
| `faq` | `tags` | `field_faq_tags` | Entity reference to `tags` |
| `faq` | `product` | `field_faq_product` | Entity reference to `product` |
| `faq` | Topic (not in unified schema) | `field_faq_topic` | Entity reference to `topic`; no unified `topic` property is defined |
| `faq` | `heroImage` | Missing | Use `null` |

The Product bundle has no topic field. The FAQ bundle has neither a dedicated
summary nor a hero-image field. No new Drupal fields are introduced by this
schema.

## Moderation and deletion

The existing workflow machine name is `brandhub_content_workflow`; its states
are `draft`, `in_review`, and `published`. The unified schema deliberately
supports only `published` and `removed`. Draft and in-review nodes are not
published records. A removal record requires explicit deletion/tombstone
information from the integration; the current moderation state alone does not
prove that a node was deleted.

## Example files

- [`../samples/drupal-product.json`](../samples/drupal-product.json)
- [`../samples/drupal-article.json`](../samples/drupal-article.json)
- [`../samples/drupal-faq.json`](../samples/drupal-faq.json)
- [Unified JSON Schema](../schemas/unified-content.schema.json)
