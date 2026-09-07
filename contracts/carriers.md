# contracts/carriers.md — carrier platform capabilities (B5e Vendor API Discovery)

**Status: placeholder.** Seat C fills this in immediately after M1 (COLLAB_PLAN §4 row "B5e"); it is a Go / No-Go gate, not a merge point. Until it is filled, seat X2 implements only the `manual` and `own_fleet` `CarrierAdapter`s (`carrier_services.source`) and must not promise POD retrieval, webhooks or waybill formats for Transdirect or EIZ (ERP_PLAN §5.6 B5e, §8.9).

Known before discovery (§5.6): Transdirect publishes API documentation and states that accounts can access tracking, POD and labels. EIZ's public material only proves its own UI offers quote / label / tracking; an external partner API is unverified.

## Checklist — answer every row for each platform before deciding
| # | Question | Transdirect | EIZ |
|---|---|---|---|
| 1 | Auth method (API key / OAuth), account type needed, cost | | |
| 2 | Sandbox available? credentials obtained? (team vault only, never in the repo) | | |
| 3 | Quote endpoint: required input (addresses, packages type / qty / weight / L×W×H, service level, tailgate flag, residential flag) | | |
| 4 | Quote response (carrier, service level, cost ex / inc GST, ETA days, surcharges: fuel / tailgate / remote / residential) → `transport_quotes.cost_cents`, `eta_days` | | |
| 5 | Quote validity / expiry and re-quote behaviour → `transport_quotes.expires_at` | | |
| 6 | Booking endpoint: input, response (booking ref, tracking number, waybill / label file + format) → `shipments.booking_ref`, `tracking_number`, `waybill_pdf` | | |
| 7 | Pickup booking and dispatch cut-off rules | | |
| 8 | Tracking: webhook push or polling? event vocabulary → `tracking_events` | | |
| 9 | POD: retrievable via API? format (PDF / image / name only) → `pods.pod_file` | | |
| 10 | Cancellation / amendment of a booking → `booking_cancelled` | | |
| 11 | Address validation / suburb–postcode lookup | | |
| 12 | Actual cost after delivery (re-weigh, surcharges) → `carrier_costs.actual_cost` | | |
| 13 | Carrier invoice export for reconciliation (B9b) | | |
| 14 | Rate limits, uptime, support channel | | |
| 15 | Service levels / zones exposed → mapping to `orders.service_level`, `rate_items.zone` | | |

## Decision
| Platform | Go / No-Go | Date | Scope in phase 1 | Fallback |
|---|---|---|---|---|
| Transdirect | | | | `manual` adapter |
| EIZ | | | | `manual` adapter |

No-Go for a platform = it is out of phase 1; the `manual` adapter (typed tracking number, uploaded POD) keeps quote → book → POD unbroken (§8.9).
