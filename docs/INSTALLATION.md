# Installation

## Requirements

- PHP `>=8.2 <8.6`
- Symfony components compatible with `^7.0 || ^8.0`
- Officially covered Symfony minors include `7.0`, `7.4`, `8.0`, and `8.1`

## Composer

```bash
composer require nowo-tech/beacon-bundle
```

With Symfony Flex, the recipe:

- registers `Nowo\BeaconBundle\NowoBeaconBundle`
- creates `config/packages/nowo_beacon.yaml`
- adds `BEACON_DSN=` to your env file with an empty default

## Manual registration

If you are not using Flex, enable the bundle manually:

```php
// config/bundles.php
return [
    // ...
    Nowo\BeaconBundle\NowoBeaconBundle::class => ['all' => true],
];
```

Then copy the recipe config from:

```text
.symfony/recipe/nowo-tech/beacon-bundle/1.0/config/packages/nowo_beacon.yaml
```

## Minimal configuration

```yaml
nowo_beacon:
    enabled: true
    dsn: '%env(default::BEACON_DSN)%'
    environment: '%kernel.environment%'
    release: null
    server_name: null
    verify_peer: true
    timeout: 5.0
    register_error_listener: true
    ignore_exceptions: []
```

## Configure the DSN

Point `BEACON_DSN` at your Symfony Beacon server:

```env
BEACON_DSN=https://PUBLIC_KEY@beacon.example.com:9444/1
```

Leave it empty when you want Beacon reporting off.

## Development with self-signed HTTPS

For local Beacon servers using self-signed certificates, disable peer verification only in development:

```yaml
when@dev:
    nowo_beacon:
        verify_peer: false
```

See [CONFIGURATION.md](CONFIGURATION.md) for the full key reference and [USAGE.md](USAGE.md) for capture examples and E2E scenarios.
