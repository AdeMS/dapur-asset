# adems-asset

PSR-15 / Mezzio handler package for serving files from `adems-dapur/data/asset`.

Requests in the form `/asset/{vendor_name}/{filepath}` map to the same relative
path under `data/asset`:

```text
/asset/bootstrap/dist/css/bootstrap.min.css
-> data/asset/bootstrap/dist/css/bootstrap.min.css
```

If the requested file does not exist (or resolves outside the asset directory),
the handler responds with HTTP 404.

The package contributes its route through `Asset\ConfigProvider`; it does not
edit the consuming application's route files during Composer install/update.
Register the provider once in the application's ConfigAggregator:

```php
$aggregator = new ConfigAggregator([
	Dapur\ConfigProvider::class,
	Asset\ConfigProvider::class,
]);
```

The Dapur router must merge the config's `routes` entries with its local route
files. The asset directory defaults to `{DAPUR_ROOT}/data/asset`, or
`{current working directory}/data/asset` when `DAPUR_ROOT` is unset.

In the consuming project, add the Git repository and package requirement:

```json
{
	"repositories": [
		{
			"type": "vcs",
			"url": "https://github.com/AdeMS/adems-asset"
		}
	],
	"require": {
		"adems/adems-asset": "^0.1"
	}
}
```

After tagging a release such as `v0.1.0`, install it with
`composer require adems/adems-asset:^0.1`.