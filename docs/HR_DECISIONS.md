# HR decisions

The questions to settle with HR before Stampy is used for real. Each row has the current default and why it was chosen, who decided it, and where to change it. Ask about them in this order: the time off in lieu ratio and block lock once used, so they come first.

**Who decided:** **Owner** is the company owner's decision while building. **Law** is a Cambodian Labour Law or Prakas 112/25 default. **Claude** is a recommendation made while building, open to veto. **Where to change:** a Policies field changes the setting in the app; "code change" means a developer has to change the rule.

**What a change affects:**
- **Future only:** it applies from now on. Nothing already recorded changes.
- **Rebuild:** past days keep the old result until rebuilt with `php artisan attendance:build-daily --from=YYYY-MM-DD --to=YYYY-MM-DD`.
- **Locks:** it can't be changed once it has been used.

## Decide first: time off in lieu ratio and block

**Decide these before the first time-off overtime is approved.** As soon as any time off in lieu is credited, both are locked. Every half day is worked out again from all the overtime ever credited, so changing them later would re-value it all.

| # | Question | Current default | Decided by | Where to change · what it affects |
|---|---|---|---|---|
| 1 | How much time off does an hour of overtime earn? | **1:1** — an hour off per overtime hour, whatever its pay rate. | Owner | Policies → Overtime → Time off in lieu → **Ratio**. **Locks** at the first credit. |
| 2 | How much overtime makes half a day off? | **4 hours** (240 minutes) per half day. Time below 4 hours carries on toward the next half day, across years. | Owner | Policies → Overtime → **Half a day for every**. **Locks** at the first credit. |

## Leave

| # | Question | Current default | Decided by | Where to change · what it affects |
|---|---|---|---|---|
| 3 | Which leave types does the company have, and how many days each? | Annual 18 days a year (+1 per 3 years of service, carry up to 6). Medical 30 days a year. Special up to 7 days a request. Maternity 90 calendar days. Unpaid with no limit. | Owner (Annual, Medical, Unpaid); law (Special, Maternity — Art. 166, 169/171, 182–183) | Policies → Leave types: **Days per year**, **At most per request**, **Carry over up to**. **Future only**: a new days-per-year applies to grants not yet made. **Counts**, **Half days allowed**, **Draws from** and **Balance** lock once a leave of the type is taken. |
| 4 | Is Medical leave paid in full? | **Paid** (company draft). No medical certificate upload. | Owner | Policies → Leave types → Medical → **Paid**. Only a label: the app doesn't compute pay. |
| 5 | Is Special leave paid, and does it come out of Annual or have to be made up? | **Paid, deducted from Annual**. Before someone is eligible for Annual, they use Unpaid instead. | Law (Art. 166) | Policies → Leave types → Special → **Paid** and **Draws from**. Draws from **locks** once a Special leave is taken. |
| 6 | In someone's first eligible year, can all of their unused Annual leave carry into the next year? | **Yes, uncapped, once**: a late-year joiner can get a large first grant with only weeks left to use it. The 6-day cap applies from the next year. | Claude (accepted by the owner pending HR) | **Code change** (one rule in the balance calculator). Carry-over is calculated, never stored, so a change applies at once to every balance. |
| 7 | Do Medical, Maternity or Unpaid need a minimum length of service, as Annual's 12 months does? | **No**: usable from the join date, pro-rated in the join year. Annual needs 12 months (law). | Claude (accepted by the owner); law for Annual | Policies → Leave types → **Usable after** (types with a yearly grant). **Future only**: grants not yet made. |

## Overtime rates and days

| # | Question | Current default | Decided by | Where to change · what it affects |
|---|---|---|---|---|
| 8 | Which day is the weekly rest day, and what does overtime pay on it and on Saturday? | **Sunday** is the weekly rest day at **200%**. **Saturday** is a non-working day at the workday rate, **150%** (night hours 200%). Workday overtime is 150%. | Owner (Labour Law §139, §164: the premium is for the weekly rest day) | Policies → Overtime → **Weekly rest day** and **Rates**. Rates: only the report's arithmetic changes. Rest day: **rebuild** for past days. A separate Saturday rate is a **code change** (a new category). |
| 9 | Does a public holiday that falls on a Saturday pay the holiday rate? | **Yes, 200%**: holiday beats every other category. | Owner | **Code change** to the category order (holiday > rest day > night > workday), then **rebuild**. |
| 10 | When are night hours? | **22:00–05:00**, at 200%. | Law (Labour Law §139, §164) | Policies → Overtime → **Night starts / Night ends**. **Rebuild** for past days. |

## Limits

| # | Question | Current default | Decided by | Where to change · what it affects |
|---|---|---|---|---|
| 11 | How much overtime, and how much work in all, is allowed in a day? | At most **2 hours** of overtime, and **10 hours** of work in all, a day. An admin can go over either with a written reason. | Law (§137, Prakas 112/25); the admin override is the owner's | Policies → Overtime → **Overtime a day, at most** / **Work a day, at most**. **Future only**: checked when a request is filed and when it's finally approved. |
| 12 | Does the 2-hour cap apply on a day off (Saturday, Sunday, a holiday)? | **No**: only the 10-hour total applies. Without the exception, an ordinary 8-hour Saturday would be refused. | Claude (for the rest day), extended to every day off by the owner | **Code change**. **Future only**. |
| 13 | How far back can an employee claim overtime they already worked? | **7 days**. An admin can go back further. | Owner | Policies → Overtime → **Claims, at most**. **Future only**. |
| 14 | How far ahead can overtime be planned? | **31 days**: far enough to plan a month, near enough that schedules and holidays are known. | Claude | **Code change**. **Future only**. |

## Time off in lieu

The ratio and the block are rows 1–2 above.

| # | Question | Current default | Decided by | Where to change · what it affects |
|---|---|---|---|---|
| 15 | How much unused time off in lieu carries into the next year? | **5 days, a placeholder**. Not "no carry-over": a half day earned on 30 December would lapse the next day. | Owner (placeholder) | Policies → Leave types → Time off in lieu → **Carry over up to**. Carry-over is calculated, not stored, so a change applies at once to every balance. |
| 16 | Must time off for rest-day work be taken in the following week? | **Not enforced**: the balance is shown, and when to take it is left to HR. | Law (§151–152) | **Code change** to enforce it. |

## Counting

| # | Question | Current default | Decided by | Where to change · what it affects |
|---|---|---|---|---|
| 17 | Is the lunch break subtracted from overtime worked on a day off or a holiday? | **Yes**: Saturday 08:00–17:00 counts as 8 hours, not 9. | Claude | **Code change**, then **rebuild**. |
| 18 | Are partial minutes rounded? | **No, dropped**: 1h 20m 59s is 80 minutes, for overtime as for late, early and worked minutes. Late arrival never counts a partial minute against the employee. | Owner | **Code change**, then **rebuild**. |
| 19 | What counts as overtime on a half-day leave day? | Only time outside the **full** schedule. AM leave and work 13:00–19:00 gives 17:00–19:00 at most. | Claude (accepted by the owner pending HR) | **Code change**, then **rebuild**. |
| 20 | Does overtime need both approvals (manager, then admin), or the manager alone? | **Both**, the same two steps as leave. | Owner | **Code change** (the approval engine is shared with leave). **Future only**. |

## Payroll (Phase 5)

| # | Question | Current default | Decided by | Where to change · what it affects |
|---|---|---|---|---|
| 21 | Do late minutes on a day with a missing punch count for payroll? | Late is recorded as soon as the in-punch exists, and stays on an incomplete day. Nothing reaches payroll yet. | Owner (recording); payroll use open | Phase 5 exports. |
| 22 | Should a month be locked once payroll has run? | **No lock**: a late claim, a cancellation, a punch correction or a rate change can still change a past month's report. The CSV says when it was exported, and an open month says so. | Claude (deferred) | Phase 5 build. |
| 23 | Should a past month's pay-equivalent hours use the rates in force then? | **Today's rates**: the report re-prices a past month after a rate change. The minutes never change. | Claude | Phase 5 build, with the lock (22). |
