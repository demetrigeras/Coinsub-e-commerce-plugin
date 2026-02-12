# Full White-Label Architecture: Form → env_config → Plugin

This document describes how to make the plugin **fully white-label** by:

1. **White-label partners** complete a form; data is saved in your **env_config** table (backend).
2. The **plugin** identifies itself via a **hardcoded config variable** (e.g. which row in env_config).
3. The plugin **pulls config from your API** (which reads env_config) and uses it for **plugin name**, **checkout**, and **every other branded string**.

You do **not** need a separate repo per white-label. One repo + a **config variable per build** (or per deployment) is enough.

---

## 1. High-Level Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  YOUR BACKEND (CoinSub / Payment Servers, etc.)                              │
│                                                                              │
│  ┌──────────────┐     ┌─────────────────────┐     ┌─────────────────────┐   │
│  │ Partner form │ ──► │ env_config table    │ ──► │ API: get config      │   │
│  │ (company,    │     │ (environment_id,    │     │ by environment_id    │   │
│  │  logo, etc.) │     │  company, logos,    │     │ or by merchant_id    │   │
│  └──────────────┘     │  plugin_name, …)    │     └──────────┬──────────┘   │
│                       └─────────────────────┘                │               │
└─────────────────────────────────────────────────────────────┼───────────────┘
                                                              │
                                                              ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  WOOCOMMERCE SITE (merchant installs plugin)                                  │
│                                                                              │
│  Plugin has a HARDCODED variable that points to ONE white-label:            │
│  e.g.  COINSUB_WHITELABEL_ENV_ID = 'paymentservers'                          │
│        (in wp-config.php, or a small config file, or build-time constant)   │
│                                                                              │
│  On load / settings save:  GET /v1/env-config?environment_id=paymentservers   │
│  Store result in wp_options → coinsub_whitelabel_branding                    │
│                                                                              │
│  Everywhere the plugin shows a name (checkout, admin, emails):               │
│  use branding from that option → "Payment Servers", their logo, etc.         │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Backend: env_config Table and Form

### 2.1 Where the data lives

- **Table**: `env_config` (or your existing `environment_configs` / environment config store).
- **Per-row identifier**: `environment_id` (e.g. `paymentservers.com`, `vantack.com`, `bxnk.com`). This is the key the plugin will send to get its config.

### 2.2 Form fields to collect (and save to env_config)

| Field | Purpose | Example |
|-------|---------|--------|
| `environment_id` | Unique key for this white-label (used in API URLs and config lookup) | `paymentservers.com` |
| `company` | Display name everywhere (checkout, admin, emails) | `Payment Servers` |
| `plugin_display_name` | Name shown in WordPress Plugins list (optional; see 4.1) | `Payment Servers Pay` |
| `checkout_title` | Text on checkout (e.g. "Pay with X") | `Pay with Payment Servers` |
| `powered_by` | Footer / “Powered by” text | `Powered by Payment Servers` |
| `logo` (light/dark, default/square) | URLs for logos | As you already have |
| `favicon` | Favicon URL | As you already have |
| `buyurl`, `documentation_url`, `privacy_policy_url`, `terms_of_service_url`, `copyright` | Links and legal | As needed |

You can keep your current structure (e.g. `config_data` JSON with `app.company`, `app.logo`, etc.) and add a few fields for plugin-specific strings (`plugin_display_name`, `checkout_title`, `powered_by`) if they are not already there.

### 2.3 API: Pull config by env_id

The plugin needs a way to get **one** environment’s config by identifier.

- **Option A (recommended)**: New endpoint, e.g.  
  `GET /v1/environment-variables/config?environment_id=paymentservers.com`  
  Returns the single env_config row for that `environment_id` (no auth required for this lookup if the env_id is considered non-secret; or require a shared secret per env_id if you prefer).

- **Option B**: Keep existing “list all environment configs” endpoint; plugin fetches all and picks the one where `environment_id === COINSUB_WHITELABEL_ENV_ID`. Works with current setup but sends more data than needed.

---

## 3. Plugin: Config Variable and Fetch by env_id

### 3.1 Single repo, one variable per white-label

- **One codebase** for all white-labels.
- **One variable** identifies which white-label this install is for. Options:

| Method | Where | Example |
|--------|--------|--------|
| **Constant** | `wp-config.php`: `define('COINSUB_WHITELABEL_ENV_ID', 'paymentservers.com');` | Easiest for you to ship one plugin and have the partner add one line to wp-config. |
| **Config file** | e.g. `coinsub-whitelabel-config.php` in plugin root: `return ['env_id' => 'paymentservers.com'];` | You ship a different small file per partner (or they edit one file). |
| **Build-time constant** | In your build script (e.g. `create-plugin-package.sh`), sed/replace a placeholder in the main plugin file. | One zip per partner; no manual edit on their side. |

You do **not** need a new repo per white-label. Same repo; only the value of that variable (or the included config file) changes per build/deployment.

### 3.2 One codebase, two zips: branch on environment_id

You have **one codebase** and produce **two zip files**:

| Zip | environment_id in build | Runtime behavior |
|-----|------------------------|------------------|
| **Stablecoin Pay** | `null` / not set | Use existing logic: branding from merchant credentials (merchant_id → API → match to env_config). No whitelabel; show “Stablecoin Pay” etc. |
| **Whitelabel** | Set (e.g. `paymentservers.com`) | Use that env_id: run your **query for whitelabel** (API/DB by environment_id), store result in `coinsub_whitelabel_branding`, and use it **everywhere around the plugin** (checkout title, method title, payment method title, logos, “Powered by”, etc.). |

**Logic in code:**

```
if (environment_id === null || not set)
  → Stablecoin Pay: fetch branding by merchant_id / parent_merchant_id (current behavior)
else
  → Whitelabel: fetch config by that environment_id (your whitelabel query), then whitelabel the plugin for that env in all relevant places
```

So: **if environment_id is null, do Stablecoin Pay; else run that environment_id and whitelabel the plugin** in all the places that show a name or logo. One codebase, one branch, two zip outputs depending on whether the build has env_id set or not.

### 3.3 When to fetch config

- **If `COINSUB_WHITELABEL_ENV_ID` (or equivalent) is set:**  
  On plugin load or when gateway settings are saved, call the API with that `environment_id` (your “query for whitelabel”), store the result in `get_option('coinsub_whitelabel_branding')`, and use it everywhere. No merchant_id matching for this path.
- **If the constant is not set (null):**  
  Stablecoin Pay: fetch by merchant credentials and match to env config (merchant_id → parent_merchant_id → environment_configs).

So:

- **Whitelabel-by-env-id**: config comes from env_config row identified by the hardcoded variable.
- **Whitelabel-by-credentials**: config comes from existing logic (merchant_id + API → match to env_config).

### 3.4 What to store in WordPress

Same as now: one option holding the “current” branding for this site.

- **Key**: `coinsub_whitelabel_branding` (in `wp_options`).
- **Content**: Same structure you already use: `company`, `company_slug`, `environment_id`, `logo`, `favicon`, `powered_by`, `checkout_title`, `plugin_display_name`, etc., so the rest of the plugin can stay agnostic of whether the config came from env_id or from merchant matching.

---

## 4. What We Whitelabel (Master List)

We whitelabel the following. Everywhere that would say “Stablecoin Pay” or “CoinSub” uses the partner’s name and assets instead.

| What | Where | Notes |
|------|--------|--------|
| **Download of the plugin** | Zip filename (and optional folder name) | e.g. `payment-servers-pay-1.0.0.zip` instead of `stablecoin-pay-1.0.0.zip`. |
| **Settings** | WooCommerce → Settings → Payments (and any plugin settings screens) | Titles, labels, descriptions, setup instructions — use partner name / `plugin_display_name` or `company`. |
| **Payments tab** | WooCommerce → Payments | Payment method name (e.g. “Pay with Payment Servers” instead of “Stablecoin Pay”). |
| **Subscriptions tab** | WooCommerce → Subscriptions | Same payment method name and any “Stablecoin Pay” / “CoinSub” labels. |
| **Checkout: logo** | Checkout page, payment option | Partner logo (from env_config), not CoinSub/Stablecoin Pay logo. |
| **Checkout: “Pay with [blank]”** | Checkout payment option title | “Pay with {company}” (e.g. “Pay with Payment Servers”). |
| **Anywhere else that says Stablecoin Pay or CoinSub** | Order details, emails, admin notices, review/powered-by text, iframe title, etc. | All such strings come from branding (`company`, `plugin_display_name`, `checkout_title`, `powered_by`, logos). |

So in short: we whitelabel the **download**, the **settings**, the **names in Payments and Subscriptions**, the **checkout logo and “Pay with [blank]”**, and **every other occurrence** of “Stablecoin Pay” or “CoinSub.”

---

## 4a. Where the Plugin Shows the White-Label Name (Implementation Reference)

All of these should use the stored branding (from `coinsub_whitelabel_branding`) when available.

| Location | Current value / source | Use from branding |
|----------|------------------------|-------------------|
| **Plugins list (Plugin Name)** | Main file header `Plugin Name: Stablecoin Pay` | Build-time replacement with `plugin_display_name`, or leave as “Stablecoin Pay” (see 4.1). |
| **WooCommerce → Payments (method title)** | `method_title` = “Stablecoin Pay” | `branding['plugin_display_name']` or `branding['company']`. |
| **WooCommerce → Subscriptions** | Same method title / labels | Same as Payments. |
| **Checkout: logo** | Gateway icon / button logo | `branding['logo']` (and favicon). |
| **Checkout: “Pay with [blank]”** | `checkout_title` | `branding['checkout_title']` or “Pay with {company}”. |
| **Order payment method title** | `set_payment_method_title('CoinSub')` / `'Pay with Coinsub'` | `branding['checkout_title']` or “Pay with {company}”. |
| **Checkout iframe page title** | “Complete Your Payment - {company}” | `company`. |
| **Shortcode / checkout page** | “Stablecoin Pay” fallback | `company` or `plugin_display_name`. |
| **Powered by** | “Powered by CoinSub” | `branding['powered_by']`. |
| **Review / branding template** | Logo + “Powered by” | Branding logos and `powered_by`. |
| **Settings screen** | Titles, descriptions, instructions | `plugin_display_name` or `company`. |
| **Admin notices** | “Stablecoin Pay requires WooCommerce…” | `plugin_display_name` or `company`. |
| **Emails** | Payment method title in order | Same as order payment method title (from branding). |

Implementing full white-label means ensuring **every** user-facing string that says “CoinSub” or “Stablecoin Pay” is replaced by a value from the branding option when the white-label config is active.

---

## 4.1 Plugin name in the Plugins list

WordPress reads the plugin name from the **main plugin file header** (e.g. `Plugin Name: Stablecoin Pay`). There is no WordPress filter to change that at runtime.

Options:

1. **Build-time replacement**  
   In your build script, replace a placeholder in the main file, e.g.  
   `Plugin Name: {{PLUGIN_DISPLAY_NAME}}` → `Plugin Name: Payment Servers Pay`  
   So one zip per white-label = one plugin name per zip. No new repo, just a build matrix (env_id + plugin_display_name per build).

2. **Single plugin name**  
   Keep “Stablecoin Pay” in the plugin list and white-label only checkout, payment method title, “Powered by”, and all other visible strings. Easiest; full white-label everywhere except the Plugins list.

3. **Separate plugin slug per white-label** (e.g. `payment-servers-pay`)  
   Still one repo; build produces different plugin slugs and different headers. More work (different folder names / slugs per build).

Recommendation: use **(1)** if you want the Plugins list to show the partner name; otherwise **(2)** is enough for “full” white-label in the rest of the experience.

---

## 5. Summary: Is a New Repo Needed?

**No.** You can do full white-label with:

- **One repo** (this plugin).
- **One env_config table** (and form) on your backend.
- **One API** that returns config by `environment_id` (or reuse “list configs” and filter in the plugin).
- **One variable per deployment** that ties this install to one row in env_config:
  - **Option A**: Partner (or you) adds one line in `wp-config.php`: `define('COINSUB_WHITELABEL_ENV_ID', 'paymentservers.com');`
  - **Option B**: You ship a different `coinsub-whitelabel-config.php` (or different build) with `env_id` set, so the plugin knows which row to pull.

The plugin then:

1. Reads the variable (constant or config file).
2. If set, fetches config from your API by `environment_id` and saves it to `coinsub_whitelabel_branding`.
3. Uses that branding for plugin name (if you use build-time replacement), method title, checkout title, payment method title, “Powered by”, and any other place that should show the white-label merchant’s name.

That gives you full white-label with a single repo and a single config variable pointing to the correct row in the env_config table.

---

## 6. Implementation Outline (Code)

### 6.1 Define the config variable

**Option A – Constant in wp-config.php (recommended for partners)**  
Partner adds to `wp-config.php`:

```php
define('COINSUB_WHITELABEL_ENV_ID', 'paymentservers.com');
```

In the plugin, read it once and reuse:

```php
// e.g. in stablecoin-pay.php or class-sp-whitelabel-branding.php
$env_id = defined('COINSUB_WHITELABEL_ENV_ID') ? COINSUB_WHITELABEL_ENV_ID : null;
```

**Option B – Config file in plugin**  
Create `coinsub-whitelabel-config.php` in the plugin root:

```php
<?php
return [
    'env_id' => 'paymentservers.com',
];
```

Plugin loads it:

```php
$config_file = COINSUB_PLUGIN_DIR . 'coinsub-whitelabel-config.php';
$env_id = (is_readable($config_file)) ? (include $config_file)['env_id'] ?? null : null;
```

**Option C – Build-time constant**  
In `create-plugin-package.sh` (or similar), when building for “paymentservers”, run:

```bash
sed -i '' 's/{{COINSUB_WHITELABEL_ENV_ID}}/paymentservers.com/g' stablecoin-pay.php
```

And in code use `defined('COINSUB_WHITELABEL_ENV_ID') ? COINSUB_WHITELABEL_ENV_ID : null` (with a default `{{COINSUB_WHITELABEL_ENV_ID}}` in the source that gets replaced at build time, or a literal constant that you define only in built zips).

### 6.2 Backend API (your side)

- Add or reuse an endpoint that returns **one** env config by `environment_id`, e.g.  
  `GET /v1/environment-variables/config?environment_id=paymentservers.com`  
  Response shape should match what the plugin already stores in `coinsub_whitelabel_branding` (e.g. `company`, `company_slug`, `environment_id`, `logo`, `favicon`, `powered_by`, `checkout_title`, `plugin_display_name`, etc.).

### 6.3 Plugin: fetch by env_id when variable is set

In **`includes/class-sp-whitelabel-branding.php`**:

1. In `get_branding($force_refresh)` (or in a new method used by the gateway):
   - If `COINSUB_WHITELABEL_ENV_ID` (or config file) is set:
     - Call your new API: e.g. `$this->api_client->get_config_by_environment_id($env_id)`.
     - Map the response into the same array structure you already use for `coinsub_whitelabel_branding`.
     - `update_option('coinsub_whitelabel_branding', $branding)` and return.
   - Else:
     - Keep existing flow (merchant_info + environment_configs + match by parent_merchant_id).
2. In **`includes/class-sp-api-client.php`** add something like:

```php
public function get_config_by_environment_id($environment_id) {
    $url = 'https://api.coinsub.io/v1/environment-variables/config?environment_id=' . urlencode($environment_id);
    $response = wp_remote_get($url, array('timeout' => 15));
    // Parse JSON, return single config (same shape as one entry from env_config).
}
```

(Use your real base URL and auth if the endpoint requires it.)

### 6.4 Use branding everywhere (checklist)

| File | What to change |
|------|----------------|
| **stablecoin-pay.php** | Plugin header: either keep “Stablecoin Pay” or use build-time `{{PLUGIN_DISPLAY_NAME}}`. WooCommerce missing notice: use branding `company` or `plugin_display_name` if available. |
| **stablecoin-pay.php** | `coinsub_ajax_process_payment`: replace `set_payment_method_title('CoinSub')` with title from branding (e.g. “Pay with {company}” or stored `checkout_title`). |
| **includes/class-sp-payment-gateway.php** | `method_title`: set from branding (e.g. `plugin_display_name` or `company`) when branding exists; else “Stablecoin Pay”. |
| **includes/class-sp-payment-gateway.php** | `checkout_title`: already from branding; ensure default is “Pay with {company}” or stored `checkout_title`. |
| **includes/class-sp-webhook-handler.php** | Replace `set_payment_method_title('Pay with Coinsub')` with branding-based title (e.g. “Pay with {company}”). Same for renewal orders. |
| **includes/class-sp-whitelabel-branding.php** | When building branding from API (env_id or merchant match), include `plugin_display_name`, `checkout_title`, `powered_by` from env_config if your API provides them. |
| **includes/templates/sp-review-page.php** | Already uses branding; ensure `powered_by` and logos come from config. |

After these changes, the only remaining “CoinSub” / “Stablecoin Pay” strings will be in the plugin header (if you don’t use build-time replacement) and possibly in comments or log messages, which you can leave or make conditional.

### 6.5 Flow summary

1. **Backend**: Form → save to env_config. API returns one config by `environment_id`.
2. **Plugin**: Constant or config file sets `COINSUB_WHITELABEL_ENV_ID` (or equivalent).
3. **Plugin**: On load or settings save, if env_id is set → fetch config by env_id → save to `coinsub_whitelabel_branding`.
4. **Plugin**: All user-facing strings (method title, checkout title, payment method title, “Powered by”, notices) read from `coinsub_whitelabel_branding`.
5. **Plugin name in Plugins list**: Optional build-time replacement of the main file header; otherwise keep “Stablecoin Pay”.

No new repo is required; only the single config variable (or build artifact) per white-label.

---

## 7. Simple Manual Workflow (Recommended for You)

You don’t need automatic “pay and get download.” You can keep it manual and easy:

- **One whitelabel repo** (not one repo per whitelabel). This repo is for the “whitelabel build” scenario only.
- **One config file** in that repo lists all whitelabel partners. Each entry is basically: **environment_id** (and optionally a **slug** for the zip filename).
- When a merchant pays for whitelabel, you **add one row** to that config (their `environment_id` and maybe `slug`), run the build, get the zip, and **send it to them manually** (email, shared link, etc.).

**Do you have to manually input the environment_id?**  
**Yes.** That’s the intended flow:

1. Merchant becomes a whitelabel partner (payment, contract, etc.).
2. You add them to the whitelabel config: e.g. `environment_id: paymentservers.com`, `slug: payment-servers`.
3. You run the build for that one partner only (e.g. `./build.sh payment-servers`). “batch build” one plugin at a time).
4. You get `payment-servers-pay-1.0.0.zip` That’s the only zip for this run.
5. You send that zip to the merchant manually.

The plugin zip is built with that **environment_id** baked in (via a config file or constant inside the zip). When the merchant installs it, the plugin calls your API with that env_id and gets their branding from env_config — so the “whole entire plugin” is whitelabeled for that environment. No need for them to configure anything except their WooCommerce + API credentials.

**What the config looks like (example):** In the whitelabel repo you might have a file like `config/whitelabel-partners.json` or `config/partners.php`:

```json
[
  { "environment_id": "paymentservers.com", "slug": "payment-servers" },
  { "environment_id": "vantack.com", "slug": "vantack" }
]
```

When you onboard a new whitelabel merchant, you add one line (their `environment_id` and a `slug` for the zip name), run the build, and send the zip. That’s it.

**One plugin at a time:** Build one zip per run (e.g. `./build.sh payment-servers`). Don’t build or download multiple plugins in one go — keep it to one whitelabel (or Stablecoin Pay) per build.

**Summary:** One whitelabel repo, one config where you add `environment_id` (and slug) per partner. When you need a zip for someone, run the build for that one partner, get the zip, send it manually.

---

## 8. Repo Strategy: Stablecoin Pay vs Whitelabel (Paid)

### 8.1 Should you keep them in separate repos?

**Yes, keeping them in separate repos is a good idea** when:

- **Stablecoin Pay** = public/default version: no env_id config, branding derived from merchant credentials only. One zip: `stablecoin-pay-1.0.0.zip`.
- **Whitelabel (paid)** = version that uses env_config + config variable per partner. Build produces one zip **per** white-label with a **friendly filename** (e.g. `payment-servers-pay-1.0.0.zip`). This repo holds the config layer and build logic.

Benefits of two repos:

| Aspect | Stablecoin Pay repo | Whitelabel repo |
|--------|---------------------|------------------|
| **Audience** | Public / anyone | Partners who paid for white-label |
| **Content** | Single product, no partner configs | Config files or build matrix per partner |
| **Releases** | One zip per version | One zip per partner per version (friendly names) |
| **Visibility** | Can be public | Usually private (configs, build details) |

You can have the whitelabel repo **include** the stablecoin-pay code (as a submodule, or copy, or monorepo package) so the “core” is one place and the whitelabel repo only adds config + build that produces partner-specific zips.

### 8.2 How does the partner know which zip to download?

They **don’t have to know**. Your system decides for them:

1. **Partner logs in** to your dashboard (e.g. Payment Servers dashboard).
2. They go to something like **“Download WooCommerce plugin”** or **“Integrations”**.
3. They get **one** download link. That link:
   - Either points to a **versioned, partner-specific path** that only serves their zip, or
   - Is a **generic download endpoint** that uses auth (or a token) to decide which zip to serve.

So “which zip” is determined by **who they are** (env_id / partner_id in your backend), not by the partner picking from a list.

**Ways to implement “which zip”:**

| Approach | How it works | Example |
|----------|----------------|--------|
| **A. Versioned URL per partner** | You store one build artifact per partner per version. Download URL includes partner slug and version. | `https://downloads.example.com/plugins/payment-servers-pay/1.0.0/payment-servers-pay-1.0.0.zip` |
| **B. Generic URL + auth** | One URL, e.g. `GET /api/download/plugin`. Backend checks session/token → env_id → serves the zip for that partner. Filename is set via `Content-Disposition`. | `Content-Disposition: attachment; filename="payment-servers-pay-1.0.0.zip"` |
| **C. Token in link** | You email/link: `https://downloads.example.com/plugin?token=xyz`. Token maps to env_id; you serve the right zip and friendly filename. | Same as B, but link is shareable once. |

Recommendation: **A** if you want clear, cacheable URLs and simple CDN/storage layout; **B** if you want a single “Download plugin” button that always gives the latest for that partner.

### 8.3 White-label-friendly filenames

The **zip file name** (and optionally the **plugin folder** inside the zip) should be white-label friendly so the partner sees e.g. `payment-servers-pay-1.0.0.zip`, not `stablecoin-pay-1.0.0.zip`.

**Conventions:**

- **Zip file**: `{partner-slug}-pay-{version}.zip`  
  Examples: `payment-servers-pay-1.0.0.zip`, `vantack-pay-1.0.0.zip`
- **Plugin folder inside zip** (optional): same slug so after install the path is e.g. `wp-content/plugins/payment-servers-pay/`.  
  If you keep the folder as `stablecoin-pay-plugin` for both, that’s fine too; the **visible** part is the zip name when they download.

**In the whitelabel repo:**

1. **Build script** (e.g. `create-whitelabel-package.sh` or CI job) accepts:
   - `WHITELABEL_SLUG` (e.g. `payment-servers`)
   - `VERSION` (e.g. `1.0.0`)
2. Build outputs a zip named **`${WHITELABEL_SLUG}-pay-${VERSION}.zip`**.
3. That zip is what you upload to your storage/CDN and what your download URL serves (or what your generic endpoint sends with `Content-Disposition: attachment; filename="payment-servers-pay-1.0.0.zip"`).

**Example build flow (whitelabel repo):**

```bash
# Build one partner's zip only (one plugin at a time)
./create-whitelabel-package.sh --slug=payment-servers --version=1.0.0
# Output: payment-servers-pay-1.0.0.zip

# Do not batch: build one whitelabel (or Stablecoin Pay) per run, then send that zip.
```

**Summary:**

- **Stablecoin Pay repo**: one zip, `stablecoin-pay-1.0.0.zip`; public/default product.
- **Whitelabel repo**: config files + build script; produces **one zip per partner** with name `{partner-slug}-pay-{version}.zip`.
- **Which zip to download**: determined by your backend (partner identity / env_id); partner gets one link and the file they get has a white-label-friendly name.

### 8.4 Whitelabel build script (zip name + config)

In the **whitelabel repo**, the package script should:

1. Accept `--slug` and `--version` (e.g. `payment-servers`, `1.0.0`).
2. Inject the env_id into the plugin (e.g. write `coinsub-whitelabel-config.php` with `'env_id' => 'paymentservers.com'`, or set a build-time constant from the slug).
3. Output the zip as **`{slug}-pay-{version}.zip`** (e.g. `payment-servers-pay-1.0.0.zip`).

Example (conceptual):

```bash
# create-whitelabel-package.sh (in whitelabel repo)
SLUG="${1:-payment-servers}"   # e.g. payment-servers
VERSION="${2:-1.0.0}"
# Build plugin dir, inject config for $SLUG, then:
ZIP_NAME="${SLUG}-pay-${VERSION}.zip"
zip -r "$ZIP_NAME" . -x "*.DS_Store" "*.git*"
# Upload or artifact: $ZIP_NAME
```

Your **dashboard** then either:

- Stores files at a known path: e.g. `plugins/{slug}-pay/{version}/{slug}-pay-{version}.zip`, and the “Download” button links there, or
- Calls a backend that looks up the partner’s `env_id`/slug and serves the matching zip with `Content-Disposition: attachment; filename="{slug}-pay-{version}.zip"`.
