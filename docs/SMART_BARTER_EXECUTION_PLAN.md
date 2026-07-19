# Smart Barter Execution Plan (Integrated Occasion)

## Objective
Implement an integrated sale/barter marketplace in the existing `occasions` domain without splitting the user experience.

## Delivered in this iteration
- Added sale/barter transaction fields on `used_item_listings` (migration).
- Extended backend model, request validation, controller filtering/serialization.
- Extended Flutter occasion model/service/create flow to submit barter metadata.
- Updated marketplace listing badges to display transaction mode (sale, barter, sale+barter).

## Backend migration already prepared
- `database/migrations/2026_07_19_120000_add_smart_barter_fields_to_used_item_listings_table.php`

## Next implementation steps
1. Trade Offer Domain
- Create tables: `trade_offers`, `trade_offer_items`, `trade_offer_status_history`.
- Add statuses: pending, accepted, rejected, cancelled, expired, in_progress, escrow_blocked, escrow_released, delivered, completed, disputed.
- Add CRUD + lifecycle endpoints under `/api/v1/occasions/{listing}/trade-offers`.

2. Escrow Domain (Wallet EDGPAY)
- Create `trade_escrows` table.
- Implement lock/unlock/release using `WalletService` with strict transactional integrity.
- Add dispute branch and immutable audit log for each state transition.

3. Matching Engine v1
- Create `trade_matches` table for computed compatibility snapshots.
- Implement scoring strategy (category, value gap, condition, city, preferences).
- Expose `/api/v1/trade-matches` endpoint with top ranked offers.

4. Radar + Saved Searches + Favorites
- Create tables: `saved_searches`, `favorites`.
- Trigger notifications on new listing match.
- Add endpoints for save/list/delete searches and favorites.

5. Admin and Moderation
- Add admin endpoints for trade offers, escrow actions, and disputes.
- Add force-close operation with audit trail.

6. Flutter UX completion
- Listing filters: sale, barter, sale+barter, distance, price, city, category, condition, wanted object.
- Detail page actions: buy, propose exchange, contact, share, favorite.
- Multi-item offer composer + complement recommendation.

7. Testing and hardening
- Feature tests for listing + offer + escrow lifecycle.
- Wallet consistency tests for blocked/released/dispute paths.
- Regression tests for existing auction/direct sale flows.

## Quality gates before release
- No regression on existing occasion sale and auction flows.
- Atomic wallet operations for all escrow transitions.
- Full status transition validation.
- Pagination and index-backed filtering on marketplace endpoints.
- Notification deduplication for radar alerts.
