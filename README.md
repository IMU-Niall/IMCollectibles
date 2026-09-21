# IM Collectibles — XRP Ledger NFT Marketplace

Production source for **[imcollectibles.io](https://imcollectibles.io)**, a live XRP Ledger NFT marketplace for music, film, art, books and audiobooks. Built and operated by [IMU, LLC](https://imutv.tv).

This repository is published for **review and evaluation**. It is source-available, not open source — see [LICENSE](LICENSE).

---

## Status: live on XRPL Mainnet

This is not a prototype or a hackathon demo. It is the code running the marketplace today, serving real creators and real buyers, settling in XRP and in issued currencies.

Every transaction the platform originates carries the XRPL **SourceTag `2606240013`**, applied marketplace-wide on 22 August 2026. Anyone can verify the platform's on-chain activity independently by filtering for that tag, or by inspecting the public platform fee account `riMCgymFVzdqQoTR82m5oUJE697bDHrJm` on any XRPL explorer.

The tag is set in six files here — `mint-on-demand-handler.php`, `offer-handler.php`, `xumm-proxy.php`, `burn-to-earn.php`, `tip-handler.php` and `events-manager.php` — so you can see exactly which transaction paths it covers.

---

## Architecture boundary — read this first

The IMU platform is several codebases. **This repository is one of them: the marketplace web application.** Understanding what is deliberately absent will save you time reading.

### In this repository

The WordPress-hosted marketplace: minting flows, listings, drops, the secondary market, wallet authentication, entitlements, creator tooling and the buyer-facing pages. 117 files, ~7.3 MB.

### Not in this repository

| Not included | Why |
|---|---|
| **Protected-media service** | The service that stores master assets and decides entitlement. This is the subject of pending patent applications — see [NOTICE](NOTICE). Call sites are left intact so the flows still read end to end; the implementations return `501 Not available in this build`. |
| **VPS media and indexer services** | Transcoding, watermarking, IPFS pinning, the XRPL indexer and ownership-reconciliation daemons. Separate infrastructure, separate repository. |
| **XRPLAYR / IMUP3 mobile apps** | Native iOS and Android clients. The marketplace does not depend on them; the app-auth surface they use is maintained with the app. |
| **IMUTV** | The streaming platform. Separate product. |
| **Astra theme and WordPress** | Third-party GPLv2 software, obtained from its publishers rather than redistributed here. See [Dependencies](#dependencies). |
| **Credentials and production data** | No `.env`, no `wp-config.php`, no database exports, no API keys. Every credential resolves from a constant or environment variable that is absent here. |

Where something was removed, the code says so at the point of removal rather than failing silently.

---

## What the marketplace does

Feature coverage, measured by the number of files in this repository that implement each area:

### Minting and drops

| Capability | Files |
|---|---|
| Xaman (XUMM) transaction signing | 60 |
| Album NFTs with per-track metadata (ISRC, ISWC, IPI, PRO codes) | 24 |
| Royalties and issuer transfer fees | 22 |
| Collections and taxon management | 21 |
| Progressive (stepped) pricing | 20 |
| eBook NFTs with in-browser page-turning | 14 |
| AudioBook NFTs | 13 |
| Allowlists — early access, discounts, exclusives, per-wallet pricing | 12 |
| Open editions with timed close | 11 |
| Pay-what-you-want pricing | 10 |
| Tiered drops | 10 |
| Dynamic NFTs (`tfMutable` / `NFTokenModify`) | 3 |
| Authorised-minter delegation | 2 |

### Trading and holding

| Capability | Files |
|---|---|
| Trustline detection, creation and issued-currency payment | 24 |
| Watchlists | 13 |
| Brokered secondary sales | 2 |

### Community and rewards

| Capability | Files |
|---|---|
| Rewards and task engine | 23 |
| Burn-to-earn | 17 |
| Push notifications | 8 |
| Events and live experiences | 7 |
| Moderation, scam filtering and DMCA handling | 7 |
| User profiles and follows | 3 |
| Creator analytics | 2 |
| In-app wallet-to-wallet chat | 2 |
| Creator and holder tipping | 2 |

### XRPL transaction types implemented

`NFTokenMint` · `NFTokenCreateOffer` · `NFTokenAcceptOffer` · `NFTokenCancelOffer` · `NFTokenBurn` · `NFTokenModify` · `TrustSet` · `Payment` · `AccountSet`

Metadata follows **XLS-24d**. NFTs are **XLS-20**.

---

## How to read the code

PHP handlers, page templates, and the JavaScript and CSS they enqueue. There is no build step — the JavaScript ships as written.

**The tree is flattened.** In production these files sit in subdirectories of the theme — handlers under `xrpl-nft-marketplace/backend/`, shared includes under `inc/`, assets under `js/` and `css/`. They are published here at the top level for readability, so paths inside the code still refer to their production locations: a `require` of `/inc/imc-session-auth.php` points at `imc-session-auth.php` in this repository.

### Start here

| File | What it is | Size |
|---|---|---|
| `mint-on-demand-handler.php` | The heart of the system. Purchase groups, payment verification, mint orchestration, the worker lane, delivery reconciliation and recovery. | ~8,900 lines |
| `listings-handler.php` | Listing and drop CRUD, pricing modes, tiers, schema. | |
| `offer-handler.php` | The secondary market: offers, brokered settlement, royalty splits. | |
| `mint.js` | The creation wizard — every mint path a creator can take. | |
| `collections.php` | The buyer-facing drop and browse surface. | |
| `page-nft-single.php` | The NFT detail page, including holder-only content rendering. | |
| `imc-session-auth.php` | Wallet identity: proof-bound HMAC session tokens. | |

### Two conventions worth knowing

**Identity comes from the session, never the request.** `imc_session_require_wallet()` returns `['ok', 'wallet', 'error']` and is the only thing that proves a caller controls a wallet. A wallet address arriving in `$_GET` or `$_POST` is treated as data, never as proof — XRPL addresses are public, so knowing one proves nothing. Handlers resolve the wallet once and use it as the identity for every ownership check.

**Comments carry the reasoning.** Non-obvious decisions are annotated with why they are the way they are, not just what they do. Where a comment names a version like `v728` or a phase like `A2`, that is an internal build reference.

---

## Dependencies

This repository contains only IMU's own code. It is **not a runnable WordPress theme on its own** — it needs its dependencies, and it needs configuration that is deliberately absent.

Required, not included:

- **WordPress** — GPLv2 or later
- **Astra theme** by Brainstorm Force — GPLv2 or later, from [wpastra.com](https://wpastra.com/). The marketplace runs inside Astra. Astra's own files, including `style.css` and the constant definitions and bootstrap it loads from `functions.php`, are not redistributed here; the point that bootstrap occupies is marked in place.
- **Mozilla PDF.js**, **WalletConnect**, **OneSignal**, **Chart.js**, **particles.js**, **Hardcastle XRPL PHP**

Full attribution and licences: [NOTICE](NOTICE).

---

## Licence

**Source-available. All rights reserved. Not open source.**

You may read, study and quote this code with attribution. You may not use, modify, redistribute or deploy it without written permission.

**No patent licence is granted.** IMU, LLC has patent applications pending on dual-asset NFT minting and NFT-gated media playback. Publishing this source is not a dedication to the public and does not waive any claim. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

Licensing enquiries: **legal@imutv.tv**

---

## About

Built by **IMU, LLC**, founded August 2024 — an independent music and media company building creator-owned infrastructure on the XRP Ledger, across IMUTV (streaming), XRPLAYR (NFT media player) and IM Collectibles (this marketplace).

- Marketplace — [imcollectibles.io](https://imcollectibles.io)
- Company — [imutv.tv](https://imutv.tv)
- Support — support@imcollectibles.io

*Not affiliated with, endorsed by, or an official product of Ripple Labs Inc. or the XRP Ledger Foundation.*
