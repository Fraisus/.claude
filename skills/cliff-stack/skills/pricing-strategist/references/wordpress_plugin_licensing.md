# WordPress plugin/theme licensing & packaging — the site-based specifics

> **Boundary:** this skill DECIDES the price/model/packaging. Configuring it in the billing platform (creating the plan, the coupon, the webhook, the SDK gating) belongs to that platform's side (the `freemius` skill when it is Freemius) — decide here, implement there.
>
> Complements the generic Good/Better/Best logic of this skill with the WP-specific layer. Facts verified 2026-06-20 (Freemius blog cross-checked with real plugin prices + ProfitWell/Paddle + independent third-party sources). **Bias flag:** several of these come from the Freemius blog, which has a commercial interest in pushing recurring subscriptions over lifetime/monthly. Claims corroborated by independent sources are marked **[robust]**; Freemius-only recommendations are marked **[Freemius reco]**.

## Contents
- [1. Value metric = number of sites](#1-value-metric--number-of-sites)
- [2. Lifetime pricing & when to avoid it](#2-lifetime-pricing--when-to-avoid-it)
- [3. Discount the first payment, not the renewal](#3-discount-the-first-payment-not-the-renewal)
- [4. Raising prices: grandfather vs grace period](#4-raising-prices-grandfather-vs-grace-period)
- [5. Low-commitment entry point (monthly/trial)](#5-low-commitment-entry-point-monthlytrial)
- [6. Numbers you may cite vs must not](#6-numbers-you-may-cite-vs-must-not)

## 1. Value metric = number of sites
For a self-hosted WP plugin/theme, the **number of sites** the license activates on is the natural primary differentiator (the license activates per domain), **usually combined with — not replacing — feature tiers**. *Observed tendency, not a law.*
- Real grids: **WP Rocket** 59$/119$/299$ by site-count, identical features across tiers (mono-function plugin); **WPML** tiers by site count, "Agency" = unlimited prod+dev. **[robust]**
- For a multi-feature product (Elementor/Yoast), site-count and feature-gating coexist. Don't assert "sites before features" as universal.
→ When packaging a WP plugin: design the site-count axis first (e.g. 1 / 5 / 25 or unlimited-domains), then layer feature tiers if the product is multi-feature. Still run the generic model/packaging steps of this skill.

## 2. Lifetime pricing & when to avoid it
- **Price a lifetime at 3–5× the annual (sweet spot 3–4×).** Argued from survival/renewal math (~50% renewal → only ~6% renew 5 years straight). **[Freemius reco]** — present as a sourced heuristic, not a constant.
- **The dangerous combo is `lifetime + unlimited sites`** for a product with ongoing maintenance: revenue is flat one-time while support cost grows with the active base → "lifetime = free accounts." **[robust]**
  - WPML/Toolset case (data from OnTheGoSystems, a third party): moving lifetime→auto-renew took renewal rate **30%→60%**, revenue/renewal **$39→$116**, **−30% revenue year 1** (lost upfront cash), acquisition stable after ~4 months. **WPML officially stopped selling lifetime in 2018** (honoring existing holders). WP Rocket retired its "Infinite" unlimited tier. **[robust]**
→ If a lifetime is offered: cap it in **domains** (never "unlimited sites"), keep it in a premium tier, price 3–4× annual.

## 3. Discount the first payment, not the renewal
With **auto-renew**, the renewal needs no proactive customer action → a renewal discount doesn't change the decision, it only erodes margin. Discount the **first payment** instead. **[Freemius reco, sound logic]**
- **Explicit exception:** keep a renewal discount for **one-shot products with no cumulative value** (migration plugin, coming-soon theme, giveaway tool). Not a universal anti-pattern.
- Historical note: renewal discounts were common in the manual-renewal era (friction to overcome); auto-renew made them largely obsolete.

## 4. Raising prices: grandfather vs grace period
Principle **[robust]**: raise prices for **new** customers, **grandfather existing** auto-renew subscriptions. Grandfathering is the most common SaaS price-change method (~**46%** of companies). Slack 2021: +8% avg price, 90-day notice, one-cycle grandfathering → minimal churn.
- **Hidden cost** to flag: grandfathering perpetually keeps low-engagement customers on old prices + multi-version reporting complexity. Some advise a **grace period (6–12 months)** instead of perpetual grandfathering.
- Elasticity reality: SaaS price elasticity coefficients ≈ **−1.5 to −2.5** → a +10% price can cut **new-customer acquisition** by 15–25%. The "negligible churn" only applies to the **grandfathered existing base**, not to new prospects.

## 5. Low-commitment entry point (monthly/trial)
A low-commitment entry (monthly plan or trial) can act as a psychological anchor / evaluability point. One editor (RatingWidget) saw conversions drop after removing the monthly plan — *but* this is an anecdote (**n = 19 → 10**, ~2 weeks, a "service-ware" SaaS-as-plugin), **not a reliable metric**. Cite as an example, never as "removing monthly costs 47% of conversions."

## 6. Numbers you may cite vs must not
**Safe to cite (sourced):**
- Lifetime = 3–5× annual (sweet spot 3–4×).
- WPML move: renewal 30%→60%, revenue/renewal $39→$116, −30% revenue year 1; lifetime killed 2018; lifetime was $195 ≈ 3 years.
- WP Rocket: 59$/119$/299$ by sites; "Infinite" retired.
- Grandfathering ≈ 46% of SaaS price changes.
- **ProfitWell: +1% pricing ≈ +11% operating profit**; monetization ≈ 8× the impact of acquisition.
- SaaS elasticity coefficients −1.5 to −2.5.

**Do NOT cite (fabricated/unsourced):**
- ❌ "+10% price = +10% revenue for 1–2% churn across 14,000 SaaS." Not a real ProfitWell stat — a distortion. Use "+1% pricing ≈ +11% operating profit" instead.
- The "−47% RatingWidget" figure: real but n=19→10, anecdotal — never present as a metric.
