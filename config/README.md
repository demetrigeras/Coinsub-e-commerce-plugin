# Whitelabel config

Set **only** `environment_id` (and optionally `slug`). The plugin uses it to fetch the full config from your API (lookup by `environment_id` in your env_config table).

---

## Where to manually put the environment_id

You can set it in **either** place (or both; build script can copy from JSON into the PHP file):

### 1. config/whitelabel-config.json (for build script / zip name)

Edit this file and set `environment_id` and optionally `slug`:

```json
{
  "environment_id": "paymentservers.com",
  "slug": "payment-servers"
}
```

- **environment_id** – Must match a row in your env_config table (e.g. `paymentservers.com`, `bxnk.com`).
- **slug** – Used by the build script for the zip name, e.g. `payment-servers-pay-1.0.0.zip`.

### 2. coinsub-whitelabel-config.php (plugin root – used at runtime)

Open **`coinsub-whitelabel-config.php`** in the **plugin root** (same folder as `stablecoin-pay.php`). Put the environment_id in the return array:

```php
return array(
    'environment_id' => 'paymentservers.com',   // ← PUT IT HERE
    'slug'          => 'payment-servers',
);
```

- For **Stablecoin Pay** (no whitelabel): use `'environment_id' => null`.
- For **Payment Servers**: use `'environment_id' => 'paymentservers.com'`.
- For **BXNK**: use `'environment_id' => 'bxnk.com'`.

The plugin loads this file and uses `environment_id` to fetch that row’s config from your API. No need to paste full config_data here; that stays in your DB.
