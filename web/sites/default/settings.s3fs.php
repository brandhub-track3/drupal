<?php

/**
 * S3FS environment overrides.
 *
 * This file contains no credentials or deployment-specific values. Include it
 * from settings.php; S3 remains disabled unless all required variables exist.
 */
$media_s3_enabled_value = getenv('BRANDHUB_MEDIA_S3_ENABLED');
$media_s3_enabled = $media_s3_enabled_value !== FALSE
  && filter_var(
    $media_s3_enabled_value,
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE,
  ) === TRUE;

$media_s3_bucket = getenv('BRANDHUB_MEDIA_S3_BUCKET') ?: '';
$media_s3_region = getenv('BRANDHUB_MEDIA_S3_REGION')
  ?: getenv('AWS_REGION')
  ?: getenv('AWS_DEFAULT_REGION')
  ?: '';
$media_environment = strtolower(
  trim(getenv('BRANDHUB_MEDIA_ENVIRONMENT') ?: ''),
);
$media_cloudfront_domain = getenv('BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN') ?: '';
$media_s3_endpoint = getenv('BRANDHUB_MEDIA_S3_ENDPOINT') ?: '';
$media_cloudfront_domain_is_valid = preg_match(
  '/^[A-Za-z0-9.-]+(?::[0-9]+)?$/',
  $media_cloudfront_domain,
) === 1;
$media_s3_endpoint_parts = $media_s3_endpoint === ''
  ? FALSE
  : parse_url($media_s3_endpoint);
$media_s3_endpoint_is_valid = $media_s3_endpoint === ''
  || (
    in_array($media_environment, ['local', 'test'], TRUE)
    &&
    is_array($media_s3_endpoint_parts)
    && in_array($media_s3_endpoint_parts['scheme'] ?? '', ['http', 'https'], TRUE)
    && !empty($media_s3_endpoint_parts['host'])
    && !isset($media_s3_endpoint_parts['user'])
    && !isset($media_s3_endpoint_parts['pass'])
    && !isset($media_s3_endpoint_parts['query'])
    && !isset($media_s3_endpoint_parts['fragment'])
  );
$media_s3_ready = $media_s3_enabled
  && $media_s3_bucket !== ''
  && $media_s3_region !== ''
  && $media_cloudfront_domain_is_valid
  && $media_s3_endpoint_is_valid;

$settings['s3fs.upload_as_private'] = TRUE;
$settings['s3fs.use_s3_for_public'] = $media_s3_ready;

if ($media_s3_bucket !== '') {
  $config['s3fs.settings']['bucket'] = $media_s3_bucket;
}
if ($media_s3_region !== '') {
  $config['s3fs.settings']['region'] = $media_s3_region;
}
if ($media_cloudfront_domain_is_valid) {
  $config['s3fs.settings']['domain'] = $media_cloudfront_domain;
}

$config['s3fs.settings']['use_cname'] = $media_s3_ready;
$config['s3fs.settings']['use_https'] = !str_starts_with(
  $media_s3_endpoint,
  'http://',
);
$config['s3fs.settings']['use_customhost'] = $media_s3_endpoint !== '';
$config['s3fs.settings']['hostname'] = $media_s3_endpoint;
$config['s3fs.settings']['use_path_style_endpoint'] =
  $media_s3_endpoint !== '';
$config['s3fs.settings']['domain_root'] = 'none';

if ($media_s3_ready) {
  $settings['php_storage']['twig']['directory'] =
    dirname($app_root) . '/storage/php/twig';
}
