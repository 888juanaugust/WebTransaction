# Sub-project 9 — Stock intelligence, counts and damaged goods

Status: built (2026-10-17): stock age, analytics, count sheets, damaged goods.

## Why

The owner wants to know how old the stock is from the day it entered the warehouse,
which products sell most, least and never, a daily count of what moved and a full count
every semester, and damaged returns kept apart from saleable stock. The base keeps the
stock ledger with every movement's date, the stock opname documents and the sales
return with a warehouse per line; it has no age, no analytics, no cadence and no
condition on a return.

## Decisions

- **Stock age** is derived from the ledger, never stored: receipts open layers, issues
  consume the oldest first (quantity only; the moving average stays the costing method);
  what remains is the stock on hand with the day it entered. A transfer's goods re-enter
  the destination on the receive date; a return enters on its date; opening stock counts
  from its date; zero-quantity entries (value adjustments) open no layer; the In Transit
  warehouse is skipped. Buckets 0–30, 31–90, 91–180, 181–365, over 365 days; value at
  the average cost. Per warehouse; a gudang account sees its own.
- **Product analytics** is one screen with six views over the same filters (period,
  branch, warehouse, category, brand): most sold (by quantity, by value, by customers),
  least taken (on hand, no delivery in 90 days), never sold, oldest stock, most returned,
  turnover. Cost and margin only with the "see cost" right. Excel export. A Central screen
  under Reports; the catalogue stays the base's.
- **Count cadence.** Every working evening `central:opname-sheets` drafts, per warehouse
  with a bound account, a count sheet (a Stock Opname Order of kind `daily` and its
  result) holding only the SKUs that went OUT of that warehouse that day; on 1 January
  and 1 July a `semester` sheet holds every SKU the warehouse has had. Sheets are written
  in the System user's name. The gudang counts on Count Sheets (its own warehouse); the
  Purchasing group approves, which posts the variance as the base does. A sheet not
  counted by the next evening is a bell and a mail; sheets due show in the calendar.
- **Damaged returns.** A return claim line carries a condition, good or damaged; the
  filer suggests it, the verifier decides. Damaged lines enter the branch's damaged-goods
  warehouse (Gudang Rusak, one per cabang, flagged `scrap_warehouse`); the customer is
  credited in full as before. Damaged stock never counts as available to the portal, the
  reservations or the order splitter. Damaged Goods lists what sits there with its age
  and the return it came from; Purchasing writes it off (an inventory adjustment on the
  damaged-goods expense account) or returns it to the vendor by hand.

## Base edits

None. Status labels through a Central match; strings in `lang/id.json`.

## Tests

`StockAgeTest`, `ProductAnalyticsTest`, `OpnameScheduleTest`, `DamagedReturnTest`.
