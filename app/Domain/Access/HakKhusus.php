<?php

declare(strict_types=1);

namespace App\Domain\Access;

/** Rights that are not tied to one screen; granted to a group on its Special Rights tab. */
enum HakKhusus: string
{
    case SeeCost = 'see_cost';
    case ChangeSellingPrice = 'change_selling_price';
    case SeeCreditData = 'see_credit_data';
    case OverrideCreditLimit = 'override_credit_limit';
    case OpenClosedPeriod = 'open_closed_period';
    case BackdateTransactions = 'backdate_transactions';
    case EditOthersTransactions = 'edit_others_transactions';
    case DeletePostedTransactions = 'delete_posted_transactions';
    case ApproveTransactions = 'approve_transactions';
    case ExportData = 'export_data';

    public function label(): string
    {
        return match ($this) {
            self::SeeCost => __('See item cost and margins'),
            self::ChangeSellingPrice => __('Change the selling price on a document'),
            self::SeeCreditData => __('See customer credit data'),
            self::OverrideCreditLimit => __('Save a document over the credit limit'),
            self::OpenClosedPeriod => __('Reopen a closed period'),
            self::BackdateTransactions => __('Save a transaction dated before today'),
            self::EditOthersTransactions => "Edit other users' transactions",
            self::DeletePostedTransactions => __('Delete a posted transaction'),
            self::ApproveTransactions => __('Approve transactions'),
            self::ExportData => __('Export lists and reports'),
        };
    }
}
