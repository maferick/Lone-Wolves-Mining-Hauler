# Admin Operations Pricing & Controls

Use this guide when you need to configure pricing behavior, reward validation, and dispatch visibility in the Admin Operations area. It is written for the person who will manage daily hauling operations and pricing rules.

## Where to find these settings

Navigate to **Admin → Hauling → Routing & Risk Controls**. The tab bar at the top contains the sections listed below: Rules, Optimization, Access, Validation, SLAs/Timers, and Risk/Restrictions. All pricing and dispatch controls live inside these tabs. 【F:public/admin/hauling/index.php†L17-L206】

---

## Rules (Pricing baseline)

### Default priority
Sets the default priority applied to new hauling requests. Higher priority signals urgent routing and can influence queue handling or external notifications, depending on your workflow. Save after choosing **Normal** or **High**. 【F:public/admin/hauling/index.php†L60-L77】

### Reward tolerance (Price validation)
Controls how strictly the system validates the reward (price) submitted with a request. The tolerance can be set as:

- **Percent**: allowed percentage difference from expected pricing.
- **Flat ISK**: allowed absolute ISK difference.

Use this when you want to allow small deviations (ex: contracts rounded by the requester) without flagging a mismatch. Save after entering the value. 【F:public/admin/hauling/index.php†L78-L94】

**What it affects:** When reward validation runs, the system checks request rewards against the allowed tolerance range. This is reflected in request validation outcomes and contract checks in Operations. 【F:public/assets/js/admin/hauling.js†L153-L177】

---

## Optimization (Route suggestions)

These settings don’t set prices directly, but they control when the system suggests extra jobs to haulers (which can influence how much work gets bundled into a run).

### Enable optimization
**Enable optimization** toggles whether haulers receive suggestions for additional haul opportunities. 【F:public/admin/hauling/index.php†L103-L122】

### Detour budget (jumps)
Maximum extra jumps allowed when recommending additional stops. Higher values allow broader route suggestions. 【F:public/admin/hauling/index.php†L106-L122】

### Max suggestions
Caps how many extra suggestions a hauler will receive at a time. 【F:public/admin/hauling/index.php†L109-L122】

### Min free capacity (%)
Only suggests extra jobs if the hauler has at least this percentage of free capacity. 【F:public/admin/hauling/index.php†L112-L122】

**Operational impact:** If enabled, the system will look for nearby opportunities and present them to haulers when their ship has available capacity and the detour budget allows it. 【F:public/assets/js/admin/hauling.js†L95-L150】

---

## Access (Pricing-related entry points)

### Contract attachment
Toggle whether requesters are allowed to attach in-game contract IDs to their requests. This affects how pricing is verified, because linked contracts provide actual reward/volume data. 【F:public/admin/hauling/index.php†L128-L145】

**If disabled:** requesters cannot attach contract IDs, and pricing validation relies solely on the submitted request data. 【F:public/assets/js/admin/hauling.js†L52-L68】

### Quote location inputs
Allows quote requests to search stations and structures in addition to just system names. This expands the set of possible routes and can impact pricing because distances and route risk may change based on the exact origin/destination. 【F:public/admin/hauling/index.php†L147-L164】

### Operations dispatch sections
Controls whether **Assign haulers** and **Update status** sections are visible in the Operations screen. This is not a pricing control, but it directly affects who can execute operational steps once pricing is approved. 【F:public/admin/hauling/index.php†L166-L184】

---

## Validation (Buyback haulage pricing)

### Buyback haulage tiers
This is the primary **price table** for buyback hauling requests. You define **four volume tiers** (up to 950,000 m³) and the price for each tier. 【F:public/admin/hauling/index.php†L189-L212】

**How it works:**

1. Each row is a tier with **Max Volume (m³)** and **Price (ISK)**.
2. The system chooses the first tier that can cover the request’s volume.
3. If every tier price is 0, buyback haulage is effectively disabled. 【F:public/assets/js/admin/hauling.js†L225-L250】

**Recommended approach:**
Set tier thresholds to match ship classes you allow (ex: small, medium, large, capital), then match pricing so volume-heavy contracts are properly compensated. 【F:public/admin/hauling/index.php†L189-L212】

---

## SLAs / Timers

There are no SLA or timer controls configured yet. This section is a placeholder for future policy-based pricing or urgency modifiers. 【F:public/admin/hauling/index.php†L214-L220】

---

## Risk / Restrictions (Indirect pricing impact)

These are not price settings, but they materially affect how pricing *should* be set because they define which routes are allowed and how risky they are.

### Security class definitions
Define the security bands that classify systems (High-sec vs Low-sec, plus special zones). 【F:public/admin/hauling/index.php†L223-L269】

You can also enable and define special cases:
- **Pochven** (by region)
- **Zarzakh** (by system)
- **Thera** (by system)

These classifications can change what routes are allowed and therefore how pricing should be tuned. 【F:public/assets/js/admin/hauling.js†L179-L217】

### Security routing rules
For each security class, you can allow or deny:

- **Pickup**
- **Delivery**
- **Transit only**
- **Requires acknowledgement**

Disabling pickup/delivery but allowing transit means routes may pass through but cannot start/end there. This impacts which requests are accepted and how “risk premiums” should be modeled in your pricing policy. 【F:public/admin/hauling/index.php†L271-L296】

### Route blocks
Hard or soft route blocks are used to exclude systems/regions (hard block) or apply a penalty (soft block). This indirectly affects pricing by making certain routes longer or unavailable, often requiring higher rewards to compensate. 【F:public/admin/hauling/index.php†L298-L352】

### Webhooks
Ops dispatch and pricing actions can trigger Discord/Slack alerts via the Webhooks page. This doesn’t change pricing directly, but it ensures pricing changes and assignments are visible to the team. 【F:public/admin/hauling/index.php†L354-L362】

---

## Practical “What to Adjust When Pricing Feels Off”

1. **Too many mismatches or failed validations?**  
   Increase **Reward tolerance** or ensure contracts are attached. 【F:public/admin/hauling/index.php†L78-L94】

2. **Buyback contracts feel underpaid?**  
   Raise prices in the **Buyback haulage tiers**, especially at higher volumes. 【F:public/admin/hauling/index.php†L189-L212】

3. **Haulers ignore small add‑on jobs?**  
   Enable **Optimization** and increase **Min free capacity** to only surface worthwhile add‑ons. 【F:public/admin/hauling/index.php†L103-L122】

4. **Routes feel too risky for current payouts?**  
   Tighten **Security routing rules** or apply **Route blocks** for dangerous areas. 【F:public/admin/hauling/index.php†L271-L352】
