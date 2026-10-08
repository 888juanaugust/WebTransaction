# Inventory

Module group `inventory`. 13 screens in the standard menu.

## Behaviours

- Stock is per warehouse; cost is a moving average per item per warehouse. Every movement carries its document's date; a back-dated or edited document recosts what came after it, never before the first open period.
- Items are of four types (inventory, non-inventory, service, group) with any number of units and conversion ratios, a selling price per price category, a purchase price, default accounts, a tax code, a minimum stock and an opening stock per warehouse. A group item (bundle) keeps no stock of its own: selling, delivering or taking back a group moves its stocked components (quantity per group unit, in each component's unit), each at its own cost and on its own inventory and cost of sales accounts. Groups are sold, never bought, received or counted.
- Adjustments change quantity and/or value per warehouse against an adjustment account, and record opening stock. Transfers move goods between warehouses with an in-transit stage. Stock opname orders a count per warehouse; the result is compared with the system and its variance posted as an adjustment, approved by someone other than the counter.
- Order fulfilment shows what is ordered and not yet delivered, and what stock on hand and open purchase orders cannot cover ("need to order", the earliest orders served first); stock by warehouse the quantity and value per item per warehouse.
- Minimum stock lists the items at or below their minimum: the warehouse's own minimum when one is set on the item, else the item's overall minimum. Beside stock on hand it shows what approved, open purchase orders still bring (on order) and what approved, open requisitions still ask for (requested), and the quantity to order to get back to the minimum. Selected items open a purchase order (their preferred vendor, when they share one) or a requisition with those lines.
- With departments or projects on, inventory adjustments carry a department and a project on the header and per line, so a write-off reads against the department that made it. Transfers between warehouses carry none.

## Screens

- [Purchase Requisitions](#purchase-requisitions)
- [Item Transfers](#item-transfers)
- [Inventory Adjustments](#inventory-adjustments)
- [Stock Opname Orders](#stock-opname-orders)
- [Stock Opname Results](#stock-opname-results)
- [Items & Services](#items-services)
- [Warehouses](#warehouses)
- [Units](#units)
- [Item Categories](#item-categories)
- [Item Brands](#item-brands)
- [Order Fulfilment](#order-fulfilment)
- [Stock by Warehouse](#stock-by-warehouse)
- [Minimum Stock](#minimum-stock)

## Purchase Requisitions

Menu key `vendor__purchase-requisition` · module `purchasing`

### List

**Columns:** Number · Date · Request type · Notes · Status · Estimated total · Approval

**Filters:** Trans date · Request type · Printed

**Actions:** Approve · Reject · Edit · Create order

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Date | `trans_date` | date | yes |
| Request type | `requisition_type` | select | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Number format | `series_id` | select |  |
| Number | `number` | text |  |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Requested for · Estimated price · Memo

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Item Transfers

Menu key `inventory__item-transfer` · module `inventory`

### List

**Columns:** Number · Date · Process · To / from · Warehouse · Notes · Delivery status · Approval

**Filters:** Trans date · Process · Delivery status · From warehouse · To warehouse

**Actions:** Approve · Reject · Edit · Receive · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Process | `item_transfer_type` | select |  |
| From warehouse | `warehouse_id` | select | yes |
| To warehouse | `reference_warehouse_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Transfer No. format | `series_id` | select |  |
| Transfer No. | `number` | text |  |
| Branch | `branch_id` | select |  |

#### Tab: Line items

**Line grid "Line items":** Item · Category · Quantity · Unit · Memo

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Inventory Adjustments

Menu key `inventory__item-adjustment` · module `inventory`

### List

**Columns:** Number · Date · Notes · Lines · Total cost · Opening · Approval

**Filters:** Trans date

**Actions:** Approve · Reject · Edit · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Adjustment No. format | `series_id` | select |  |
| Adjustment No. | `number` | text |  |
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |

#### Tab: Line items

**Line grid "Line items":** Item · Type · Quantity · Unit · Unit cost · Warehouse · Department · Project · Memo

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Stock Opname Orders

Menu key `inventory__stock-opname-order` · module `inventory`

### List

**Columns:** Order date · Number · Count starts · Warehouse · Status · Notes · Person in charge

**Filters:** Trans date · Status

**Actions:** Edit · Delete

### Form

**Section: Order**

| Field | Column | Type | Required |
|---|---|---|---|
| Order date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Order No. format | `series_id` | select |  |
| Order No. | `number` | text |  |
| Count starts | `start_date` | date | yes |
| Person in charge | `person_charged` | text | yes |
| Counted by | `users` | multi-select | yes |
| Warehouse | `warehouse_id` | select | yes |
| Notes | `description` | textarea |  |

**Section: Items to count**

| Field | Column | Type | Required |
|---|---|---|---|
| Item categories | `itemCategories` | multi-select |  |
| Preferred vendors | `vendors` | multi-select |  |
| Brands | `brands` | multi-select |  |

## Stock Opname Results

Menu key `inventory__stock-opname-result` · module `inventory`

### List

**Columns:** # · Count date · Number · Count order · Notes

**Filters:** Trans date · Status

**Actions:** Edit · Approve and post the variance

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Count date | `trans_date` | date | yes |
| Count order | `stock_opname_order_id` | select | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Count No. format | `series_id` | select |  |
| Count No. | `number` | text |  |

#### Tab: Line items

**Line grid "Line items":** Item · Counted · Unit · System

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

**Actions:** Pull the items of the order

## Items & Services

Menu key `inventory__item` · module `inventory`

### List

**Columns:** Item code · Type · Unit · Item name · Brand · Category · Available stock · In my warehouses · Purchase price · Selling price · Minimum stock

**Filters:** Active · Item type · Category · Brand

**Actions:** Edit · Delete

### Form

**Section: Item**

| Field | Column | Type | Required |
|---|---|---|---|
| Item name | `name` | text | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Item code format | `series_id` | select |  |
| Item code | `number` | text |  |
| Item type | `item_type` | select | yes |
| UPC / barcode | `upc_no` | text |  |
| Base unit | `unit1_id` | select | yes |
| Brand | `brand_id` | select |  |
| Category | `category_id` | select |  |
| Active | `is_active` | toggle |  |

#### Tab: Sales / Purchasing

| Field | Column | Type | Required |
|---|---|---|---|
| Default discount (%) | `default_discount` | number |  |
| Selling price per base unit | `sell_price` | number |  |
| Minimum sale quantity | `min_sell_qty` | number |  |
| Apply wholesale price / discount | `use_wholesale_price` | toggle |  |
| Substitute item | `substitute_item_id` | select |  |
| Preferred vendor | `preferred_vendor_id` | select |  |
| Purchase unit | `vendor_unit_id` | select |  |
| Purchase price | `purchase_price` | number |  |
| Minimum purchase quantity | `min_purchase_qty` | number |  |
| Minimum stock | `min_stock` | number |  |

**Line grid "Minimum stock per warehouse":** Warehouse · Minimum

**Fieldset: Tax**

| Field | Column | Type | Required |
|---|---|---|---|
| e-Tax goods code | `item_tax_code` | text |  |
| VAT | `tax1_id` | select |  |
| Withholding tax | `tax3_id` | select |  |

#### Tab: Units

**Line grid "Other units":** Unit · Contains (base units) · Selling price

#### Tab: Prices

**Line grid "Selling price per price category":** Price category · Unit · Price

#### Tab: Stock

**Line grid "Opening stock at the data start date":** Date · Quantity · Unit · Unit cost · Warehouse

#### Tab: Components

**Line grid "Items in this group":** Item · Quantity · Unit

#### Tab: Accounts

| Field | Column | Type | Required |
|---|---|---|---|
| Inventory | `inventory_account_id` | select |  |
| Sales | `sales_account_id` | select |  |
| Cost of goods sold | `cogs_account_id` | select |  |
| Sales returns | `sales_return_account_id` | select |  |
| Purchase returns | `purchase_return_account_id` | select |  |

#### Tab: Other

| Field | Column | Type | Required |
|---|---|---|---|
| Used in branch | `branch_id` | select |  |
| Notes | `notes` | textarea |  |
| Length (cm) | `length_cm` | number |  |
| Width (cm) | `width_cm` | number |  |
| Height (cm) | `height_cm` | number |  |
| Weight (g) | `weight_gr` | number |  |

## Warehouses

Menu key `inventory__warehouse` · module `inventory`

### List

**Columns:** Name · Address · Branch · Users · Damaged goods · Active

**Filters:** Active

**Actions:** Edit · Delete

### Form

#### Tab: General

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Description | `description` | textarea |  |
| Person in charge | `pic` | text |  |
| Branch | `branch_id` | select |  |
| Use as the warehouse for damaged goods | `scrap_warehouse` | toggle |  |
| Default warehouse | `is_default` | toggle |  |
| Active | `is_active` | toggle |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Address | `address` | textarea |  |

#### Tab: Users

| Field | Column | Type | Required |
|---|---|---|---|
| Available to all users | `used_all_user` | toggle |  |
| Users | `users` | checkbox list |  |

## Units

Menu key `inventory__unit` · module `inventory`

### List

**Columns:** Name · e-Tax unit code

**Actions:** Edit · Delete

### Form

#### Tab: General

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |

#### Tab: Tax info

| Field | Column | Type | Required |
|---|---|---|---|
| e-Tax unit code | `unit_tax_code` | text |  |

## Item Categories

Menu key `inventory__item-category` · module `inventory`

### List

**Columns:** Name · Default

**Actions:** Edit · Delete

### Form

#### Tab: General

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Sub-category of | `parent_id` | select |  |
| Default category | `is_default` | toggle |  |

#### Tab: Accounts

| Field | Column | Type | Required |
|---|---|---|---|
| Inventory | `inventory_account_id` | select |  |
| Sales | `sales_account_id` | select |  |
| Cost of goods sold | `cogs_account_id` | select |  |
| Sales returns | `sales_return_account_id` | select |  |
| Purchase returns | `purchase_return_account_id` | select |  |

## Item Brands

Menu key `inventory__item-brand` · module `inventory`

### List

**Columns:** Name

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |

## Order Fulfilment

Menu key `inventory__backorder-inquiry` · module `inventory`

### List

**Columns:** Customer · Order No. · Date · Ship date · Delivered · Can ship now · Need to order

**Actions:** Deliver

## Stock by Warehouse

Menu key `inventory__stock-warehouse` · module `inventory`

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Item | `item_id` | select |  |

### List

**Columns:** Warehouse · Quantity in each unit · Available stock · Average cost · Value · Address

## Minimum Stock

Menu key `inventory__minimum-stock-item` · module `inventory`

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Vendor | `vendor_id` | select |  |
| Warehouse | `warehouse_id` | select |  |
| Item name or code | `search` | text |  |

### List

**Columns:** Vendor · Item name · Item code · Unit · Available · On order · Requested · Minimum · To order

**Actions:** Order · Request

