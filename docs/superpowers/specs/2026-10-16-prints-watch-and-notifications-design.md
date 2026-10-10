# Sub-project 8 — Prints, the 30-day watch and the notification channel

Status: built (2026-10-16): the prints, the watch, the bell and the calendar seam.

## Why

The owner showed two slips the business prints today: a pick sheet (the surat jalan:
item code, quantity, item name, the expedition in a note box, three signature blocks,
no price) and a Surat Pengantar (package counts by kind, the vehicle and its plate, the
goods in a line or two, the receiver's signature). Neither matches the base's priced
delivery print. The owner also wants a tanda terima faktur for invoices handed over for
collection, to be told at 30 days whether an accepted order's goods went out, and to see
reminders in the panel, not only by email.

## Decisions

- **Templates per layout.** A print layout may name its own Blade template
  (`print_layouts.settings.template`); the base's `print.document` stays the default.
  Central seeds three layouts: **Surat Jalan** (default for deliveries, template
  `client.print.surat-jalan`), **Surat Pengantar** (deliveries, `client.print.surat-pengantar`),
  **Tanda Terima Faktur** (invoice exchanges, `client.print.tanda-terima-faktur`). The
  base 'Standard' delivery layout stays, no longer the default. Deliveries number `SJ-`.
- **Surat jalan.** Company name and address; Kepada: the customer and the delivery
  address; a box with the title, date, number and Note (the expedition's name and the
  delivery's note); the lines as Kode Barang | Qty | Unit | Nama Barang (part number in
  brackets); signatures Collect By / Check-Packing By / TTD Kepala Gudang with Tgl and
  Jam; "dicetak oleh" with the user and the moment. Never a price.
- **Surat pengantar.** The company mark and phone; No., date; Tuan/Toko and address;
  "Bersama ini kendaraan … No. … kami ada kirim barang-barang tersebut dibawah ini";
  Banyaknya: Koli, Kresek, Ikat, Palet with counts; Nama Barang lines (the goods
  description, else the item names); Penerima / Hormat kami.
- **Tanda terima faktur.** On the base's Invoice Exchange (tukar faktur), which gains a
  Print button: the customer, the exchange number and date, the collect date, the
  invoices (number, date, due, amount), the total, Diserahkan oleh / Diterima oleh.
- **Expedition.** The base's Shipping Methods master, relabelled Ekspedisi, is the list
  of expeditions; its contact prints. A delivery also carries a shipping note, the
  vehicle, the plate number, the package counts and a goods description (Central
  columns), entered at Deliver on the Fulfilment screen; the warehouse's delivery keeps
  the order's expedition, FOB and ship date.
- **30-day watch.** `central:delivery-watch` runs every morning: every approved order
  (each split piece its own) approved 30 days ago or more and not yet reported goes into
  one digest — number, customer, days, delivered x of y with the delivery numbers, or
  not yet delivered with the warehouse holding it — mailed to every active
  administrator and the customer's marketing seat, and shown as a bell notification;
  once per order (`order_delivery_notices`). Nothing is released: the admin decides.
  The days are `client.orders.watch_days` (30). Order Approvals gets an "Over 30 days"
  tab.
- **Channel.** The staff panel gets database notifications (the bell, polled every
  minute). One `Notify` service sends a bell and a mail in the company's language to
  the administrators, a customer's team, or any users; the ops alert keeps its own
  incident mailbox.
- **Calendar.** `CalendarFeed::extend()` lets a module add kinds and events; the legend
  follows. Central adds "Delivery over 30 days"; later sub-projects add count sheets,
  birthdays and the year end.

## Base edits (named here)

- `PrintController`, `PdfRenderer`: render the layout's template when it names one.
- `Printable`: `invoice_exchange` entry; the delivery prints under the title Surat Jalan.
- `InvoiceExchangeResource`: a Print button on the list.
- `AdminPanelProvider`: `databaseNotifications()`.
- `CalendarFeed`: `extend()` and `kinds()`; the calendar page's legend reads them.
- `lang/id.json`, `lang/en/menu.php` (Ekspedisi).

## Tests

`PrintTemplatesTest`, `DeliveryWatchTest`, `NotifyTest`, `CalendarSeamTest`, plus the
delivery maker and fulfilment suites.
