## Behaviours

- An asset recorded from a purchase invoice line ("Record as fixed asset" on the invoice) starts with the line's name, date, quantity and net amount; its expenditure is the account the invoice charged, so its posting moves the cost onto the asset account, and the invoice is locked while the asset exists.
- An asset is acquired for cash, on credit or from a purchase invoice, in a category that names its accounts (asset, accumulated depreciation, expense), its method and useful life; a fiscal category gives the tax office's group and rate.
- Depreciation is posted monthly by `erp:depreciate` (scheduled for the month's last day) by the straight-line or declining-balance method, down to the salvage value.
- The tax books are a report, never posted: a fiscal asset depreciates by its fiscal group's method and yearly rate, for the months in use in each fiscal year, the last year of the useful life taking whatever is left. The asset's Fiscal tab shows the years; the depreciation schedule switches between the commercial and the fiscal books.
- Changes revalue an asset or add cost; disposals remove it with the gain or loss; transfers move it between locations and branches. Assets by location lists them where they stand.
