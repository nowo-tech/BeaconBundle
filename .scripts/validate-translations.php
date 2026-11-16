#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validates the bundle translation catalogues (REQ-MAKE-004 / REQ-I18N-002 / REQ-I18N-003).
 *
 * - every file under src/Resources/translations/ is a NowoBeaconBundle.<locale>.yaml catalogue (no other domain);
 * - every shipped catalogue parses as a YAML map of scalar messages (nested maps are flattened to dotted keys);
 * - every shipped locale has exactly the keys of the reference locale (en), and no message is empty;
 * - the reference locale must exist.
 *
 * Required locales that are not shipped yet (REQ-I18N-002: en, es, fr, de, it, pt, nl) are reported as a warning
 * only, so the target stays usable until the remaining catalogues ship; set TRANSLATIONS_STRICT=1 to fail on them.
 *
 * Exit code 0 when everything is valid, 1 otherwise (errors on STDERR).
 */
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$domain          = 'NowoBeaconBundle';
$requiredLocales = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl'];
$referenceLocale = 'en';
$dir             = dirname(__DIR__) . '/src/Resources/translations';

/**
 * @param array<mixed> $tree
 *
 * @return array<string, mixed>
 */
$flatten = static function (array $tree, string $prefix = '') use (&$flatten): array {
    $flat = [];
    foreach ($tree as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $flat += $flatten($value, $path);
            continue;
        }
        $flat[$path] = $value;
    }

    return $flat;
};

$errors     = [];
$catalogues = [];

foreach (glob($dir . '/*') ?: [] as $file) {
    if (preg_match('/^' . preg_quote($domain, '/') . '\.[a-z]{2}(?:_[A-Z]{2})?\.yaml$/', basename($file)) !== 1) {
        $errors[] = sprintf('Unexpected catalogue %s: only %s.<locale>.yaml files are allowed (REQ-I18N-003).', basename($file), $domain);
    }
}

$strict   = getenv('TRANSLATIONS_STRICT') === '1';
$warnings = [];

foreach ($requiredLocales as $locale) {
    $file = sprintf('%s/%s.%s.yaml', $dir, $domain, $locale);
    if (!is_file($file)) {
        if ($strict || $locale === $referenceLocale) {
            $errors[] = sprintf('Missing catalogue: %s', $file);
        } else {
            $warnings[] = sprintf('Missing required locale %s (REQ-I18N-002): %s', $locale, basename($file));
        }
        continue;
    }

    try {
        $parsed = Yaml::parseFile($file);
    } catch (ParseException $e) {
        $errors[] = sprintf('Invalid YAML in %s: %s', basename($file), $e->getMessage());
        continue;
    }

    if (!is_array($parsed)) {
        $errors[] = sprintf('Invalid catalogue (not a map): %s', basename($file));
        continue;
    }

    $messages = $flatten($parsed);
    foreach ($messages as $key => $message) {
        if (!is_string($message) || trim($message) === '') {
            $errors[] = sprintf('Empty or non-string message "%s" in %s', $key, basename($file));
        }
    }
    $catalogues[$locale] = array_keys($messages);
}

$reference = $catalogues[$referenceLocale] ?? [];
foreach ($catalogues as $locale => $keys) {
    if ($locale === $referenceLocale) {
        continue;
    }
    $missing = array_diff($reference, $keys);
    $extra   = array_diff($keys, $reference);
    if ($missing !== []) {
        $errors[] = sprintf('Key parity failed for %s — missing: %s', $locale, implode(', ', $missing));
    }
    if ($extra !== []) {
        $errors[] = sprintf('Key parity failed for %s — extra: %s', $locale, implode(', ', $extra));
    }
}

if ($warnings !== []) {
    fwrite(STDERR, 'Warning: ' . implode("\nWarning: ", $warnings) . "\n");
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    // @igor-ignore - CLI script, not a long-running worker path
    exit(1);
}

printf("OK — %d locales, %d keys (domain %s)\n", count($catalogues), count($reference), $domain);
