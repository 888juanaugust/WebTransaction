# Fixed Assets

Module group `fixed-assets`. 7 screens in the standard menu.

## Behaviours

- An asset recorded from a purchase invoice line ("Record as fixed asset" on the invoice) starts with the line's name, date, quantity and net amount; its expenditure is the account the invoice charged, so its posting moves the cost onto the asset account, and the invoice is locked while the asset exists.
- An asset is acquired for cash, on credit or from a purchase invoice, in a category that names its accounts (asset, accumulated depreciation, expense), its method and useful life; a fiscal category gives the tax office's group and rate.
- Depreciation is posted monthly by `erp:depreciate` (scheduled for the month's last day) by the straight-line or declining-balance method, down to the salvage value.
- The tax books are a report, never posted: a fiscal asset depreciates by its fiscal group's method and yearly rate, for the months in use in each fiscal year, the last year of the useful life taking whatever is left. The asset's Fiscal tab shows the years; the depreciation schedule switches between the commercial and the fiscal books.
- Changes revalue an asset or add cost; disposals remove it with the gain or loss; transfers move it between locations and branches. Assets by location lists them where they stand.
- Aset tetap (Central): Finance holds the fixed-assets screens with the Month-end Process; the Owner alone reopens a month. Depreciation posted for every month an asset was in use is one of the items the year-end close checks.

## Screens

- [Fixed Assets](#fixed-assets)
- [Asset Categories](#asset-categories)
- [Fiscal Asset Categories](#fiscal-asset-categories)
- [Asset Changes](#asset-changes)
- [Asset Disposals](#asset-disposals)
- [Asset Transfers](#asset-transfers)
- [Assets by Location](#assets-by-location)

## Fixed Assets

Menu key `fixed-asset__fixed-asset` · module `fixed-assets` · switched by Preferences → Features → Fixed assets

### List

**Columns:** Asset code · Name · Purchase date · Category · Location · Qty · Total cost · Book value · Status

**Filters:** Asset category · Location

**Actions:** Edit · Depreciation

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Purchase date | `trans_date` | date | yes |
| In use from | `usage_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Asset code format | `series_id` | select |  |
| Asset code | `number` | text |  |
| Asset category | `asset_category_id` | select | yes |
| Intangible asset | `intangible` | toggle |  |
| Depreciation method | `depreciation_method` | select | yes |
| Quantity | `quantity` | number | yes |
| Useful life (months) | `useful_life_months` | number | yes |
| Salvage value | `salvage_value` | number |  |

#### Tab: General

| Field | Column | Type | Required |
|---|---|---|---|
| Asset account | `asset_account_id` | select | yes |
| Accumulated depreciation account | `accumulated_depreciation_account_id` | select | yes |
| Depreciation expense account | `depreciation_expense_account_id` | select | yes |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Initial location | `location_id` | select |  |
| Notes | `notes` | textarea |  |
| Fiscal asset | `fiscal` | toggle |  |
| Fiscal asset group | `fiscal_asset_category_id` | select |  |
| Branch | `branch_id` | select |  |

#### Tab: Fiscal

| Field | Column | Type | Required |
|---|---|---|---|
| Fiscal schedule | `fiscal_schedule` | computed |  |

#### Tab: Expenditure accounts

**Line grid "Expenditures":** Account · Description · Date · Amount

| Field | Column | Type | Required |
|---|---|---|---|
| Total cost | `cost_preview` | computed |  |

## Asset Categories

Menu key `fixed-asset__fa-type` · module `fixed-assets` · switched by Preferences → Features → Fixed assets

### List

**Columns:** Name · Asset account · Method · Life (months) · Active

**Filters:** Active

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Asset account | `asset_account_id` | select | yes |
| Accumulated depreciation account | `accumulated_depreciation_account_id` | select | yes |
| Depreciation expense account | `depreciation_expense_account_id` | select | yes |
| Depreciation method | `depreciation_method` | select | yes |
| Useful life (months) | `useful_life_months` | number | yes |
| Active | `is_active` | toggle |  |

## Fiscal Asset Categories

Menu key `fixed-asset__fiscal-fa-type` · module `fixed-assets` · switched by Preferences → Features → Fixed assets

### List

**Columns:** Rate (%) · Name · Estimated life (years) · Method

**Filters:** Depreciation method

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Depreciation method | `depreciation_method` | select | yes |
| Estimated life (years) | `useful_life_years` | number | yes |
| Depreciation rate (%) | `rate_percent` | number |  |

## Asset Changes

Menu key `fixed-asset__fixed-asset-edited` · module `fixed-assets` · switched by Preferences → Features → Fixed assets

### List

**Columns:** Number · Date · Description · Asset · Asset name · Kind · Added

**Filters:** Trans date · Kind of change

**Actions:** Edit

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Kind of change | `change_type` | select | yes |
| Asset | `fixed_asset_id` | select | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Change No. format | `series_id` | select |  |
| Change No. | `number` | text |  |
| Date | `trans_date` | date | yes |
| New depreciation method | `new_depreciation_method` | select |  |
| New salvage value | `new_salvage_value` | number |  |
| New useful life (months) | `new_useful_life_months` | number |  |
| What changed | `description` | textarea |  |

#### Tab: Expenditure

**Line grid "Expenditures":** Account · Description · Amount

| Field | Column | Type | Required |
|---|---|---|---|
| Added to the asset | `amount_preview` | computed |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Intangible asset | `new_intangible` | toggle |  |
| Branch | `branch_id` | select |  |
| Asset account (new) | `asset_account_id` | select |  |
| Fiscal asset | `new_fiscal` | toggle |  |

## Asset Disposals

Menu key `fixed-asset__fixed-asset-disposed` · module `fixed-assets` · switched by Preferences → Features → Fixed assets

### List

**Columns:** Number · Date · Notes · Asset · Asset name · Qty · Proceeds · Gain / (loss)

**Filters:** Trans date

**Actions:** Edit

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Asset | `fixed_asset_id` | select | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Disposal No. format | `series_id` | select |  |
| Disposal No. | `number` | text |  |
| Date | `trans_date` | date | yes |
| Quantity | `quantity` | number | yes |
| Gain / loss account | `gain_loss_account_id` | select | yes |
| Asset location | `location_id` | select |  |
| Sold | `selling_asset` | toggle |  |
| Proceeds | `proceeds` | number |  |
| Proceeds to | `proceeds_account_id` | select |  |
| Notes | `description` | textarea |  |
| Book value of the asset | `book_value` | computed |  |

## Asset Transfers

Menu key `fixed-asset__asset-transfer` · module `fixed-assets` · switched by Preferences → Features → Fixed assets

### List

**Columns:** Number · Date · Notes · From · To

**Filters:** Trans date · From · To

**Actions:** Edit

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Transfer No. format | `series_id` | select |  |
| Transfer No. | `number` | text |  |
| From location | `from_location_id` | select | yes |
| To location | `to_location_id` | select | yes |

#### Tab: Asset details

**Line grid "Line items":** Asset code · Asset name · Quantity · Memo

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Assets by Location

Menu key `fixed-asset__asset-location` · module `fixed-assets` · switched by Preferences → Features → Fixed assets

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Find an asset | `fixed_asset_id` | select |  |

### List

**Columns:** Location · Address · Quantity · Assets

