# Open World Multilingual 🌍

> **The first complete, free, and open-source multilingual plugin for WordPress and WooCommerce.**

Open World gives you full control over your website translations — no premium lock-ins, no per-word fees, no bloated dependencies. Built entirely on WordPress-native standards and engineered for performance.

---

## Features

| Feature | Description |
|---|---|
| **Zero DB Overhead** | Uses WordPress native text domains. No extra columns, no schema changes. |
| **Auto-Translate** | Bulk-translate your entire store via Google Translate Free (built-in) or the DeepL API. |
| **Smart Scanner** | Crawls your live frontend and captures only strings actually rendered — skip lots of unused strings. |
| **Inline Editor** | Translate strings visually while browsing your site. Click any text in the sidebar to jump straight to it. |
| **Full WooCommerce Support** | Translates product titles, descriptions, categories, checkout fields, and order emails. |
| **Hreflang & SEO** | Built-in language switchers, hreflang tags, and clean URL endpoints (`/es/`, `/pl/`, etc.). |
| **Language Statuses** | Set languages as `active` (public), `pending` (admin-only preview), or `inactive` (hidden). |
| **PO/POT Export** | Export translations as standard `.po` files for backup or use in other tools. |
| **Plural Forms** | Full plural rules for Polish, Russian, Arabic, Czech, and 20+ other languages. |
| **SEO Plugin Integrations** | Translates SEO titles, meta descriptions, OG tags, Twitter Cards, and JSON-LD schema for **Yoast**, **Rank Math**, **AIOSEO**, and **SEOPress**. |

---

## Quick Start

1. **Install and activate** the plugin.
2. Go to **Open World → Languages** and add your target languages (e.g. Polish, Spanish).
3. Navigate to **Settings** and click **Smart Scan** — it will crawl your live pages and collect only the strings your site actually uses.
4. Go to **Translations**, pick a language, and start translating.
5. Go to **Translations**, pick a language, and manually translate, or click **Auto-Translate** to automatically translate everything using Google Free or DeepL.

---

## Inline Editor

Translate directly while browsing your frontend:

1. Log in as an administrator.
2. Click **🌐 Translate** in the WordPress Admin Bar.
3. A sidebar slides in. Click any visible text on the page — the sidebar jumps to that exact string so you can edit it for all languages at once.

---

## Auto-Translate Engine

Translate your entire store in automated batches without typing a word. Open World comes with **Google Translate Free natively built-in** with zero configuration required. Alternatively, you can connect a DeepL API key if you prefer their translation engine.

- **Resilient Batching:** Both translation engines feature advanced, resilient network wrappers to smoothly push through massive batches of 100,000+ strings without cURL timeouts.
- **Quota Management:** DeepL quota usage is monitored and cached locally to minimize API hits.
- **Smart Mapping:** Native DeepL language code mapping is built directly into the engine (e.g. `en` → `EN-US`, `pt` → `PT-BR`).

---

## Smart Scanner — How It Works

The Smart Scanner sends an authenticated internal HTTP request to each of your frontend URLs with an `X-OW-Scan` header. During rendering, the plugin engine captures every `__()`, `_e()`, `esc_html__()`, and similar gettext calls in real time, then stores them as empty translation seeds.

**Scanned sources:**
- All published pages and posts
- WooCommerce shop, cart, checkout, and My Account pages
- Product pages
- Your active child theme's PHP files

Run **Clean Unused Strings** afterwards to remove any static-scan strings that have never been translated.

---

## SEO Plugin Integrations

Open World automatically hooks into the frontend output of the most popular SEO plugins — no additional configuration required. After running a Smart Scan, your SEO strings become translatable in **Open World → Translations**.

| Plugin | Titles & Meta | Open Graph | Twitter Cards | JSON-LD Schema |
|---|---|---|---|---|
| **Yoast SEO** | ✅ | ✅ | ✅ | ✅ |
| **Rank Math** | ✅ | ✅ | — | ✅ |
| **All in One SEO** | ✅ | ✅ | — | — |
| **SEOPress** | ✅ | ✅ | ✅ | ✅ |

> **Product Schema (WooCommerce):** `name` and `description` fields in your Product JSON-LD are also translated, making your Google Shopping rich results multilingual.

---

## 🛒 WooCommerce Support

Open World hooks into WooCommerce at every level:

- **Product titles, short descriptions, and full descriptions** — per-language meta fields with a tabbed UI in the product edit screen
- **Category and tag names** — translated via term meta
- **Checkout field labels and placeholders** — translated dynamically per language
- **Order emails** — locale is switched to the customer's language before sending

---

## Language Management

Each language has three statuses:

- **Active** — visible on the frontend to all visitors; URL endpoint is live
- **Pending** — visible only to admins (useful during the translation phase before going live)
- **Inactive** — completely hidden, no URL endpoint

---

## Contributing

Open World is 100% open source (GPL-2.0+). Pull Requests are welcome for:

- New language support or plural rules
- Scanner improvements (new gettext patterns, new source types)
- Bug fixes and performance enhancements
- Other MT provider integrations

Please open an issue first for larger features.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce 7.0+ *(optional, for WooCommerce features)*
- Google Translate (built-in) or DeepL API key *(optional, for premium engines)*

---

## License

GPL-2.0 or later. See [LICENSE](LICENSE) for details.
